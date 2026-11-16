#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

APP=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
DOMAIN=bambodia.com
BASE=319c70eb4d3c591351b89a725072588223c5c7d2
REPO=https://github.com/bambodia168-sys/bambodialaravel.git
SCRIPT=support/operator/bambodia-supplemental-terms-aapanel.sh
RELEASE="${1:-}"
CSS=public/css/builder-supplemental-terms.css
FILES=(
    app/Support/BuilderSupplementalTerms.php
    lang/en/builder_terms.php
    lang/km/builder_terms.php
    lang/zh/builder_terms.php
    "$CSS"
    resources/views/builder/partials/supplemental-terms.blade.php
    app/Http/Controllers/BuilderController.php
    app/Support/BuilderSubmissionTerms.php
    resources/views/builder/index.blade.php
)

fail() { echo "BAMBODIA_SUPPLEMENTAL_TERMS=BLOCKED $*" >&2; exit 1; }
[[ $# -eq 1 && "$RELEASE" =~ ^[0-9a-f]{40}$ ]] || fail 'Supply the tested release commit.'
[[ "$(id -u)" == 0 ]] || fail 'Run on the aaPanel host as root.'
[[ "$(realpath -e -- "$APP")" == "$APP" && -r "$APP/vendor/autoload.php" && -x "$PHP" ]] || fail 'App or PHP runtime unavailable.'

exec 9>/run/lock/bambodia-marketplace-transfer.lock
flock -n 9 || fail 'Another Bambodia upgrade holds the lock.'
WORK="$(mktemp -d /root/bambodia-supplemental-terms.XXXXXXXX)"
BACKUP=
PENDING=
COMPLETE=0
declare -A ORIGINAL=()
declare -a CHANGED=()

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
    local result=$? path
    trap - EXIT
    if [[ -n "$PENDING" ]]; then rm -f -- "$PENDING"; fi
    if (( ! COMPLETE && ${#CHANGED[@]} > 0 )); then
        for path in "${CHANGED[@]}"; do
            if [[ "${ORIGINAL[$path]}" == absent ]]; then
                rm -f -- "$APP/$path" || echo "RESTORE_FAILED=$path" >&2
            else
                cp -aT -- "$BACKUP/$path" "$APP/$path" || echo "RESTORE_FAILED=$path" >&2
            fi
        done
        artisan view:clear >/dev/null || echo CACHE_RESTORE_FAILED >&2
    fi
    rm -rf -- "$WORK"
    exit "$result"
}
trap cleanup EXIT

git init -q --bare "$WORK/release"
git -C "$WORK/release" fetch -q --no-tags --depth=2 "$REPO" "$RELEASE"
[[ "$(git -C "$WORK/release" rev-parse FETCH_HEAD)" == "$RELEASE" ]] || fail 'Release SHA mismatch.'
[[ "$(git -C "$WORK/release" rev-parse "$RELEASE^")" == "$BASE" ]] || fail 'Unexpected release parent.'
[[ "$(git hash-object -- "$0")" == "$(git -C "$WORK/release" rev-parse "$RELEASE:$SCRIPT")" ]] || fail 'Installer does not match the release.'
git -C "$WORK/release" diff --no-ext-diff -U2 "$BASE" "$RELEASE" -- "${FILES[@]}" > "$WORK/changes.patch"
test -s "$WORK/changes.patch"

mkdir -- "$WORK/stage"
for path in "${FILES[@]}"; do
    ORIGINAL[$path]="$(file_hash "$APP/$path")" || fail "Unexpected file type at $path."
    if [[ "${ORIGINAL[$path]}" != absent ]]; then
        (cd "$APP" && cp -a --parents -- "$path" "$WORK/stage/")
    fi
done

if git -C "$WORK/stage" apply --reverse --check "$WORK/changes.patch" 2>/dev/null; then
    echo 'BAMBODIA_SUPPLEMENTAL_TERMS=ALREADY_INSTALLED'
else
    git -C "$WORK/stage" apply --check "$WORK/changes.patch" || fail 'Installed sources differ from the supported Builder layout.'
    git -C "$WORK/stage" apply "$WORK/changes.patch"
    BACKUP="$(mktemp -d /root/bambodia-supplemental-terms-backup.XXXXXXXX)"
    printf 'BACKUP=%s\n' "$BACKUP"
    for path in "${FILES[@]}"; do
        if [[ "$path" == *.php && "$path" != *.blade.php ]]; then "$PHP" -l "$WORK/stage/$path"; fi
        [[ "$(file_hash "$APP/$path")" == "${ORIGINAL[$path]}" ]] || fail "File changed during staging: $path."
        if [[ "${ORIGINAL[$path]}" != absent ]]; then
            (cd "$APP" && cp -a --parents -- "$path" "$BACKUP/")
        fi
    done
    for path in "${FILES[@]}"; do
        mkdir -p -m 0755 -- "$APP/$(dirname -- "$path")"
        PENDING="$(mktemp "$APP/$(dirname -- "$path")/.supplemental-terms.XXXXXXXX")"
        cat -- "$WORK/stage/$path" > "$PENDING"
        if [[ "${ORIGINAL[$path]}" == absent ]]; then
            chmod 0644 -- "$PENDING"
        else
            chown --reference="$APP/$path" -- "$PENDING"
            chmod --reference="$APP/$path" -- "$PENDING"
        fi
        [[ "$(file_hash "$APP/$path")" == "${ORIGINAL[$path]}" ]] || fail "File changed before installation: $path."
        CHANGED+=("$path")
        mv -fT -- "$PENDING" "$APP/$path"
        PENDING=
    done
    artisan view:cache
fi

curl -fsS --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" -o /dev/null "https://$DOMAIN/"
curl -fsS --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" -o "$WORK/stylesheet" "https://$DOMAIN/${CSS#public/}?v=1"
cmp -s -- "$APP/$CSS" "$WORK/stylesheet" || fail 'Live stylesheet does not match the installed release.'
COMPLETE=1
printf 'BAMBODIA_SUPPLEMENTAL_TERMS=PASS\n'
