#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

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
WORK="$(mktemp -d /root/bambodia-featured-upload.XXXXXXXX)"
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
        artisan view:clear >/dev/null || echo CACHE_RESTORE_FAILED >&2
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

cat > "$WORK/changes.patch" <<'FEATURED_UPLOAD_PATCH'
diff --git a/app/Http/Controllers/MediaLibraryController.php b/app/Http/Controllers/MediaLibraryController.php
--- a/app/Http/Controllers/MediaLibraryController.php
+++ b/app/Http/Controllers/MediaLibraryController.php
@@ -51,5 +52,11 @@
+        $featured = $fromBuilder && $request->has('featured_type');
+        $ownerId = (int) $request->user()->id;
         if ($fromBuilder) {
-            abort_unless(in_array($request->user()->role, [
-                'freelancer', 'super_admin', 'customer', 'subscriber',
-            ], true), 403);
+            if ($featured) {
+                $ownerId = $this->featuredUploadOwner($request);
+            } else {
+                abort_unless(in_array($request->user()->role, [
+                    'freelancer', 'super_admin', 'customer', 'subscriber',
+                ], true), 403);
+            }
             $returnTo = (string) $request->input('return_to', '/builder');
@@ -57,3 +65,3 @@
         }
-        $data = $this->validateMetadata($request, true);
+        $data = $this->validateMetadata($request, true, $featured);
         $file = $request->file('image');
@@ -90,3 +98,3 @@
         };
-        $path = 'marketplace/previews/'.$request->user()->id.'/'.bin2hex(random_bytes(24)).'.'.$extension;
+        $path = 'marketplace/previews/'.$ownerId.'/'.bin2hex(random_bytes(24)).'.'.$extension;
         $storageDisk = config('filesystems.media_library_disk', 'local');
@@ -99,4 +107,5 @@
             $asset = MediaAsset::query()->create([
+                ...($featured ? ['rights_basis' => 'unspecified', 'rights_holder' => ''] : []),
                 ...$data,
-                'user_id' => $request->user()->id,
+                'user_id' => $ownerId,
                 'original_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180),
@@ -301,3 +310,27 @@
 
-    private function validateMetadata(Request $request, bool $withFile = false): array
+    private function featuredUploadOwner(Request $request): int
+    {
+        $data = $request->validate([
+            'featured_type' => ['required', Rule::in(BuilderAccess::TYPES)],
+            'featured_listing' => ['nullable', 'string', 'regex:/^[0-9]{9}$/D'],
+            'featured_managed' => ['sometimes', 'boolean'],
+        ]);
+        $actor = $request->user();
+        abort_unless($actor->account_status === 'active', 403);
+        $reference = $data['featured_listing'] ?? null;
+        if ($request->boolean('featured_managed')) {
+            abort_unless(UserManagementAccess::allows($actor, 'marketplace_review'), 403);
+            abort_if($reference === null, 422);
+            $record = MarketplaceManagement::record($data['featured_type'], $reference);
+            abort_unless(MarketplaceManagement::mayEdit($record), 409);
+
+            return MarketplaceManagement::ownerId($record);
+        }
+
+        BuilderAccess::assertEditable($actor, $data['featured_type'], $reference);
+
+        return (int) $actor->id;
+    }
+
+    private function validateMetadata(Request $request, bool $withFile = false, bool $featured = false): array
     {
diff --git a/resources/views/builder/index.blade.php b/resources/views/builder/index.blade.php
--- a/resources/views/builder/index.blade.php
+++ b/resources/views/builder/index.blade.php
@@ -411,5 +411,23 @@
               </div>
-              <div class="studio-featured-preview" data-featured-preview aria-label="Selected featured media preview">
-                <span><x-icon name="image" size="25"/> Featured media preview</span>
+              <div class="studio-featured-drop" @if($canEdit)
+                data-featured-drop data-upload-url="{{ route('builder.media.store') }}"
+                data-listing-type="{{ $type }}" data-listing-reference="{{ $record?->public_id }}"
+                data-managed="{{ $managed ? '1' : '0' }}"
+              @endif>
+                <div class="studio-featured-preview" data-featured-preview aria-label="Selected featured media preview">
+                  <span><x-icon name="image" size="25"/> Featured media preview</span>
+                </div>
+                @if($canEdit)
+                  <div class="studio-featured-drop-action" data-featured-upload-action hidden>
+                    <span aria-hidden="true">Drag &amp; Drop</span><span aria-hidden="true">|</span>
+                    <button class="studio-tool-action" type="button" data-featured-upload-button aria-label="Upload featured media">
+                      <x-icon name="upload" size="16"/> Upload
+                    </button>
+                    <input type="file" data-featured-upload-input accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" hidden>
+                  </div>
+                @endif
               </div>
+              @if($canEdit)
+                <p class="hint studio-featured-upload-status" data-featured-upload-status role="status" aria-live="polite" hidden></p>
+              @endif
               @error('featured_media_id')<small class="builder-field-error">{{ $message }}</small>@enderror
@@ -697,2 +715,6 @@
     </dialog>
+    @if($canEdit)
+      <link rel="stylesheet" href="{{ asset('css/builder-featured-upload.css') }}?v=1">
+      <script defer src="{{ asset('js/builder-featured-upload.js') }}?v=1"></script>
+    @endif
     @if($canEdit && !$managed)
FEATURED_UPLOAD_PATCH
REPO=bambodia168-sys/bambodialaravel
RELEASE=5ca39453711bc74510008472852f661aa250a2c9

stage_live() {
    local path="$1"
    test -f "$path" && test ! -L "$path"
    test "$(realpath -e -- "$path")" = "$APP/$path"
    ORIGINAL_HASH["$path"]="$(installed_hash "$path")"
    mkdir -p -- "$WORK/$(dirname -- "$path")"
    cp -- "$path" "$WORK/$path"
}

stage_asset() {
    local path="$1" expected="$2"
    test ! -L "$path"
    test "$(realpath -e -- "$(dirname -- "$path")")/$(basename -- "$path")" = "$APP/$path"
    ORIGINAL_HASH["$path"]="$(installed_hash "$path")"
    if [[ "${ORIGINAL_HASH[$path]}" != 'absent' && "${ORIGINAL_HASH[$path]}" != "$expected" ]]; then
        printf 'FEATURED_UPLOAD_ASSET_CONFLICT=%s\n' "$path" >&2
        return 1
    fi
    mkdir -p -- "$WORK/$(dirname -- "$path")"
    gh api -H 'Accept: application/vnd.github.raw+json' "repos/$REPO/contents/$path?ref=$RELEASE" > "$WORK/$path"
    test "$(git hash-object -- "$WORK/$path")" = "$expected"
}

stage_live app/Http/Controllers/MediaLibraryController.php
stage_live resources/views/builder/index.blade.php
stage_asset public/css/builder-featured-upload.css 0e47a51771174bf2b31dcc91ee88c6ea723f36a2
stage_asset public/js/builder-featured-upload.js a294567c3843d9a0852364849ac9e90c291c0e23
(
    cd "$WORK"
    if ! git apply --reverse --check changes.patch 2>/dev/null; then
        git apply --check changes.patch
        git apply changes.patch
    fi
    python3 - app/Http/Controllers/MediaLibraryController.php <<'PY_IMPORTS'
from pathlib import Path
import re
import sys

path = Path(sys.argv[1])
source = path.read_bytes()
namespace = b'namespace App\\Http\\Controllers;'
class_marker = b'\nclass MediaLibraryController extends Controller'
if source.count(namespace) != 1 or source.count(class_marker) != 1:
    raise SystemExit('FEATURED_UPLOAD_SOURCE_CONFLICT=MediaLibraryController namespace or class')
begin = source.index(namespace) + len(namespace)
end = source.index(class_marker, begin)
header = source[begin:end]
use_statements = re.findall(rb'^use[ \t]+([^;]+);[ \t]*\r?$', header, re.M)
pending = []
for symbol in (b'BuilderAccess', b'MarketplaceManagement', b'UserManagementAccess'):
    fqcn = b'App\\Support\\' + symbol
    bindings = []
    for statement in use_statements:
        parts = re.split(rb'[ \t]+as[ \t]+', statement.strip(), maxsplit=1, flags=re.I)
        short_name = parts[-1].rsplit(b'\\', 1)[-1]
        if short_name.lower() == symbol.lower():
            bindings.append(parts[0].lstrip(b'\\'))
    if not bindings:
        pending.append(b'use ' + fqcn + b';')
    elif bindings != [fqcn]:
        raise SystemExit('FEATURED_UPLOAD_SOURCE_CONFLICT=unexpected import for ' + symbol.decode())
if pending:
    newline = b'\r\n' if b'\r\n' in header else b'\n'
    first_import = re.search(rb'^use[ \t]+', header, re.M)
    if not first_import:
        raise SystemExit('FEATURED_UPLOAD_SOURCE_CONFLICT=MediaLibraryController imports missing')
    offset = begin + first_import.start()
    path.write_bytes(source[:offset] + newline.join(pending) + newline + source[offset:])
PY_IMPORTS
)
for FILE in public/css/builder-featured-upload.css public/js/builder-featured-upload.js app/Http/Controllers/MediaLibraryController.php resources/views/builder/index.blade.php; do
    if ! cmp -s -- "$FILE" "$WORK/$FILE"; then
        if [[ "$FILE" == *.php ]]; then "$PHP" -l "$WORK/$FILE"; fi
        STAGED+=("$FILE")
    fi
done

if (( ${#STAGED[@]} > 0 )); then
    BACKUP="$(mktemp -d /root/bambodia-featured-upload-backup.XXXXXXXX)"
    printf 'BACKUP=%s\n' "$BACKUP"
    for FILE in "${STAGED[@]}"; do
        test "$(installed_hash "$FILE")" = "${ORIGINAL_HASH[$FILE]}"
        if [[ "${ORIGINAL_HASH[$FILE]}" != 'absent' ]]; then
            cp -a --parents -- "$FILE" "$BACKUP/"
        fi
    done
    for FILE in "${STAGED[@]}"; do
        PENDING="$(mktemp "$(dirname -- "$FILE")/.featured-upload.XXXXXXXX")"
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

http_status() {
    curl -ksS --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" \
        --output /dev/null --write-out '%{http_code}' "https://$DOMAIN$1"
}

test "$(http_status /)" = 200
test "$(http_status /builder)" = 302
for FILE in public/css/builder-featured-upload.css public/js/builder-featured-upload.js; do
    curl -ksSf --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" \
        "https://$DOMAIN/${FILE#public/}?v=1" -o "$WORK/http-asset"
    cmp -s -- "$FILE" "$WORK/http-asset"
done
COMPLETE=1
printf 'BAMBODIA_FEATURED_UPLOAD=PASS\n'
