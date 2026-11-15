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
WORK="$(mktemp -d /root/bambodia-attached-media.XXXXXXXX)"
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
    git hash-object -- "$1"
}

cat > "$WORK/changes.patch" <<'ATTACHED_MEDIA_DIFF'
diff --git a/app/Http/Controllers/BuilderController.php b/app/Http/Controllers/BuilderController.php
index cb3e829..3b2c2ac 100644
--- a/app/Http/Controllers/BuilderController.php
+++ b/app/Http/Controllers/BuilderController.php
@@ -102,8 +102,11 @@ public function show(Request $request): View
         $extendedLicenses = $licenses->filter(fn (LicenseDefinition $definition) => $definition->allowsTier('extended'));
         $ownerId = $managerMode !== null ? MarketplaceManagement::ownerId($record) : (int) $request->user()->id;
+        $attachedMediaIds = $record ? array_map('intval', MarketplaceManagement::mediaIds($record)) : [];
         $assets = $type !== null
-            ? MediaAsset::query()->where('user_id', $ownerId)
-                ->when($managerMode === 'preview', fn ($query) => $query->whereIn('id', MarketplaceManagement::mediaIds($record)))
-                ->latest()->get(['id', 'public_id', 'alt_text', 'mime_type'])
+            ? MediaAsset::query()
+                ->when($managerMode === 'preview',
+                    fn ($query) => $query->whereIn('id', $attachedMediaIds),
+                    fn ($query) => $query->where(fn ($scope) => $scope->where('user_id', $ownerId)->orWhereIn('id', $attachedMediaIds)))
+                ->latest()->get(['id', 'user_id', 'public_id', 'alt_text', 'mime_type'])
             : collect();
 
@@ -161,6 +164,4 @@ public function updateJob(Request $request): RedirectResponse
         ]);
 
-        BuilderFeaturedMedia::check($data, (int)$request->user()->id);
-        BuilderGallery::check($data, $request);
         $status = DB::transaction(function () use ($request, $match, $data, $termsHash): string {
             $job = MarketplaceJob::query()->where('public_id', $match[1])
@@ -169,4 +170,6 @@ public function updateJob(Request $request): RedirectResponse
             abort_unless(in_array($job->status, ['draft', 'pending', 'rejected'], true), 409,
                 'Only drafts, pending jobs, or rejected jobs can be edited.');
+            BuilderFeaturedMedia::check($data, (int) $job->client_id, $job->featured_media_id);
+            BuilderGallery::check($data, $request, (int) $job->client_id, $job->gallery_media_ids ?? []);
             $job->update([
                 ...$data,
@@ -205,5 +208,5 @@ public function updateManaged(Request $request, string $type, string $publicId):
             }
             if ($type === 'product') {
-                $data = app(ProductController::class)->validated($request, $ownerId);
+                $data = app(ProductController::class)->validated($request, $ownerId, $record);
                 unset($data['status']);
             } else {
@@ -241,6 +244,6 @@ private function managedListingData(Request $request, MarketplaceJob|Marketplace
             ]),
         ]);
-        BuilderFeaturedMedia::check($data, $ownerId);
-        BuilderGallery::check($data, $request, $ownerId);
+        BuilderFeaturedMedia::check($data, $ownerId, $record->featured_media_id);
+        BuilderGallery::check($data, $request, $ownerId, $record->gallery_media_ids ?? []);
 
         return $data;
diff --git a/app/Http/Controllers/Creator/ListingController.php b/app/Http/Controllers/Creator/ListingController.php
index 8b4906c..5b0ead1 100644
--- a/app/Http/Controllers/Creator/ListingController.php
+++ b/app/Http/Controllers/Creator/ListingController.php
@@ -76,4 +76,5 @@ public function update(Request $request,MarketplaceListing $listing): RedirectRe
             'description'=>['required','string','min:40','max:10000'],
             ...BuilderFeaturedMedia::rules(),
+            ...BuilderGallery::rules(),
             'description_html'=>['nullable','string','max:60000'],
             'price'=>['nullable','numeric','min:0','max:9999999999.99'],
@@ -88,6 +89,6 @@ public function update(Request $request,MarketplaceListing $listing): RedirectRe
             abort_unless(in_array($locked->status,['draft','rejected'],true),409);
             $this->checkCategory([...$data,'type'=>$locked->type]);
-            BuilderFeaturedMedia::check($data, (int)$request->user()->id);
-            BuilderGallery::check($data, $request);
+            BuilderFeaturedMedia::check($data, (int)$request->user()->id, $locked->featured_media_id);
+            BuilderGallery::check($data, $request, attachedIds: $locked->gallery_media_ids ?? []);
             $locked->update([...$data,'status'=>'pending']);
             BuilderSubmissionTerms::record($termsHash, (int)$request->user()->id, $locked->type, (int)$locked->id);
diff --git a/app/Http/Controllers/Creator/ProductController.php b/app/Http/Controllers/Creator/ProductController.php
index 5a5b085..0157343 100644
--- a/app/Http/Controllers/Creator/ProductController.php
+++ b/app/Http/Controllers/Creator/ProductController.php
@@ -89,16 +89,16 @@ public function update(Request $request, MarketplaceListing $product): RedirectR
         abort_unless(FeatureFlag::isEnabled('creator_submissions'), 403);
         $termsHash = BuilderSubmissionTerms::requireAcceptance($request);
-        $data = $this->validated($request);
-
-        DB::transaction(function () use ($request, $product, $data, $termsHash): void {
+        DB::transaction(function () use ($request, $product, $termsHash): void {
             $record = MarketplaceListing::query()->lockForUpdate()->findOrFail($product->id);
             abort_unless($record->type === 'product' && (int) $record->user_id === (int) $request->user()->id, 404);
             abort_unless(in_array($record->status, ['draft', 'rejected'], true), 409, 'Submitted or published products cannot be edited directly.');
 
+            $data = $this->validated($request, (int) $record->user_id, $record);
             if ($data['status'] === 'pending') {
                 ProductReadiness::assertReady(
                     (int) $record->user_id,
-                    $data['license_definition_id'] ?? null,
-                    $data['cover_media_id'] ?? null
+                    array_key_exists('license_definition_id', $data) ? $data['license_definition_id'] : $record->license_definition_id,
+                    array_key_exists('cover_media_id', $data) ? $data['cover_media_id'] : $record->cover_media_id,
+                    $record->cover_media_id,
                 );
             }
@@ -112,5 +112,5 @@ public function update(Request $request, MarketplaceListing $product): RedirectR
     }
 
-    public function validated(Request $request, ?int $ownerId = null): array
+    public function validated(Request $request, ?int $ownerId = null, ?MarketplaceListing $record = null): array
     {
         $ownerId ??= (int) $request->user()->id;
@@ -137,6 +137,6 @@ public function validated(Request $request, ?int $ownerId = null): array
         ]);
 
-        // A draft must never be allowed to reference a different creator's private asset.
         if (! empty($data['cover_media_id'])
+            && (int) $data['cover_media_id'] !== (int) $record?->cover_media_id
             && ! MediaAsset::query()->whereKey($data['cover_media_id'])
                 ->where('user_id', $ownerId)->exists()) {
@@ -150,6 +150,6 @@ public function validated(Request $request, ?int $ownerId = null): array
             throw ValidationException::withMessages(['category_id'=>'Select an active product category.']);
         }
-        BuilderFeaturedMedia::check($data, $ownerId);
-        BuilderGallery::check($data, $request, $ownerId);
+        BuilderFeaturedMedia::check($data, $ownerId, $record?->featured_media_id);
+        BuilderGallery::check($data, $request, $ownerId, $record ? ($record->gallery_media_ids ?? []) : null);
         // Keep existing preview links when older product forms do not send this field.
         if (array_key_exists('preview_url',$data)) {
diff --git a/app/Http/Controllers/Dashboard/MarketplaceActionController.php b/app/Http/Controllers/Dashboard/MarketplaceActionController.php
index ccde923..87fd975 100644
--- a/app/Http/Controllers/Dashboard/MarketplaceActionController.php
+++ b/app/Http/Controllers/Dashboard/MarketplaceActionController.php
@@ -38,6 +38,4 @@ public function asset(Request $request, string $type, string $publicId, string $
         $asset = MediaAsset::query()->whereIn('id', MarketplaceManagement::mediaIds($record))
             ->where('public_id', $mediaId)->firstOrFail();
-        abort_unless((int) $asset->user_id === MarketplaceManagement::ownerId($record), 404);
-
         return MediaLibraryController::servePreview($request, $asset);
     }
diff --git a/app/Http/Controllers/JobBoardController.php b/app/Http/Controllers/JobBoardController.php
index 163115d..3d5de79 100644
--- a/app/Http/Controllers/JobBoardController.php
+++ b/app/Http/Controllers/JobBoardController.php
@@ -61,5 +61,5 @@ public function show(Request $request, string $job): View
                 ->where('marketplace_job_id',$listing->id)->exists()
             : false;
-        $screenshots = BuilderGallery::approvedFor($listing->gallery_media_ids, (int)$listing->client_id);
+        $screenshots = BuilderGallery::attachedImages($listing->gallery_media_ids);
         $relatedJobs=MarketplaceJob::query()
             ->where('status','published')
diff --git a/app/Http/Controllers/Marketplace/ListingController.php b/app/Http/Controllers/Marketplace/ListingController.php
index 77c6fa3..0fb717b 100644
--- a/app/Http/Controllers/Marketplace/ListingController.php
+++ b/app/Http/Controllers/Marketplace/ListingController.php
@@ -108,5 +108,5 @@ public function show(Request $request, string $type): View
             : [];
 
-        $screenshots = BuilderGallery::approvedFor($listing->gallery_media_ids, (int)$listing->user_id);
+        $screenshots = BuilderGallery::attachedImages($listing->gallery_media_ids);
         return view('marketplace.detail',compact('listing','isSaved','relatedListings','savedRelatedIds','screenshots'));
     }
diff --git a/app/Support/BuilderFeaturedMedia.php b/app/Support/BuilderFeaturedMedia.php
index c1e4ca2..4e8ce35 100644
--- a/app/Support/BuilderFeaturedMedia.php
+++ b/app/Support/BuilderFeaturedMedia.php
@@ -21,5 +21,5 @@ public static function rules(): array
     }
 
-    public static function check(array &$data, int $ownerId): void
+    public static function check(array &$data, int $ownerId, ?int $attachedId = null): void
     {
         if (! array_key_exists('featured_kind', $data)) {
@@ -72,5 +72,6 @@ public static function check(array &$data, int $ownerId): void
         $asset = ! empty($data['featured_media_id'])
             ? MediaAsset::query()->whereKey($data['featured_media_id'])
-                ->where('user_id', $ownerId)->first()
+                ->where(fn ($query) => $query->where('user_id', $ownerId)->orWhere('id', $attachedId))
+                ->first()
             : null;
 
diff --git a/app/Support/BuilderGallery.php b/app/Support/BuilderGallery.php
index 5d9e94e..4ba73e5 100644
--- a/app/Support/BuilderGallery.php
+++ b/app/Support/BuilderGallery.php
@@ -19,5 +19,5 @@ public static function rules(): array
     }
 
-    public static function check(array &$data, Request $request, ?int $ownerId = null): void
+    public static function check(array &$data, Request $request, ?int $ownerId = null, ?array $attachedIds = null): void
     {
         if (! $request->boolean('builder_mode')) {
@@ -26,8 +26,11 @@ public static function check(array &$data, Request $request, ?int $ownerId = nul
             return;
         }
+        if ($attachedIds !== null && ! array_key_exists('gallery_media_ids', $data) && ! $request->boolean('gallery_present')) {
+            return;
+        }
 
         $ids = array_map('intval', $data['gallery_media_ids'] ?? []);
         $allowed = MediaAsset::query()->whereIn('id', $ids)
-            ->where('user_id', $ownerId ?? $request->user()->id)
+            ->where(fn ($query) => $query->where('user_id', $ownerId ?? $request->user()->id)->orWhereIn('id', $attachedIds ?? []))
             ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
             ->count();
@@ -40,10 +43,9 @@ public static function check(array &$data, Request $request, ?int $ownerId = nul
     }
 
-    public static function approvedFor(?array $ids, int $ownerId): \Illuminate\Support\Collection
+    public static function attachedImages(?array $ids): \Illuminate\Support\Collection
     {
         if (! $ids) return collect();
 
         return MediaAsset::query()->whereIn('id', $ids)
-            ->where('user_id', $ownerId)
             ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])
             ->get()->sortBy(fn ($asset) => array_search($asset->id, $ids, false))->values();
diff --git a/app/Support/ProductReadiness.php b/app/Support/ProductReadiness.php
index 5382ad0..af1c2af 100644
--- a/app/Support/ProductReadiness.php
+++ b/app/Support/ProductReadiness.php
@@ -11,5 +11,5 @@
 final class ProductReadiness
 {
-    public static function assertReady(int $ownerId, ?int $licenseId, ?int $coverId): void
+    public static function assertReady(int $ownerId, ?int $licenseId, ?int $coverId, ?int $attachedCoverId = null): void
     {
         $errors = [];
@@ -21,5 +21,5 @@ public static function assertReady(int $ownerId, ?int $licenseId, ?int $coverId)
         $cover = $coverId ? MediaAsset::query()->find($coverId) : null;
         $disk = $cover?->storage_disk ?: 'local';
-        if (! $cover || (int) $cover->user_id !== $ownerId
+        if (! $cover || ((int) $cover->user_id !== $ownerId && $coverId !== $attachedCoverId)
             || ! in_array($cover->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)
             || ! in_array($disk, ['local', 'r2'], true)
@@ -49,5 +49,6 @@ public static function assertListing(MarketplaceListing $listing): void
             (int) $listing->user_id,
             $listing->license_definition_id === null ? null : (int) $listing->license_definition_id,
-            $listing->cover_media_id === null ? null : (int) $listing->cover_media_id
+            $listing->cover_media_id === null ? null : (int) $listing->cover_media_id,
+            $listing->cover_media_id === null ? null : (int) $listing->cover_media_id,
         );
     }
ATTACHED_MEDIA_DIFF

while IFS= read -r FILE; do
    test -f "$FILE" && test ! -L "$FILE"
    test "$(realpath -e -- "$FILE")" = "$APP/$FILE"
    ORIGINAL_HASH["$FILE"]="$(installed_hash "$FILE")"
    mkdir -p -- "$WORK/$(dirname -- "$FILE")"
    cp -- "$FILE" "$WORK/$FILE"
done <<'ATTACHED_MEDIA_FILES'
app/Http/Controllers/BuilderController.php
app/Http/Controllers/Creator/ListingController.php
app/Http/Controllers/Creator/ProductController.php
app/Http/Controllers/Dashboard/MarketplaceActionController.php
app/Http/Controllers/JobBoardController.php
app/Http/Controllers/Marketplace/ListingController.php
app/Http/Controllers/MediaLibraryController.php
app/Support/BuilderFeaturedMedia.php
app/Support/BuilderGallery.php
app/Support/ProductReadiness.php
resources/views/builder/index.blade.php
ATTACHED_MEDIA_FILES

(
    cd "$WORK"
    python3 - "$WORK/changes.patch" <<'PY_CHECK'
from pathlib import Path
import subprocess
import sys

patch = Path(sys.argv[1])
data = patch.read_bytes()
sections = data.split(b'\ndiff --git ')
for i, section in enumerate(sections):
    section = (b'diff --git ' if i else b'') + section
    payload = section.rstrip(b'\n') + b'\n'
    reverse = subprocess.run(['git','apply','--reverse','--check'], input=payload, capture_output=True)
    if reverse.returncode == 0:
        continue
    forward = subprocess.run(['git','apply','--check'], input=payload, capture_output=True)
    if forward.returncode != 0:
        sys.stderr.buffer.write(forward.stderr)
        raise SystemExit('ATTACHED_MEDIA_SOURCE_CONFLICT')
    subprocess.run(['git','apply'], input=payload, check=True)

media = Path('app/Http/Controllers/MediaLibraryController.php')
source = media.read_text()
import_line = 'use App\\Support\\MarketplaceManagement;'
old_guard = '        abort_unless($owner || $admin || $public, 404);'
new_guard = '        abort_unless($owner || $admin || $public || $this->ownsAttachedRecord($request, $asset), 404);'
owner_method = "    private function ownsAttachedRecord(Request $request, MediaAsset $asset): bool\n    {\n        $actor = $request->user();\n        $type = $request->query('listing_type');\n        $reference = $request->query('listing');\n        if (! $actor || $actor->account_status !== 'active'\n            || ! in_array($type, [...MarketplaceListing::TYPES, 'job'], true)\n            || ! is_string($reference) || ! preg_match('/^[0-9]{9}$/D', $reference)) {\n            return false;\n        }\n\n        $record = $type === 'job'\n            ? MarketplaceJob::query()->where('client_id', $actor->id)\n            : MarketplaceListing::query()->where('user_id', $actor->id)->where('type', $type)->where('status', '!=', 'deleted');\n        $listing = $record->where('public_id', $reference)->first();\n\n        return $listing && in_array((int) $asset->id, array_map('intval', MarketplaceManagement::mediaIds($listing)), true);\n    }\n\n"
if new_guard not in source:
    marker = '    public function preview(Request $request, string $publicId): Response'
    if source.count(old_guard) != 1 or source.count(marker) != 1 or 'function ownsAttachedRecord(' in source:
        raise SystemExit('ATTACHED_MEDIA_SOURCE_CONFLICT: MediaLibraryController.php has a different preview guard')
    if import_line not in source:
        anchor = 'use App\\Models\\MediaAsset;'
        if source.count(anchor) != 1:
            raise SystemExit('ATTACHED_MEDIA_SOURCE_CONFLICT: MediaLibraryController.php imports differ')
        source = source.replace(anchor, anchor+'\n'+import_line, 1)
    source = source.replace(marker, owner_method+marker, 1).replace(old_guard, new_guard, 1)
    media.write_text(source)
elif source.count(new_guard) != 1 or owner_method not in source or import_line not in source:
    raise SystemExit('ATTACHED_MEDIA_SOURCE_CONFLICT: incomplete Media Library preview update')

view = Path('resources/views/builder/index.blade.php')
source = view.read_text()
url = "  $mediaUrl = function ($asset) use ($managerMode, $editing, $ownerId, $type, $record) {\n    if ($managerMode === 'preview' || ($managerMode === 'manage' && (int) $asset->user_id !== $ownerId)) {\n      return route('dashboard.marketplace.asset', ['type' => $type, 'publicId' => $record->public_id, 'mediaId' => $asset->public_id]);\n    }\n    return $editing && (int) $asset->user_id !== $ownerId\n      ? route('media.preview', ['publicId' => $asset->public_id, 'listing_type' => $type, 'listing' => $record->public_id])\n      : $asset->previewUrl();\n  };\n"
if url not in source:
    old_url = "  $mediaUrl = fn ($asset) => $managerMode === 'preview'\n    ? route('dashboard.marketplace.asset', ['type' => $type, 'publicId' => $record->public_id, 'mediaId' => $asset->public_id])\n    : $asset->previewUrl();\n"
    if source.count(old_url) == 1:
        source = source.replace(old_url, url, 1)
    else:
        marker = "  $shareUrl = $editing"
        if source.count(marker) != 1 or '$mediaUrl =' in source:
            raise SystemExit('ATTACHED_MEDIA_SOURCE_CONFLICT: Builder media URL layout differs')
        source = source.replace(marker, url + marker, 1)
        source = source.replace(url + marker, '  $managerMode = $managerMode ?? null;\n' + url + marker, 1)
        source = source.replace('{{ $asset->previewUrl() }}', '{{ $mediaUrl($asset) }}')
    source = source.replace('Select media from your library', 'Select attached media or a file from your library')
    gallery = '<div class="studio-gallery-grid" data-gallery-grid>'
    if source.count(gallery) != 1:
        raise SystemExit('ATTACHED_MEDIA_SOURCE_CONFLICT: Builder gallery layout differs')
    if 'name="gallery_present"' not in source:
        source = source.replace(gallery, gallery + '\n                  @if($canEdit)<input type="hidden" name="gallery_present" value="1">@endif', 1)
    view.write_text(source)
PY_CHECK
)

for FILE in "${!ORIGINAL_HASH[@]}"; do
    if ! cmp -s -- "$FILE" "$WORK/$FILE"; then
        "$PHP" -l "$WORK/$FILE"
        STAGED+=("$FILE")
    fi
done

if (( ${#STAGED[@]} > 0 )); then
    BACKUP="$(mktemp -d /root/bambodia-attached-media-backup.XXXXXXXX)"
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

http_status() {
    curl -ksS --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" \
        --output /dev/null --write-out '%{http_code}' "https://$DOMAIN$1"
}

test "$(http_status /)" = 200
test "$(http_status /builder)" = 302
COMPLETE=1
printf 'BAMBODIA_ATTACHED_MEDIA=PASS\n'
