#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APP=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
DOMAIN=bambodia.com
REPO=https://github.com/bambodia168-sys/bambodialaravel.git
BASE=319c70eb4d3c591351b89a725072588223c5c7d2
ERROR_BASE=942d8b960ae33c5c5063c0c933f5e89bb7629320
SCRIPT=support/operator/bambodia-builder-auth-errors-aapanel.sh
RELEASE="${1:-}"
PHP_FILES=(app/Http/Controllers/Auth/AuthController.php bootstrap/app.php)
SEO=resources/views/layouts/partials/site-seo-head.blade.php
CSS=public/css/error-404.css
ERR=resources/views/errors
VIEWS=(
    "$ERR/status-mark.blade.php" "$ERR/client.blade.php" "$ERR/server.blade.php"
    "$ERR/401.blade.php" "$ERR/402.blade.php" "$ERR/403.blade.php" "$ERR/404.blade.php"
    "$ERR/419.blade.php" "$ERR/429.blade.php" "$ERR/4xx.blade.php"
    "$ERR/500.blade.php" "$ERR/503.blade.php" "$ERR/5xx.blade.php"
)
FILES=("$CSS" "$SEO" "${VIEWS[@]}" "${PHP_FILES[@]}")

fail() { printf 'BAMBODIA_BUILDER_ERRORS=BLOCKED %s\n' "$*" >&2; exit 1; }
[[ $# -eq 1 && "$RELEASE" =~ ^[0-9a-f]{40}$ ]] || fail 'Supply the tested release commit.'
[[ "$(id -u)" == 0 ]] || fail 'Run on the Bambodia aaPanel host as root.'
[[ "$(realpath -e -- "$APP")" == "$APP" && -r "$APP/vendor/autoload.php" && -x "$PHP" ]] ||
    fail 'Bambodia app or PHP runtime unavailable.'

exec 9>/run/lock/bambodia-marketplace-transfer.lock
flock -n 9 || fail 'Another Bambodia upgrade holds the lock.'
WORK="$(mktemp -d /root/bambodia-builder-errors.XXXXXXXX)"
BACKUP=
TEMP=
PASS=0
declare -A ORIGINAL=()
declare -a CHANGED=() INSTALL=()

artisan() { (cd "$APP" && runuser -u www -- "$PHP" artisan "$@" --no-interaction); }
file_hash() {
    if [[ -f "$1" && ! -L "$1" ]]; then
        git hash-object -- "$1"
    elif [[ ! -e "$1" && ! -L "$1" ]]; then
        printf 'absent\n'
    else
        return 1
    fi
}
cleanup() {
    local rc=$? path
    trap - EXIT
    if [[ -n "$TEMP" ]]; then rm -f -- "$TEMP"; fi
    if (( ! PASS && ${#CHANGED[@]} )); then
        for path in "${CHANGED[@]}"; do
            if [[ "${ORIGINAL[$path]}" == absent ]]; then
                rm -f -- "$APP/$path" || printf 'RESTORE_FAILED=%s\n' "$path" >&2
            else
                cp -aT -- "$BACKUP/$path" "$APP/$path" || printf 'RESTORE_FAILED=%s\n' "$path" >&2
            fi
        done
        artisan view:clear >/dev/null || printf 'VIEW_CACHE_RESTORE_FAILED\n' >&2
    fi
    rm -rf -- "$WORK"
    exit "$rc"
}
trap cleanup EXIT

git init -q --bare "$WORK/repo"
git -C "$WORK/repo" fetch -q --no-tags --depth=2 "$REPO" "$RELEASE"
git -C "$WORK/repo" fetch -q --no-tags --depth=1 "$REPO" "$ERROR_BASE"
revision() { git -C "$WORK/repo" rev-parse "$1"; }
[[ "$(revision "$RELEASE^")" == "$BASE" ]] || fail 'Unexpected release parent.'
[[ "$(git hash-object -- "$0")" == "$(revision "$RELEASE:$SCRIPT")" ]] || fail 'Installer does not match release.'
mkdir -- "$WORK/stage"
for path in "${FILES[@]}"; do
    [[ "$(realpath -m -- "$APP/$path")" == "$APP/$path" ]] || fail "Unexpected path at $path."
    ORIGINAL[$path]="$(file_hash "$APP/$path")" || fail "Unexpected file type at $path."
    mkdir -p -- "$WORK/stage/$(dirname -- "$path")"
done

for path in "$CSS" "$SEO" "${VIEWS[@]}"; do
    current="${ORIGINAL[$path]}"
    target="$(revision "$RELEASE:$path")"
    if [[ "$current" == "$target" ]]; then continue; fi
    if [[ "$current" != absent ]]; then
        previous="$(git -C "$WORK/repo" rev-parse --verify "$ERROR_BASE:$path" 2>/dev/null || true)"
        original="$(git -C "$WORK/repo" rev-parse --verify "$BASE:$path" 2>/dev/null || true)"
        [[ "$current" == "$previous" || "$current" == "$original" ]] ||
            fail "Installed error-page source differs at $path."
    fi
    git -C "$WORK/repo" show "$RELEASE:$path" > "$WORK/stage/$path"
    INSTALL+=("$path")
done

for path in "${PHP_FILES[@]}"; do
    [[ "${ORIGINAL[$path]}" != absent ]] || fail "Missing $path."
    cp -- "$APP/$path" "$WORK/stage/$path"
    git -C "$WORK/repo" diff --no-ext-diff -U2 "$BASE" "$RELEASE" -- "$path" > "$WORK/source.patch"
    test -s "$WORK/source.patch"
    if git -C "$WORK/stage" apply --reverse --check "$WORK/source.patch" 2>/dev/null; then continue; fi
    git -C "$WORK/stage" apply --check "$WORK/source.patch" || fail "PHP baseline differs at $path."
    git -C "$WORK/stage" apply "$WORK/source.patch"
    "$PHP" -l "$WORK/stage/$path"
    INSTALL+=("$path")
done

if (( ${#INSTALL[@]} )); then
    BACKUP="$(mktemp -d /root/bambodia-builder-errors-backup.XXXXXXXX)"
    printf 'BACKUP=%s\n' "$BACKUP"
    for path in "${INSTALL[@]}"; do
        [[ "$(file_hash "$APP/$path")" == "${ORIGINAL[$path]}" ]] || fail "File changed during staging at $path."
        if [[ "${ORIGINAL[$path]}" != absent ]]; then
            (cd "$APP" && cp -a --parents -- "$path" "$BACKUP/")
        fi
    done
    for path in "${INSTALL[@]}"; do
        mkdir -p -m 0755 -- "$APP/$(dirname -- "$path")"
        TEMP="$(mktemp "$APP/$(dirname -- "$path")/.builder-errors.XXXXXXXX")"
        cat -- "$WORK/stage/$path" > "$TEMP"
        if [[ "${ORIGINAL[$path]}" == absent ]]; then
            chmod 0644 -- "$TEMP"
        else
            chown --reference="$APP/$path" -- "$TEMP"
            chmod --reference="$APP/$path" -- "$TEMP"
        fi
        [[ "$(file_hash "$APP/$path")" == "${ORIGINAL[$path]}" ]] || fail "File changed before installing $path."
        CHANGED+=("$path")
        mv -fT -- "$TEMP" "$APP/$path"
        TEMP=
    done
    artisan view:cache
else
    printf 'BAMBODIA_BUILDER_ERRORS=ALREADY_INSTALLED\n'
fi

origin() { curl -sS --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" "$@"; }
EDITOR="https://$DOMAIN/builder?type=service&item=441696845&mode=manage"
REDIRECT="$(origin --output /dev/null --write-out '%{http_code} %{redirect_url}' "$EDITOR")"
[[ "$REDIRECT" == "302 https://$DOMAIN/login" ]] || fail 'The guest Builder editor did not redirect to login.'
JSON="$(origin -H 'Accept: application/json' --output /dev/null --write-out '%{http_code} %{content_type}' "$EDITOR")"
[[ "$JSON" == '401 application/json'* ]] || fail 'The Builder JSON request did not return a JSON 401.'
[[ "$(origin --output "$WORK/page" --write-out '%{http_code}' "https://$DOMAIN/bambodia-error-layout-check")" == 404 ]] &&
    grep -Fq 'class="site-not-found-mark"' "$WORK/page" || fail 'The HTML 404 was not branded.'
[[ "$(origin --output "$WORK/page" --write-out '%{http_code}' "https://$DOMAIN/builder/content/preview")" == 405 ]] &&
    grep -Fq 'aria-label="405"' "$WORK/page" || fail 'The HTML 405 was not branded.'
origin -f -o "$WORK/css" "https://$DOMAIN/${CSS#public/}?v=2"
cmp -s -- "$APP/$CSS" "$WORK/css" || fail 'The live stylesheet did not match.'
PASS=1
printf 'BAMBODIA_BUILDER_ERRORS=PASS\n'
