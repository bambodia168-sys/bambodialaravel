#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REPO=bambodia168-sys/bambodialaravel
RELEASE=8e2ada302f85b7796a203408a9e784e53998029d
APP=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
DOMAIN=bambodia.com

test "$(id -u)" -eq 0
test "$(realpath -e -- "$APP")" = "$APP"
cd "$APP"
test -f artisan
test -r vendor/autoload.php
test -x "$PHP"

exec 9>/run/lock/bambodia-marketplace-transfer.lock
flock -n 9
WORK="$(mktemp -d /root/bambodia-marketplace-transfer.XXXXXXXX)"
BACKUP=
PENDING=
COMPLETE=0
declare -A ORIGINAL_HASH=()
declare -a STAGED=() CHANGED=()

artisan() {
    runuser -u www -- "$PHP" artisan "$@" --no-interaction
}

cleanup() {
    local result=$?
    trap - EXIT
    if [[ -n "$PENDING" ]]; then
        rm -f -- "$PENDING"
    fi
    if (( ! COMPLETE && ${#CHANGED[@]} > 0 )); then
        local path
        for path in "${CHANGED[@]}"; do
            cp -aT -- "$BACKUP/$path" "$path" || echo "RESTORE_FAILED=$path" >&2
        done
        artisan view:clear >/dev/null || echo VIEW_CACHE_RESTORE_FAILED >&2
    fi
    rm -rf -- "$WORK"
    exit "$result"
}
trap cleanup EXIT

stage() {
    local path="$1" before="$2" after="$3" incoming="$WORK/$1" installed
    test ! -L "$path"
    test -f "$path"
    test "$(realpath -e -- "$path")" = "$APP/$path"
    installed="$(git hash-object -- "$path")"
    if [[ "$installed" == "$after" ]]; then
        return
    fi
    if [[ "$installed" != "$before" ]]; then
        printf 'MARKETPLACE_TRANSFER_SOURCE_CONFLICT=%s %s\n' "$path" "$installed" >&2
        return 1
    fi
    mkdir -p "$(dirname -- "$incoming")"
    gh api -H 'Accept: application/vnd.github.raw+json' "repos/$REPO/contents/$path?ref=$RELEASE" > "$incoming"
    test "$(git hash-object -- "$incoming")" = "$after"
    "$PHP" -l "$incoming"
    ORIGINAL_HASH["$path"]="$installed"
    STAGED+=("$path")
}

stage 'app/Http/Controllers/Dashboard/UserOwnershipTransferController.php' '0d0b1c3e8fe4aee52980140496069814b81b5e55' '9358a0b856e6fd3f602a5020455e282d65c74f72'
stage 'resources/views/dashboard/user-transfer.blade.php' 'ebc5e465bd1c6489ac7d2bb88cea94c6ffe1b3ce' 'e3d58f6382c2d8ec8e80dbe24d15717312c0c352'

if (( ${#STAGED[@]} > 0 )); then
    BACKUP="$(mktemp -d /root/bambodia-marketplace-transfer-backup.XXXXXXXX)"
    printf 'BACKUP=%s\n' "$BACKUP"
    for FILE in "${STAGED[@]}"; do
        test "$(git hash-object -- "$FILE")" = "${ORIGINAL_HASH[$FILE]}"
        cp -a --parents -- "$FILE" "$BACKUP/"
    done
    for FILE in "${STAGED[@]}"; do
        PENDING="$(mktemp "$(dirname -- "$FILE")/.marketplace-transfer.XXXXXXXX")"
        cat -- "$WORK/$FILE" > "$PENDING"
        chown --reference="$FILE" -- "$PENDING"
        chmod --reference="$FILE" -- "$PENDING"
        test "$(git hash-object -- "$FILE")" = "${ORIGINAL_HASH[$FILE]}"
        CHANGED+=("$FILE")
        mv -fT -- "$PENDING" "$FILE"
        PENDING=
    done
    artisan view:clear
fi

artisan route:list --path=marketplace-owners --json > "$WORK/routes.json"
python3 - "$WORK/routes.json" <<'PY'
import json
import sys

with open(sys.argv[1]) as payload:
    routes = {route['name']: route for route in json.load(payload)}
for name, method, action in (
    ('dashboard.marketplace.transfer', 'GET', 'create'),
    ('dashboard.marketplace.transfer.store', 'POST', 'store'),
):
    route = routes[name]
    assert method in route['method'].split('|')
    assert route['action'].endswith(f'UserOwnershipTransferController@{action}')
    middleware = route['middleware']
    if isinstance(middleware, list):
        middleware = ' '.join(middleware)
    assert 'auth' in middleware.lower() and ':marketplace_transfer' in middleware
print('MARKETPLACE_TRANSFER_ROUTES=PASS')
PY

http_status() {
    curl -ksS --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" \
        --output /dev/null --write-out '%{http_code}' "https://$DOMAIN$1"
}

test "$(http_status /)" = 200
test "$(http_status /dashboard/marketplace-owners/1/transfer)" = 302
COMPLETE=1
printf 'BAMBODIA_MARKETPLACE_TRANSFER=PASS\n'
