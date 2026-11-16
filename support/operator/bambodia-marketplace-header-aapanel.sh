#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

repo=bambodia168-sys/bambodialaravel
release=64091812f0ba85e9f1f46a15b2bfc528b1d2b808
app=/www/wwwroot/bambodia-staging-qa
php=/www/server/php/85/bin/php
path=resources/views/dashboard/partials/marketplace-manager.blade.php
previous=65323b334a39230683d3716f922ef395f4f8d9c0
target=0a3b6ceed24d66968f9bdf22f6f2780137e2875c

test "$(id -u)" -eq 0
test "$(realpath -e -- "$app")" = "$app"
cd "$app"
test -f artisan
test -x "$php"
test -f "$path" && test ! -L "$path"
test "$(realpath -e -- "$path")" = "$app/$path"

exec 9>/run/lock/bambodia-marketplace-transfer.lock
flock -n 9

current="$(git hash-object -- "$path")"
backup=
staged=
changed=0
complete=0

artisan() {
    runuser -u www -- "$php" artisan "$@" --no-interaction
}

cleanup() {
    local result=$?
    trap - EXIT
    if (( changed && ! complete )); then
        cp -aT -- "$backup/marketplace-manager.blade.php" "$path" || echo MARKETPLACE_HEADER_RESTORE_FAILED >&2
        artisan view:clear >/dev/null || echo MARKETPLACE_HEADER_VIEW_CACHE_RESTORE_FAILED >&2
    fi
    if [[ -n "$staged" ]]; then
        rm -f -- "$staged"
    fi
    exit "$result"
}
trap cleanup EXIT

if [[ "$current" != "$target" ]]; then
    if [[ "$current" != "$previous" ]]; then
        printf 'MARKETPLACE_HEADER_SOURCE_CONFLICT=%s %s\n' "$path" "$current" >&2
        exit 1
    fi
    staged="$(mktemp "$(dirname -- "$path")/.marketplace-header.XXXXXXXX")"
    gh api -H 'Accept: application/vnd.github.raw+json' "repos/$repo/contents/$path?ref=$release" > "$staged"
    test "$(git hash-object -- "$staged")" = "$target"
    "$php" -l "$staged"

    backup="$(mktemp -d /root/bambodia-marketplace-header-backup.XXXXXXXX)"
    cp -a -- "$path" "$backup/marketplace-manager.blade.php"
    printf 'BACKUP=%s\n' "$backup"
    chown --reference="$path" -- "$staged"
    chmod --reference="$path" -- "$staged"
    test "$(git hash-object -- "$path")" = "$current"
    changed=1
    mv -fT -- "$staged" "$path"
    staged=
    artisan view:clear
fi

test "$(git hash-object -- "$path")" = "$target"
test "$(curl -ksS --max-time 15 --resolve bambodia.com:443:127.0.0.1 \
    --output /dev/null --write-out '%{http_code}' https://bambodia.com/)" = 200
complete=1
echo BAMBODIA_MARKETPLACE_HEADER=PASS
