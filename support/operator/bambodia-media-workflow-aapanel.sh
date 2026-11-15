#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

REPO=bambodia168-sys/bambodialaravel
RELEASE=90dee2a478fd8fb84946536fb736f98c13eba1fe
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

reconcile_media() {
    local path="$1" incoming="$WORK/$1" installed
    test ! -L "$path"
    test -f "$path"
    test "$(realpath -e -- "$path")" = "$APP/$path"
    installed="$(installed_hash "$path")"
    mkdir -p "$(dirname -- "$incoming")"
    python3 - "$path" "$incoming" <<'PY_RECONCILE'
from pathlib import Path
import re
import sys


source, output = map(Path, sys.argv[1:])
raw = source.read_bytes()
newline = b'\r\n' if b'\r\n' in raw else b'\n'
original = raw.decode('utf-8').replace('\r\n', '\n')
text = original


def fail(reason):
    sys.exit(f'MEDIA_WORKFLOW_RECONCILE_CONFLICT={source} {reason}')


def remove_block(pattern, value='', required=False):
    global text
    text, count = re.subn(pattern, value, text, flags=re.M | re.S)
    if count > 1 or (required and count != 1):
        fail('unexpected number of matches for a media form or validation block')


if source.name == 'MediaLibraryController.php':
    for signature in (
        'class MediaLibraryController extends Controller',
        'public function store(Request $request)',
        'public function update(Request $request, MediaAsset $asset)',
        'public function destroy(Request $request, MediaAsset $asset)',
        'private function validateMetadata(',
    ):
        if text.count(signature) != 1:
            fail('unexpected controller structure')

    remove_block(r"^[ \t]+\$request->validate\(\[\n[ \t]+'rights_confirmed'[^\n]*\n[ \t]+'rights_basis'[^\n]*\n[ \t]*\]\);\n")
    text = re.sub(r"(?m)^[ \t]+'rights_(?:basis|holder|note)'\s*=>\s*\[[^\n]*\n", '', text)
    text = re.sub(r"(?m)^[ \t]+\$data\['rights_(?:basis|holder|note)'\][^\n]*\n", '', text)
    text = re.sub(r"(?m)^[ \t]+'rights_(?:basis|holder|note)'\s*=>[^\n]*\n", '', text)
    remove_block(
        r"^(?P<indent>[ \t]+)(?P<setting>'review_status'\s*=>\s*'pending',)[ \t]*$",
        r"\g<indent>'rights_basis' => 'unspecified',\n\g<indent>'rights_holder' => '',\n\g<indent>\g<setting>",
        required=True,
    )
    if any(line.strip() not in {"'rights_basis' => 'unspecified',", "'rights_holder' => '',"}
           for line in text.splitlines() if re.search(r'rights_(?:basis|holder|note|confirmed)', line)):
        fail('an unrecognized media rights reference remains in the controller')

    def localize(match):
        parameters = match[1]
        for name in ('studio.media.*', 'account.media.*', 'dashboard.media.*', 'builder.media.store', 'dashboard.posts.media.store'):
            if f"'{name}'" in parameters and f"'localized.*.{name}'" not in parameters:
                parameters += f", 'localized.*.{name}'"
        return '->routeIs(' + parameters + ')'

    text = re.sub(r'->routeIs\(([^\n()]*)\)', localize, text)
else:
    if '@section(' not in text:
        fail('unexpected view structure')
    if source.as_posix().endswith('views/builder/index.blade.php'):
        remove_block(r'^        <div class="studio-form-grid">\n          <div class="studio-field">\n            <label for="studio-upload-rights">[^\n]*\n.*?(?=^        <p class="hint" role="status" data-builder-upload-status)')
        text = text.replace('Files uploaded here must pass rights review before they can be selected.', 'Files uploaded here are available to select immediately.')
        text = text.replace('Media ownership and usage rights', 'Preview images and screenshots')
        text = text.replace('each is reviewed individually and stored in your Media Library', 'each is stored in your Media Library')
    elif source.as_posix().endswith('views/media/edit.blade.php'):
        remove_block(r'^[ \t]*<details[^\n]*rights_basis[^\n]*\n.*?^[ \t]*</details>[ \t]*\n')
    elif source.as_posix().endswith('views/media/index.blade.php'):
        remove_block(r'^[ \t]*<details><summary>Advanced Settings</summary><label for="basis-[^\n]*\n.*?^[ \t]*</details>(?=<div class="dialog-actions">)', '                  ')
        remove_block(r'^[ \t]*<label for="basis-\{\{ \$asset->id \}\}">[^\n]*\n[ \t]*<select[^\n]*rights_basis[^\n]*\n.*?^[ \t]*</select>[ \t]*\n')
        if '<th>Rights</th>' in text:
            text = text.replace('<th>Rights</th>', '').replace('$studio ? 6 : 7', '$studio ? 5 : 6')
        text = text.replace('Rights-aware media management', 'Manage media')
        text = text.replace(
            'Uploading media does not publish it. You can use uploads immediately. Only publish media you own or are licensed to use.',
            'You can attach uploads immediately. Media remains private until the content is published.',
        )
        text = '\n'.join(line for line in text.split('\n') if not re.search(
            r'<td>\{\{ ucfirst\(\$asset->rights_basis\)|<p><strong>Rights holder:|@if\(\$asset->rights_note\)|<label for="(?:holder-|rights-note-|preview-basis|preview-holder|preview-note)', line))
    elif source.as_posix().endswith('views/media/upload.blade.php'):
        text = '\n'.join(line for line in text.split('\n') if not re.search(
            r'<label for="upload-(?:basis|holder|note)"', line))
    elif source.as_posix().endswith('views/dashboard/post-builder.blade.php'):
        remove_block(r'^[ \t]*<label class="post-featured-rights"[^\n]*</label>[ \t]*\n')
        text = re.sub(r' data-rights-holder="[^"\n]*"', '', text)
        text = re.sub(r'\bdata-post-media(?=[\s>])', 'data-editorial-post-media', text)
        if text.count('data-editorial-post-media') != 1:
            fail('unexpected Post Builder media panel')
        if 'js/post-builder-media.js' not in text:
            remove_block(r"^@section\('content'\)[ \t]*$",
                         "@section('content')\n<script defer src=\"{{ asset('js/post-builder-media.js') }}\"></script>",
                         required=True)
        if 'data-post-featured-rights' in text or 'data-rights-holder' in text:
            fail('an unrecognized Post Builder rights field remains')
    else:
        fail('no reconciliation defined for this file')
    if re.search(r'rights_(?:basis|holder|note|confirmed)|studio-upload-(?:rights|holder|note)', text):
        fail('an unrecognized media rights field remains in the view')

output.write_bytes(text.encode('utf-8').replace(b'\n', newline))
PY_RECONCILE
    if cmp -s -- "$path" "$incoming"; then
        return
    fi
    "$PHP" -l "$incoming"
    ORIGINAL_HASH["$path"]="$installed"
    STAGED+=("$path")
}

stage 'app/Http/Controllers/Dashboard/UserOwnershipTransferController.php' 'd2abb238ad56a501dc870e8d9e67e2b998bc3a4d' 'ff0c4cbcd5294eb76de70d16ec2c307d4cc0dfb0'
if [[ "$(installed_hash 'app/Support/SupportKnowledge.php')" == 'e46d4793208ab960344ea907c333ec123169d70b' ]]; then
    stage 'app/Support/SupportKnowledge.php' 'e46d4793208ab960344ea907c333ec123169d70b' '4c2c75c134fe41aaaec1577c779e666b4e1f5250'
fi
stage 'public/js/post-builder-media.js' 'absent' 'ca1fdf4db5ba845b9512eb3b88c72344ca36c875'
reconcile_media 'resources/views/dashboard/post-builder.blade.php'
stage 'resources/views/dashboard/user-transfer.blade.php' 'fbc23a84e14c23c5fbebb98bb2c4aa97846c261a' 'bb0243d2e151001e7c0cdb030eb9cfd48dc92ca1'
reconcile_media 'resources/views/media/index.blade.php'
if [[ -e 'resources/views/media/upload.blade.php' || -L 'resources/views/media/upload.blade.php' ]]; then
    reconcile_media 'resources/views/media/upload.blade.php'
fi
if [[ -e 'resources/views/media/edit.blade.php' || -L 'resources/views/media/edit.blade.php' ]]; then
    reconcile_media 'resources/views/media/edit.blade.php'
fi
reconcile_media 'resources/views/builder/index.blade.php'
reconcile_media 'app/Http/Controllers/MediaLibraryController.php'

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
