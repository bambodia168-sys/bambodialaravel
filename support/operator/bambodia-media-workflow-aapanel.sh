#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REPO=bambodia168-sys/bambodialaravel
RELEASE=90dee2a478fd8fb84946536fb736f98c13eba1fe
APP=/www/wwwroot/bambodia-staging-qa
PHP=/www/server/php/85/bin/php
DOMAIN=bambodia.com
MEDIA_OVERLAY=68bca195512af4a0c7a08e2e7f15a4615d011a5c

test "$(id -u)" -eq 0
test "$(realpath -e -- "$APP")" = "$APP"
cd "$APP"
test -f artisan
test -r vendor/autoload.php
test -x "$PHP"

exec 9>/run/lock/bambodia-marketplace-transfer.lock
flock -n 9
WORK="$(mktemp -d /root/bambodia-media-workflow.XXXXXXXX)"
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
            if [[ "${ORIGINAL_HASH[$path]}" == 'absent' ]]; then
                rm -f -- "$path"
            else
                cp -aT -- "$BACKUP/$path" "$path" || echo "RESTORE_FAILED=$path" >&2
            fi
        done
        artisan view:clear >/dev/null || echo VIEW_CACHE_RESTORE_FAILED >&2
    fi
    rm -rf -- "$WORK"
    exit "$result"
}
trap cleanup EXIT

installed_hash() {
    if [[ -f "$1" && ! -L "$1" ]]; then
        git hash-object -- "$1"
    else
        test ! -e "$1" && test ! -L "$1" && printf 'absent\n'
    fi
}

stage() {
    local path="$1" before="$2" after="$3" release="${4:-$RELEASE}" incoming="$WORK/$1" installed
    test ! -L "$path"
    test "$(realpath -e -- "$(dirname -- "$path")")/$(basename -- "$path")" = "$APP/$path"
    installed="$(installed_hash "$path")"
    if [[ "$installed" == "$after" ]]; then
        return
    fi
    if [[ "$installed" != "$before" ]]; then
        printf 'MEDIA_WORKFLOW_SOURCE_CONFLICT=%s %s\n' "$path" "$installed" >&2
        return 1
    fi
    mkdir -p "$(dirname -- "$incoming")"
    gh api -H 'Accept: application/vnd.github.raw+json' "repos/$REPO/contents/$path?ref=$release" > "$incoming"
    test "$(git hash-object -- "$incoming")" = "$after"
    if [[ "$path" == *.php ]]; then "$PHP" -l "$incoming"; fi
    ORIGINAL_HASH["$path"]="$installed"
    STAGED+=("$path")
}

stage 'app/Http/Controllers/Dashboard/UserOwnershipTransferController.php' 'd2abb238ad56a501dc870e8d9e67e2b998bc3a4d' 'ff0c4cbcd5294eb76de70d16ec2c307d4cc0dfb0'
if [[ "$(installed_hash 'app/Http/Controllers/MediaLibraryController.php')" == '65b10fc1af39cff267206622bebb06581acf3377' || "$(installed_hash 'app/Http/Controllers/MediaLibraryController.php')" == '81607ce62a3f092b068ddcf1734e85eb51739ead' ]]; then
    stage 'app/Http/Controllers/MediaLibraryController.php' '65b10fc1af39cff267206622bebb06581acf3377' '81607ce62a3f092b068ddcf1734e85eb51739ead' "$MEDIA_OVERLAY"
else
    stage 'app/Http/Controllers/MediaLibraryController.php' '4cbac91f4d2f8fecb92ff0a8c1a1e07d3d3dee8b' '7a8ca27758492153a4bbfc60962abc84a2478059'
fi
stage 'app/Support/SupportKnowledge.php' 'e46d4793208ab960344ea907c333ec123169d70b' '4c2c75c134fe41aaaec1577c779e666b4e1f5250'
stage 'public/js/post-builder-media.js' 'absent' 'ca1fdf4db5ba845b9512eb3b88c72344ca36c875'
if [[ "$(installed_hash 'resources/views/builder/index.blade.php')" == '3b33f817c312ac2be4143b13af811d16adbaadd3' || "$(installed_hash 'resources/views/builder/index.blade.php')" == '6fd1a0c72b866a216c5e320e95dc7087ccf00669' ]]; then
    stage 'resources/views/builder/index.blade.php' '3b33f817c312ac2be4143b13af811d16adbaadd3' '6fd1a0c72b866a216c5e320e95dc7087ccf00669' "$MEDIA_OVERLAY"
else
    stage 'resources/views/builder/index.blade.php' 'ca6bf37fd943856abcc1f7f19aa97b1a930103a3' 'dca1e5f45cdfe4b033c02f5ba211b8655df21db3'
fi
stage 'resources/views/dashboard/post-builder.blade.php' '1b09e910a97cde2fbfa26eaf12a414241c56c954' '8c9b3a5785fe084f87ccde4ec23192e5d072065c'
stage 'resources/views/dashboard/user-transfer.blade.php' 'fbc23a84e14c23c5fbebb98bb2c4aa97846c261a' 'bb0243d2e151001e7c0cdb030eb9cfd48dc92ca1'
if [[ "$(installed_hash 'resources/views/media/index.blade.php')" == 'ad00ac045b849f6b10e9563c94b34219c099aec5' || "$(installed_hash 'resources/views/media/index.blade.php')" == '81dce7e3c5edcd4b7c77c645cac04f585de0866d' ]]; then
    stage 'resources/views/media/index.blade.php' 'ad00ac045b849f6b10e9563c94b34219c099aec5' '81dce7e3c5edcd4b7c77c645cac04f585de0866d' "$MEDIA_OVERLAY"
else
    stage 'resources/views/media/index.blade.php' '09d8a0acf224a3eb168c7a2d38bb66f2fea05987' '0aa20b4b6f98e45218f81b96fca59e09f3ba6a82'
fi

if [[ -e 'resources/views/media/edit.blade.php' || -L 'resources/views/media/edit.blade.php' ]]; then
    stage 'resources/views/media/edit.blade.php' 'd957fbcc73826b7ed4906824156739976a31cff9' '9cf6a1191051d3cddebe989e701f19cfa49d90c9' "$MEDIA_OVERLAY"
fi

if [[ -e 'resources/views/media/upload.blade.php' || -L 'resources/views/media/upload.blade.php' ]]; then
    stage 'resources/views/media/upload.blade.php' '34285881078aeb7ff2474677cd479a67eca4d7c8' 'cc99158c17ffca7c6ed39fcabddc0a6362d50095' "$MEDIA_OVERLAY"
fi

if (( ${#STAGED[@]} > 0 )); then
    BACKUP="$(mktemp -d /root/bambodia-media-workflow-backup.XXXXXXXX)"
    printf 'BACKUP=%s\n' "$BACKUP"
    for FILE in "${STAGED[@]}"; do
        test "$(installed_hash "$FILE")" = "${ORIGINAL_HASH[$FILE]}"
        if [[ "${ORIGINAL_HASH[$FILE]}" != 'absent' ]]; then
            cp -a --parents -- "$FILE" "$BACKUP/"
        fi
    done
    for FILE in "${STAGED[@]}"; do
        PENDING="$(mktemp "$(dirname -- "$FILE")/.marketplace-transfer.XXXXXXXX")"
        cat -- "$WORK/$FILE" > "$PENDING"
        if [[ "${ORIGINAL_HASH[$FILE]}" == 'absent' ]]; then
            chmod 0644 -- "$PENDING"
        else
            chown --reference="$FILE" -- "$PENDING"
            chmod --reference="$FILE" -- "$PENDING"
        fi
        test "$(installed_hash "$FILE")" = "${ORIGINAL_HASH[$FILE]}"
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
test "$(http_status /js/post-builder-media.js)" = 200
test "$(http_status /dashboard/marketplace-owners/1/transfer)" = 302
COMPLETE=1
printf 'BAMBODIA_MEDIA_WORKFLOW=PASS\n'
