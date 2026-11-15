#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REPO=bambodia168-sys/bambodialaravel
RELEASE=9cc918420da7083e6ea4a68beb42dd70c8892e69
APP=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
CONTROLLER=app/Http/Controllers/LicenseController.php
VIEW=resources/views/licenses/show.blade.php
CSS=public/css/license-manager.css

test "$(id -u)" -eq 0
cd "$APP"
test -f artisan
test -r vendor/autoload.php
test -x "$PHP"

WORK="$(mktemp -d /root/bambodia-license-directory.XXXXXXXX)"
BACKUP=
CHANGED=()
PENDING=
COMPLETED=0

cleanup() {
    local result=$?
    trap - EXIT
    if [[ -n "$PENDING" ]]; then
        rm -f -- "$PENDING"
    fi
    if (( ! COMPLETED && ${#CHANGED[@]} > 0 )); then
        local path
        for path in "${CHANGED[@]}"; do
            cp -aT -- "$BACKUP/$path" "$path" || echo "RESTORE_FAILED=$path" >&2
        done
        runuser -u www -- "$PHP" artisan view:clear >/dev/null || echo 'VIEW_CACHE_CLEAR_FAILED' >&2
    fi
    rm -rf -- "$WORK"
    exit "$result"
}
trap cleanup EXIT

stage() {
    local path="$1" before="$2" after="$3" actual=absent
    test "$(realpath -m -- "$path")" = "$APP/$path"
    test ! -L "$path"
    if [[ -e "$path" ]]; then
        test -f "$path"
        actual="$(git hash-object -- "$path")"
    fi
    if [[ "$actual" == "$after" ]]; then
        return
    fi
    if [[ "$actual" != "$before" ]]; then
        printf 'LICENSE_SOURCE_CONFLICT=%s %s\n' "$path" "$actual" >&2
        return 1
    fi
    mkdir -p "$WORK/$(dirname -- "$path")"
    gh api -H 'Accept: application/vnd.github.raw+json' "repos/$REPO/contents/$path?ref=$RELEASE" > "$WORK/$path"
    test "$(git hash-object -- "$WORK/$path")" = "$after"
    if [[ "$path" == *.php ]]; then "$PHP" -l "$WORK/$path"; fi
}

stage "$CONTROLLER" 20ae7bc521017da741b28196cb18149fc9b59796 49e1f6cea5415ec2f4808598a8e14436617dab7d
stage "$VIEW" 3c948e71668fcee9e5f9e427db11028989aeb993 36e79ca98e311e2847eb5177b2abd5af0155f559
stage "$CSS" d22cccea662eda5490c7db1f61845ab206a0fb56 1fde7117bfa7d5bb5a2700fe76b4829cf3c71ed9

BACKUP="$(mktemp -d /root/bambodia-license-directory-backup.XXXXXXXX)"
for FILE in "$CONTROLLER" "$VIEW" "$CSS"; do
    if [[ ! -f "$WORK/$FILE" ]]; then
        continue
    fi
    cp -a --parents -- "$FILE" "$BACKUP/"
    PENDING="$(mktemp "$(dirname -- "$FILE")/.license-directory.XXXXXXXX")"
    cat -- "$WORK/$FILE" > "$PENDING"
    chown --reference="$FILE" -- "$PENDING"
    chmod --reference="$FILE" -- "$PENDING"
    CHANGED+=("$FILE")
    mv -fT -- "$PENDING" "$FILE"
    PENDING=
done

runuser -u www -- "$PHP" artisan view:clear
COMPLETED=1
printf 'BACKUP=%s\nPUBLIC_LICENSE_DIRECTORY=PASS\n' "$BACKUP"
