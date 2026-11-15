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
WORK="$(mktemp -d /root/bambodia-marketplace-crud.XXXXXXXX)"
BACKUP=
PENDING=
COMPLETE=0
ROUTES_CACHED=0
declare -A ORIGINAL_HASH=()
declare -a STAGED=() CHANGED=()

artisan() {
    runuser -u www -- "$PHP" artisan "$@" --no-interaction
}

refresh_caches() {
    artisan view:clear || return
    if (( ROUTES_CACHED )); then
        artisan route:cache
    else
        artisan route:clear
    fi
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
        refresh_caches >/dev/null || echo CACHE_RESTORE_FAILED >&2
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

if compgen -G "$APP/bootstrap/cache/routes-*.php" >/dev/null; then ROUTES_CACHED=1; fi

cat > "$WORK/changes.patch" <<'MARKETPLACE_CRUD_DIFF'
diff --git a/app/Http/Controllers/BuilderController.php b/app/Http/Controllers/BuilderController.php
index 0d52bfa..f234efc 100644
--- a/app/Http/Controllers/BuilderController.php
+++ b/app/Http/Controllers/BuilderController.php
@@ -60,4 +60,5 @@ public function show(Request $request): View
             $job = MarketplaceJob::query()
                 ->where('public_id', $match[1])
+                ->where('status', '!=', 'deleted')
                 ->where('client_id', $request->user()->id)->first();
 
@@ -164,4 +165,5 @@ public function updateJob(Request $request): RedirectResponse
             'category' => ['required', Rule::in(array_keys(BambodiaTaxonomy::jobEditorChoices($editableJob->category)))],
             'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
+            'status' => ['sometimes', Rule::in(['draft', 'pending'])],
         ]);
 
@@ -176,5 +178,5 @@ public function updateJob(Request $request): RedirectResponse
             $job->update([
                 ...$data,
-                'status' => $job->status === 'draft' ? 'draft' : 'pending',
+                'status' => $job->status === 'draft' ? ($data['status'] ?? 'draft') : 'pending',
                 'reviewed_by' => null,
                 'reviewed_at' => null,
diff --git a/app/Http/Controllers/Creator/ListingController.php b/app/Http/Controllers/Creator/ListingController.php
index 88b67dc..12bd67c 100644
--- a/app/Http/Controllers/Creator/ListingController.php
+++ b/app/Http/Controllers/Creator/ListingController.php
@@ -8,10 +8,9 @@
 use App\Support\BuilderSubmissionTerms;
 use App\Support\BuilderFeaturedMedia;
+use App\Support\MarketplaceLifecycle;
 use App\Models\MarketplaceListing;
 use App\Models\FeatureFlag;
 use App\Models\MarketplaceCategory;
 use App\Models\AuditEvent;
-use App\Models\ProductEntitlement;
-use App\Models\ProductLicense;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Validation\ValidationException;
@@ -109,5 +108,6 @@ public function changeStatus(Request $request, MarketplaceListing $listing): Red
 
         return $status === 'archived'
-            ? redirect()->route('studio.listings', ['status' => 'archived'])->with('status', 'Listing archived.')
+            ? redirect()->route($request->input('manager') === 'products' && $listing->type === 'product'
+                ? 'studio.products.index' : 'studio.listings', ['status' => 'archived'])->with('status', 'Listing archived.')
             : redirect($listing->builderUrl())->with('status', 'Listing moved to draft.');
     }
@@ -119,5 +119,6 @@ public function destroy(Request $request, MarketplaceListing $listing): Redirect
         $filters = $request->input('from') === 'archived' ? ['status' => 'archived'] : [];
 
-        return redirect()->route('studio.listings', $filters)->with('status', 'Listing deleted.');
+        return redirect()->route($request->input('manager') === 'products' && $listing->type === 'product'
+            ? 'studio.products.index' : 'studio.listings', $filters)->with('status', 'Listing deleted.');
     }
 
@@ -132,11 +133,8 @@ private function changeOwnedStatus(Request $request, MarketplaceListing $listing
             }
 
-            abort_unless($locked->canCreatorSetStatus($status), $locked->status === 'deleted' ? 404 : 409);
-
-            if ($locked->type === 'product' && $this->hasCustomerAccess($locked)) {
-                throw ValidationException::withMessages([
-                    'listing' => 'This product has an existing customer license or download entitlement. Contact the Support Center to manage it.',
-                ]);
+            if ($request->filled('expected_status')) {
+                abort_unless($request->input('expected_status') === $locked->status, 409, 'This listing changed. Reopen the manager and retry.');
             }
+            MarketplaceLifecycle::assertAvailable($locked, $status);
 
             // Preserve references from contracts, inquiries, and other historical records.
@@ -146,15 +144,4 @@ private function changeOwnedStatus(Request $request, MarketplaceListing $listing
     }
 
-    private function hasCustomerAccess(MarketplaceListing $listing): bool
-    {
-        return ProductLicense::query()->where('marketplace_listing_id', $listing->id)
-            ->whereIn('status', ['active', 'suspended'])->whereNull('revoked_at')
-            ->whereHas('entitlement', fn ($query) => $query->where('status', 'active')->whereNull('revoked_at'))
-            ->exists()
-            || ProductEntitlement::query()->where('status', 'active')->whereNull('revoked_at')
-                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
-                ->whereHas('version', fn ($query) => $query->where('marketplace_listing_id', $listing->id))->exists();
-    }
-
     private function checkCategory(array $data): void
     {
diff --git a/app/Http/Controllers/Creator/ProductController.php b/app/Http/Controllers/Creator/ProductController.php
index 17fa9d1..33f1f22 100644
--- a/app/Http/Controllers/Creator/ProductController.php
+++ b/app/Http/Controllers/Creator/ProductController.php
@@ -27,5 +27,5 @@ public function index(Request $request): View
     {
         $status = (string)$request->query('status','all');
-        abort_unless(in_array($status,['all','draft','pending','in_review','published','rejected'],true),404);
+        abort_unless(in_array($status,['all','draft','pending','in_review','published','rejected','archived'],true),404);
         $search = trim((string)$request->query('q',''));
         abort_unless(mb_strlen($search)<=120,404);
@@ -39,4 +39,5 @@ public function index(Request $request): View
                     'coverAsset:id,public_id,alt_text,review_status'])
             ->when($status!=='all',fn($q)=>$q->where('status',$status))
+            ->when($status==='all',fn($q)=>$q->where('status','!=','archived'))
             ->when($search!=='',fn($q)=>$q->where(function($inner)use($search){
                 $inner->where('title','like','%'.$search.'%')
diff --git a/app/Http/Controllers/Dashboard/MarketplaceActionController.php b/app/Http/Controllers/Dashboard/MarketplaceActionController.php
index 87fd975..59020f3 100644
--- a/app/Http/Controllers/Dashboard/MarketplaceActionController.php
+++ b/app/Http/Controllers/Dashboard/MarketplaceActionController.php
@@ -22,11 +22,17 @@ public function status(Request $request, string $type, string $publicId): Redire
         ]);
 
-        DB::transaction(function () use ($request, $type, $publicId, $data): void {
+        $record = DB::transaction(function () use ($request, $type, $publicId, $data) {
             $record = MarketplaceManagement::record($type, $publicId, true);
             abort_unless($record->status === $data['expected_status'], 409, 'The status changed. Refresh the manager and try again.');
             MarketplaceManagement::changeStatus($record, $data['status'], $request);
+            return $record;
         }, 3);
 
-        return redirect(MarketplaceManagement::indexUrl($type))
+        if ($data['status'] === 'draft' && $request->boolean('open_editor')) {
+            return redirect(MarketplaceManagement::builderUrl($record))->with('status', 'Moved to draft. You can now edit this record.');
+        }
+
+        return redirect(MarketplaceManagement::indexUrl($type, in_array($data['status'], ['archived', 'deleted'], true)
+            ? ['status' => $data['status']] : []))
             ->with('status', 'Listing status set to '.MarketplaceManagement::STATUSES[$data['status']].'.');
     }
diff --git a/app/Http/Controllers/JobBoardController.php b/app/Http/Controllers/JobBoardController.php
index 3d5de79..5e3c813 100644
--- a/app/Http/Controllers/JobBoardController.php
+++ b/app/Http/Controllers/JobBoardController.php
@@ -8,4 +8,5 @@
 use App\Support\BuilderFeaturedMedia;
 use App\Support\BambodiaTaxonomy;
+use App\Support\MarketplaceLifecycle;
 use App\Models\AuditEvent;
 use App\Models\AccountNotification;
@@ -87,7 +88,40 @@ public function mine(Request $request): View
     {
         abort_unless(in_array($request->user()->role,['customer','subscriber','super_admin'],true),403);
-        $jobs=MarketplaceJob::query()->where('client_id',$request->user()->id)
-            ->withCount('proposals')->latest()->paginate(10);
-        return view('jobs.mine', compact('jobs'));
+        $archived = $request->query('status') === 'archived';
+        $jobs = MarketplaceJob::query()->where('client_id', $request->user()->id)
+            ->where('status', '!=', 'deleted')->where('status', $archived ? '=' : '!=', 'archived')
+            ->withCount('proposals')->latest()->paginate(10)->withQueryString();
+        return view('jobs.mine', compact('jobs', 'archived'));
+    }
+
+    public function changeStatus(Request $request, MarketplaceJob $job): RedirectResponse
+    {
+        abort_unless(in_array($request->user()->role, ['customer', 'subscriber', 'super_admin'], true), 403);
+        $data = $request->validate([
+            'status' => ['required', Rule::in(['draft', 'archived', 'deleted'])],
+            'expected_status' => ['required', 'string', 'max:20'],
+        ]);
+        $target = $data['status'];
+
+        DB::transaction(function () use ($request, $job, $target, $data): void {
+            $locked = MarketplaceJob::query()->whereKey($job->id)
+                ->where('client_id', $request->user()->id)->lockForUpdate()->firstOrFail();
+            if ($locked->status === $target) {
+                return;
+            }
+            abort_unless($locked->status === $data['expected_status'], 409, 'This job changed. Reopen Job Manager and retry.');
+            MarketplaceLifecycle::assertAvailable($locked, $target);
+            $locked->update([
+                'status' => $target, 'reviewed_by' => null, 'reviewed_at' => null,
+                'closed_at' => $target === 'draft' ? null : now(),
+            ]);
+            AuditEvent::record('job.owner.'.$target, $request->user(), $request);
+        }, 3);
+
+        return match ($target) {
+            'draft' => redirect('/builder?='.$job->public_id)->with('status', 'Job moved to draft.'),
+            'archived' => redirect()->route('jobs.mine', ['status' => 'archived'])->with('status', 'Job archived.'),
+            'deleted' => redirect()->route('jobs.mine')->with('status', 'Job deleted.'),
+        };
     }
 
@@ -122,4 +156,5 @@ public function close(Request $request, MarketplaceJob $job): RedirectResponse
         DB::transaction(function()use($job,$request){
             $locked=MarketplaceJob::query()->lockForUpdate()->findOrFail($job->id);
+            abort_unless((int) $locked->client_id === (int) $request->user()->id, 404);
             abort_unless(in_array($locked->status,['published','pending'],true),409);
             $locked->update(['status'=>'closed','closed_at'=>now()]);
diff --git a/app/Models/AuditEvent.php b/app/Models/AuditEvent.php
index 80236bd..7f2f822 100644
--- a/app/Models/AuditEvent.php
+++ b/app/Models/AuditEvent.php
@@ -43,4 +43,6 @@ class AuditEvent extends Model
         'listing.review.in_review',
         'listing.review.draft',
+        'listing.review.archived',
+        'listing.review.deleted',
         'listing.manager.updated',
         'detail.comment.created',
@@ -99,4 +101,9 @@ class AuditEvent extends Model
         'job.review.in_review',
         'job.review.draft',
+        'job.review.archived',
+        'job.review.deleted',
+        'job.owner.archived',
+        'job.owner.deleted',
+        'job.owner.draft',
         'job.manager.updated',
         'job.proposal.submitted',
diff --git a/app/Support/MarketplaceManagement.php b/app/Support/MarketplaceManagement.php
index f8e2697..fe826b6 100644
--- a/app/Support/MarketplaceManagement.php
+++ b/app/Support/MarketplaceManagement.php
@@ -18,5 +18,5 @@ final class MarketplaceManagement
     public const STATUSES = [
         'draft' => 'Draft', 'pending' => 'Pending', 'in_review' => 'In Review',
-        'published' => 'Published', 'rejected' => 'Rejected',
+        'published' => 'Published', 'rejected' => 'Rejected', 'archived' => 'Archived', 'deleted' => 'Deleted',
     ];
 
@@ -96,4 +96,5 @@ public static function applyFilters(Builder $query, Request $request, string $ty
 
         $query->when($status === 'review_queue', fn ($q) => $q->whereIn('status', self::REVIEW_STATUSES))
+            ->when($status === 'all', fn ($q) => $q->whereNotIn('status', ['archived', 'deleted']))
             ->when(!in_array($status, ['all', 'review_queue'], true), fn ($q) => $q->where('status', $status))
             ->when($search !== '', fn ($q) => $q->where(fn ($match) => $match
@@ -112,5 +113,4 @@ public static function actions(MarketplaceJob|MarketplaceListing $record): array
             'in_review' => ['published', 'draft', 'pending', 'rejected'],
             'rejected' => ['draft', 'pending', 'in_review'],
-            'published' => PreviewContentReview::isPreviewTitle($record->title) ? [] : ['draft'],
             default => [],
         };
@@ -118,6 +118,6 @@ public static function actions(MarketplaceJob|MarketplaceListing $record): array
         return array_intersect_key([
             'published' => 'Publish', 'draft' => 'Draft', 'in_review' => 'In Review',
-            'pending' => 'Pending', 'rejected' => 'Rejected',
-        ], array_flip($statuses));
+            'pending' => 'Pending', 'rejected' => 'Reject', 'archived' => 'Archive', 'deleted' => 'Delete',
+        ], array_flip([...$statuses, ...MarketplaceLifecycle::targets($record)]));
     }
 
@@ -126,13 +126,11 @@ public static function changeStatus(
     ): void {
         abort_unless(isset(self::actions($record)[$target]), 409, 'This status change is unavailable for the current record.');
+        if (in_array($target, ['archived', 'deleted', 'draft'], true)) {
+            MarketplaceLifecycle::assertAvailable($record, $target);
+        }
         $isJob = $record instanceof MarketplaceJob;
         if ($target === 'published' && ! $isJob) {
             ProductReadiness::assertListing($record);
         }
-        if ($record->status === 'published') {
-            abort_if(PreviewContentReview::hasCustomerActivity($record), 409,
-                'Active customer records must be resolved before this listing can return to draft.');
-        }
-
         $record->status = $target;
         $isDecision = in_array($target, ['published', 'rejected'], true);
@@ -140,4 +138,7 @@ public static function changeStatus(
             $record->reviewed_by = $isDecision || $target === 'in_review' ? $request->user()->id : null;
             $record->reviewed_at = $isDecision ? now() : null;
+            if (in_array($target, ['archived', 'deleted', 'draft'], true)) {
+                $record->closed_at = $target === 'draft' ? null : now();
+            }
         }
         $record->save();
diff --git a/resources/views/account/listings.blade.php b/resources/views/account/listings.blade.php
index 51ff72e..68437ee 100644
--- a/resources/views/account/listings.blade.php
+++ b/resources/views/account/listings.blade.php
@@ -39,5 +39,5 @@
               <td>
                 <x-row-action-menu :id="'account-listing-'.$listing->id" :label="$listing->title">
-                  <a href="{{ $listing->builderUrl() }}"><x-icon name="file-text" size="16"/> Details</a>
+                  <a href="{{ $listing->builderUrl() }}"><x-icon name="{{ in_array($listing->status, ['draft', 'rejected'], true) ? 'pencil' : 'file-text' }}" size="16"/> {{ in_array($listing->status, ['draft', 'rejected'], true) ? 'Edit in Builder Studio' : 'Details' }}</a>
                   @if($publicListingIds->has($listing->id))
                     <a href="{{ $listing->publicUrl() }}"><x-icon name="eye" size="16"/> Views</a>
diff --git a/resources/views/account/products.blade.php b/resources/views/account/products.blade.php
index 8fb2d37..fab8b72 100644
--- a/resources/views/account/products.blade.php
+++ b/resources/views/account/products.blade.php
@@ -16,8 +16,8 @@
     <div class="workspace-queue-toolbar">
       <nav class="workspace-queue-tabs" aria-label="Your products by status">
-        @foreach(['all'=>'All','draft'=>'Draft','pending'=>'Pending','in_review'=>'In Review','published'=>'Published','rejected'=>'Rejected'] as $value=>$label)
+        @foreach(['all'=>'All','draft'=>'Draft','pending'=>'Pending','in_review'=>'In Review','published'=>'Published','rejected'=>'Rejected','archived'=>'Archived'] as $value=>$label)
           <a href="{{ route('studio.products.index',array_merge(request()->except('status','page'),$value==='all'?[]:['status'=>$value])) }}"
              class="{{ $status===$value?'is-active':'' }}" @if($status===$value)aria-current="page"@endif>
-            {{ $label }} <span>{{ $value==='all'?$counts->sum():(int)$counts->get($value,0) }}</span>
+            {{ $label }} <span>{{ $value==='all'?$counts->sum()-(int)$counts->get('archived',0):(int)$counts->get($value,0) }}</span>
           </a>
         @endforeach
@@ -59,5 +59,7 @@ class="{{ $status===$value?'is-active':'' }}" @if($status===$value)aria-current=
                   <a href="{{ route('studio.vault.index', $product) }}"><x-icon name="archive" size="16"/> Releases</a>
                 @endif
+                <x-marketplace-owner-lifecycle :record="$product" :menu-id="'creator-product-'.$product->id" manager="products" :triggers="true"/>
               </x-row-action-menu>
+              <x-marketplace-owner-lifecycle :record="$product" :menu-id="'creator-product-'.$product->id" manager="products"/>
             </td>
           </tr>
diff --git a/resources/views/builder/index.blade.php b/resources/views/builder/index.blade.php
index 63ac448..248cd8b 100644
--- a/resources/views/builder/index.blade.php
+++ b/resources/views/builder/index.blade.php
@@ -554,5 +554,5 @@
             @endunless
 
-            @if($isProduct && !$managed)
+            @if(!$managed && ($isProduct || ($editing && $isJob && $record->status === 'draft')))
               <section class="studio-final-action">
                 <label for="studio-next-status">Next action</label>
diff --git a/resources/views/dashboard/partials/marketplace-actions.blade.php b/resources/views/dashboard/partials/marketplace-actions.blade.php
index f909d16..ec064c9 100644
--- a/resources/views/dashboard/partials/marketplace-actions.blade.php
+++ b/resources/views/dashboard/partials/marketplace-actions.blade.php
@@ -8,12 +8,18 @@
   ]) : null;
   $actions = $canReview ? MarketplaceManagement::actions($record) : [];
+  $icons = ['published' => 'check-circle', 'draft' => 'pencil', 'in_review' => 'clipboard-check',
+    'pending' => 'clock', 'rejected' => 'x', 'archived' => 'archive', 'deleted' => 'trash-2'];
 @endphp
 <x-row-action-menu :id="$dialogId" :label="$record->title">
+  <a href="{{ $previewUrl }}"><x-icon name="eye" size="16"/> View details</a>
+  @if($record->status === 'published')<a href="{{ $record->publicUrl() }}"><x-icon name="external-link" size="16"/> View published page</a>@endif
   <button type="button" data-modal-open="{{ $dialogId }}"><x-icon name="settings" size="16"/> Configure</button>
   @if($editUrl)<a href="{{ $editUrl }}"><x-icon name="pencil" size="16"/> Edit</a>@endif
   @foreach($actions as $target => $label)
-    <button type="button" data-modal-open="{{ $dialogId }}-{{ $target }}">{{ $label }}</button>
+    <button type="button" data-modal-open="{{ $dialogId }}-{{ $target }}" @class(['is-danger' => $target === 'deleted'])>
+      <x-icon :name="$icons[$target]" size="16"/> {{ $target === 'draft' ? ($record->status === 'archived' ? 'Restore to draft' : ($editUrl ? 'Move to draft' : 'Edit as draft')) : $label }}
+    </button>
   @endforeach
-  @if($transferUrl)<a href="{{ $transferUrl }}">Change {{ $isJob ? 'client' : 'creator' }}</a>@endif
+  @if($transferUrl && $record->status !== 'deleted')<a href="{{ $transferUrl }}"><x-icon name="users" size="16"/> Change {{ $isJob ? 'client' : 'creator' }}</a>@endif
 </x-row-action-menu>
 <dialog class="config-dialog wide-dialog" id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-heading">
@@ -39,5 +45,5 @@
     <a class="btn subtle" href="{{ $previewUrl }}">Open Builder preview</a>
     @if($editUrl)<a class="btn primary" href="{{ $editUrl }}">Edit in Builder Studio</a>@endif
-    @if($transferUrl)<a class="btn subtle" href="{{ $transferUrl }}">Change {{ $isJob ? 'client' : 'creator' }}</a>@endif
+    @if($transferUrl && $record->status !== 'deleted')<a class="btn subtle" href="{{ $transferUrl }}">Change {{ $isJob ? 'client' : 'creator' }}</a>@endif
     @if($record->status === 'published')<a class="btn subtle" href="{{ $record->publicUrl() }}">View published listing</a>@endif
   </div>
@@ -45,13 +51,15 @@
 @foreach($actions as $target => $label)
   <dialog class="config-dialog" id="{{ $dialogId }}-{{ $target }}" aria-labelledby="{{ $dialogId }}-{{ $target }}-title">
-    <h2 id="{{ $dialogId }}-{{ $target }}-title">{{ $target === 'published' ? 'Publish listing' : 'Move to '.$label }}</h2>
+    <h2 id="{{ $dialogId }}-{{ $target }}-title">{{ match($target) { 'published' => 'Publish listing', 'archived' => 'Archive listing', 'deleted' => 'Delete listing', 'rejected' => 'Reject listing', default => 'Move to '.$label } }}</h2>
     <p>{{ $record->title }}</p>
+    @if($target === 'deleted')<p>This removes the record from the marketplace and the owner’s active listings while keeping its history.</p>@endif
     @if($target === 'published' && $isProduct)<p>An active license and the creator’s stored cover are required.</p>@endif
     <form method="post" action="{{ route('dashboard.marketplace.status', ['type' => $type, 'publicId' => $record->public_id]) }}">
       @csrf @method('PATCH')
       <input type="hidden" name="expected_status" value="{{ $record->status }}">
+      @if($target === 'draft' && !$editUrl)<input type="hidden" name="open_editor" value="1">@endif
       <div class="dialog-actions">
         <button type="button" class="btn subtle" data-modal-close>Cancel</button>
-        <button class="btn primary" type="submit" name="status" value="{{ $target }}">{{ $target === 'published' ? 'Publish' : 'Set '.$label }}</button>
+        <button class="btn {{ $target === 'deleted' ? 'danger' : 'primary' }}" type="submit" name="status" value="{{ $target }}">{{ $label }}</button>
       </div>
     </form>
diff --git a/resources/views/dashboard/partials/marketplace-manager.blade.php b/resources/views/dashboard/partials/marketplace-manager.blade.php
index 0ee2afa..65323b3 100644
--- a/resources/views/dashboard/partials/marketplace-manager.blade.php
+++ b/resources/views/dashboard/partials/marketplace-manager.blade.php
@@ -16,5 +16,8 @@
   <div class="eyebrow">Marketplace Governance</div>
   <h1>{{ $name }} Manager</h1>
-  <p>{{ $isProduct ? 'Products require an active license and a valid creator-owned cover to publish.' : 'Find listings, open their Builder Studio preview, and manage review status.' }}</p>
+  <p>{{ $isProduct ? 'Products require an active license and a valid cover to publish.' : 'Find listings, open their Builder Studio preview, and manage review status.' }}</p>
+  @if(in_array($type, \App\Support\BuilderAccess::allowedTypes(auth()->user()), true))
+    <a class="btn primary" href="{{ route('builder', ['type' => $type]) }}"><x-icon name="plus" size="16"/> Create {{ strtolower($name) }}</a>
+  @endif
 </div>
 @foreach($errors->all() as $error)
diff --git a/resources/views/jobs/mine.blade.php b/resources/views/jobs/mine.blade.php
index 3a22299..5d723dc 100644
--- a/resources/views/jobs/mine.blade.php
+++ b/resources/views/jobs/mine.blade.php
@@ -9,7 +9,11 @@
   <section class="panel">
     <div class="panel-head">
-      <h2>My posted jobs <span class="count">{{ $jobs->total() }}</span></h2>
+      <h2>{{ $archived ? 'Archived jobs' : 'My posted jobs' }} <span class="count">{{ $jobs->total() }}</span></h2>
       <a class="btn primary" href="{{ route('builder', ['type'=>'job']) }}"><x-icon name="plus" size="16"/> Post a job</a>
     </div>
+    <nav class="workspace-queue-tabs" aria-label="Jobs by status">
+      <a href="{{ route('jobs.mine') }}" @if(!$archived) class="is-active" aria-current="page" @endif>My jobs</a>
+      <a href="{{ route('jobs.mine', ['status' => 'archived']) }}" @if($archived) class="is-active" aria-current="page" @endif>Archived</a>
+    </nav>
     <div class="table-scroll"><table class="data-table">
       <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Budget</th><th>Status</th><th>Proposals</th><th>Action</th></tr></thead>
@@ -24,7 +28,11 @@
           <td>{{ $job->proposals_count }}</td>
           <td><x-row-action-menu :id="'my-job-'.$job->id" :label="$job->title">
-            <a href="{{ url('/builder?='.$job->public_id) }}"><x-icon name="edit" size="16"/> {{ in_array($job->status,['pending','rejected'],true) ? 'Edit in builder' : 'Details' }}</a>
+            <a href="{{ url('/builder?='.$job->public_id) }}"><x-icon name="edit" size="16"/> {{ in_array($job->status,['draft','pending','rejected'],true) ? 'Edit in Builder Studio' : 'View details' }}</a>
+            @if($job->status === 'published')<a href="{{ $job->publicUrl() }}"><x-icon name="external-link" size="16"/> View published job</a>@endif
             <a href="{{ route('jobs.proposals',$job) }}"><x-icon name="eye" size="16"/> Proposals</a>
-          </x-row-action-menu></td>
+            <x-marketplace-owner-lifecycle :record="$job" :menu-id="'my-job-'.$job->id" :triggers="true"/>
+          </x-row-action-menu>
+          <x-marketplace-owner-lifecycle :record="$job" :menu-id="'my-job-'.$job->id"/>
+          </td>
         </tr>
       @empty
diff --git a/routes/web.php b/routes/web.php
index ca75b83..bae47b4 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -246,4 +246,6 @@
         ->name('contracts.disputes.store')->middleware('throttle:2,1');
     Route::get('/my-jobs', [JobBoardController::class, 'mine'])->name('jobs.mine');
+    Route::patch('/my-jobs/{job}/status', [JobBoardController::class, 'changeStatus'])
+        ->middleware('throttle:15,1')->name('jobs.status');
     Route::post('/my-jobs', [JobBoardController::class, 'store'])->name('jobs.store')->middleware('throttle:5,1');
     Route::patch('/my-jobs/{job}/close', [JobBoardController::class, 'close'])->name('jobs.close');
diff --git a/app/Support/MarketplaceLifecycle.php b/app/Support/MarketplaceLifecycle.php
new file mode 100644
index 0000000..7b7d5d0
--- /dev/null
+++ b/app/Support/MarketplaceLifecycle.php
@@ -0,0 +1,44 @@
+<?php
+
+namespace App\Support;
+
+use App\Models\MarketplaceJob;
+use App\Models\MarketplaceListing;
+use App\Models\ProductEntitlement;
+use App\Models\ProductLicense;
+use Illuminate\Validation\ValidationException;
+
+final class MarketplaceLifecycle
+{
+    public static function targets(MarketplaceJob|MarketplaceListing $record): array
+    {
+        return match ($record->status) {
+            'draft' => ['archived', 'deleted'],
+            'pending', 'in_review', 'published', 'rejected', 'closed' => ['draft', 'archived', 'deleted'],
+            'archived' => ['draft', 'deleted'],
+            default => [],
+        };
+    }
+
+    public static function assertAvailable(MarketplaceJob|MarketplaceListing $record, string $target): void
+    {
+        abort_unless(in_array($target, self::targets($record), true), $record->status === 'deleted' ? 404 : 409);
+
+        if ($record instanceof MarketplaceListing && $record->type === 'product' && self::hasCustomerAccess($record)) {
+            throw ValidationException::withMessages([
+                'listing' => 'This product has an existing customer license or download entitlement. Contact the Support Center to manage it.',
+            ]);
+        }
+    }
+
+    private static function hasCustomerAccess(MarketplaceListing $listing): bool
+    {
+        return ProductLicense::query()->where('marketplace_listing_id', $listing->id)
+            ->whereIn('status', ['active', 'suspended'])->whereNull('revoked_at')
+            ->whereHas('entitlement', fn ($query) => $query->where('status', 'active')->whereNull('revoked_at'))
+            ->exists()
+            || ProductEntitlement::query()->where('status', 'active')->whereNull('revoked_at')
+                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
+                ->whereHas('version', fn ($query) => $query->where('marketplace_listing_id', $listing->id))->exists();
+    }
+}
diff --git a/resources/views/components/marketplace-owner-lifecycle.blade.php b/resources/views/components/marketplace-owner-lifecycle.blade.php
new file mode 100644
index 0000000..f996a0f
--- /dev/null
+++ b/resources/views/components/marketplace-owner-lifecycle.blade.php
@@ -0,0 +1,37 @@
+@props(['record', 'menuId', 'manager' => null, 'triggers' => false])
+@php
+  $job = $record instanceof \App\Models\MarketplaceJob;
+  $kind = $job ? 'job' : 'listing';
+  $actions = array_intersect_key([
+      'draft' => [$record->status === 'archived' ? 'Restore to draft' : 'Move to draft', 'pencil',
+          'Open the editor after moving this '.$kind.' to draft. Submit the changes for review when ready.'],
+      'archived' => ['Archive', 'archive', 'Hide this '.$kind.' and keep it in Archived.'],
+      'deleted' => ['Delete', 'trash-2', 'Remove this '.$kind.' from the marketplace and your manager. Its history is kept.'],
+  ], array_flip(\App\Support\MarketplaceLifecycle::targets($record)));
+@endphp
+@foreach($actions as $target => [$label, $icon, $copy])
+  @php($dialogId = $menuId.'-'.$target)
+  @if($triggers)
+    <button type="button" data-modal-open="{{ $dialogId }}" @class(['is-danger' => $target === 'deleted'])>
+      <x-icon :name="$icon" size="16"/> {{ $label }}
+    </button>
+  @else
+    <dialog class="config-dialog" id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title">
+      <h2 id="{{ $dialogId }}-title">{{ $label }}</h2>
+      <p><strong>{{ $record->title }}</strong></p>
+      <p>{{ $copy }}</p>
+      <form method="post" action="{{ route($job ? 'jobs.status' : ($target === 'deleted' ? 'studio.listings.destroy' : 'studio.listings.status'), $record) }}">
+        @csrf
+        @method(!$job && $target === 'deleted' ? 'DELETE' : 'PATCH')
+        <input type="hidden" name="status" value="{{ $target }}">
+        <input type="hidden" name="expected_status" value="{{ $record->status }}">
+        @if($manager)<input type="hidden" name="manager" value="{{ $manager }}">@endif
+        @if($record->status === 'archived')<input type="hidden" name="from" value="archived">@endif
+        <div class="dialog-actions">
+          <button type="button" class="btn subtle" data-modal-close>Cancel</button>
+          <button class="btn {{ $target === 'deleted' ? 'danger' : 'primary' }}" type="submit">{{ $label }}</button>
+        </div>
+      </form>
+    </dialog>
+  @endif
+@endforeach
MARKETPLACE_CRUD_DIFF
cat > "$WORK/alternate-menu.patch" <<'MARKETPLACE_CRUD_MENU_DIFF'
diff --git a/resources/views/dashboard/partials/marketplace-actions.blade.php b/resources/views/dashboard/partials/marketplace-actions.blade.php
index 5051166..ec064c9 100644
--- a/resources/views/dashboard/partials/marketplace-actions.blade.php
+++ b/resources/views/dashboard/partials/marketplace-actions.blade.php
@@ -8,19 +8,18 @@
   ]) : null;
   $actions = $canReview ? MarketplaceManagement::actions($record) : [];
+  $icons = ['published' => 'check-circle', 'draft' => 'pencil', 'in_review' => 'clipboard-check',
+    'pending' => 'clock', 'rejected' => 'x', 'archived' => 'archive', 'deleted' => 'trash-2'];
 @endphp
 <x-row-action-menu :id="$dialogId" :label="$record->title">
+  <a href="{{ $previewUrl }}"><x-icon name="eye" size="16"/> View details</a>
+  @if($record->status === 'published')<a href="{{ $record->publicUrl() }}"><x-icon name="external-link" size="16"/> View published page</a>@endif
   <button type="button" data-modal-open="{{ $dialogId }}"><x-icon name="settings" size="16"/> Configure</button>
   @if($editUrl)<a href="{{ $editUrl }}"><x-icon name="pencil" size="16"/> Edit</a>@endif
   @foreach($actions as $target => $label)
-    <button type="button" data-modal-open="{{ $dialogId }}-{{ $target }}">
-      @if($target === 'in_review')
-        <x-icon name="clipboard-check" size="16"/>
-      @elseif($target === 'pending')
-        <x-icon name="clock" size="16"/>
-      @endif
-      {{ $label }}
+    <button type="button" data-modal-open="{{ $dialogId }}-{{ $target }}" @class(['is-danger' => $target === 'deleted'])>
+      <x-icon :name="$icons[$target]" size="16"/> {{ $target === 'draft' ? ($record->status === 'archived' ? 'Restore to draft' : ($editUrl ? 'Move to draft' : 'Edit as draft')) : $label }}
     </button>
   @endforeach
-  @if($transferUrl)<a href="{{ $transferUrl }}"><x-icon name="users" size="16"/> Change {{ $isJob ? 'client' : 'creator' }}</a>@endif
+  @if($transferUrl && $record->status !== 'deleted')<a href="{{ $transferUrl }}"><x-icon name="users" size="16"/> Change {{ $isJob ? 'client' : 'creator' }}</a>@endif
 </x-row-action-menu>
 <dialog class="config-dialog wide-dialog" id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-heading">
@@ -46,5 +45,5 @@
     <a class="btn subtle" href="{{ $previewUrl }}">Open Builder preview</a>
     @if($editUrl)<a class="btn primary" href="{{ $editUrl }}">Edit in Builder Studio</a>@endif
-    @if($transferUrl)<a class="btn subtle" href="{{ $transferUrl }}">Change {{ $isJob ? 'client' : 'creator' }}</a>@endif
+    @if($transferUrl && $record->status !== 'deleted')<a class="btn subtle" href="{{ $transferUrl }}">Change {{ $isJob ? 'client' : 'creator' }}</a>@endif
     @if($record->status === 'published')<a class="btn subtle" href="{{ $record->publicUrl() }}">View published listing</a>@endif
   </div>
@@ -52,13 +51,15 @@
 @foreach($actions as $target => $label)
   <dialog class="config-dialog" id="{{ $dialogId }}-{{ $target }}" aria-labelledby="{{ $dialogId }}-{{ $target }}-title">
-    <h2 id="{{ $dialogId }}-{{ $target }}-title">{{ $target === 'published' ? 'Publish listing' : 'Move to '.$label }}</h2>
+    <h2 id="{{ $dialogId }}-{{ $target }}-title">{{ match($target) { 'published' => 'Publish listing', 'archived' => 'Archive listing', 'deleted' => 'Delete listing', 'rejected' => 'Reject listing', default => 'Move to '.$label } }}</h2>
     <p>{{ $record->title }}</p>
+    @if($target === 'deleted')<p>This removes the record from the marketplace and the owner’s active listings while keeping its history.</p>@endif
     @if($target === 'published' && $isProduct)<p>An active license and the creator’s stored cover are required.</p>@endif
     <form method="post" action="{{ route('dashboard.marketplace.status', ['type' => $type, 'publicId' => $record->public_id]) }}">
       @csrf @method('PATCH')
       <input type="hidden" name="expected_status" value="{{ $record->status }}">
+      @if($target === 'draft' && !$editUrl)<input type="hidden" name="open_editor" value="1">@endif
       <div class="dialog-actions">
         <button type="button" class="btn subtle" data-modal-close>Cancel</button>
-        <button class="btn primary" type="submit" name="status" value="{{ $target }}">{{ $target === 'published' ? 'Publish' : 'Set '.$label }}</button>
+        <button class="btn {{ $target === 'deleted' ? 'danger' : 'primary' }}" type="submit" name="status" value="{{ $target }}">{{ $label }}</button>
       </div>
     </form>
MARKETPLACE_CRUD_MENU_DIFF
copy_to_stage() {
    local path="$1"
    test ! -L "$path"
    test "$(realpath -e -- "$(dirname -- "$path")")/$(basename -- "$path")" = "$APP/$path"
    ORIGINAL_HASH["$path"]="$(installed_hash "$path")"
    mkdir -p -- "$WORK/$(dirname -- "$path")"
    if [[ "${ORIGINAL_HASH[$path]}" != absent ]]; then
        cp -- "$path" "$WORK/$path"
    fi
}

while IFS= read -r FILE; do
    test -f "$FILE"
    copy_to_stage "$FILE"
done <<'MARKETPLACE_CRUD_TRACKED'
app/Http/Controllers/BuilderController.php
app/Http/Controllers/Creator/ListingController.php
app/Http/Controllers/Creator/ProductController.php
app/Http/Controllers/Dashboard/MarketplaceActionController.php
app/Http/Controllers/JobBoardController.php
app/Models/AuditEvent.php
app/Support/MarketplaceManagement.php
resources/views/account/listings.blade.php
resources/views/account/products.blade.php
resources/views/builder/index.blade.php
resources/views/dashboard/partials/marketplace-actions.blade.php
resources/views/dashboard/partials/marketplace-manager.blade.php
resources/views/jobs/mine.blade.php
routes/web.php
MARKETPLACE_CRUD_TRACKED
while IFS= read -r FILE; do
    copy_to_stage "$FILE"
done <<'MARKETPLACE_CRUD_NEW'
app/Support/MarketplaceLifecycle.php
resources/views/components/marketplace-owner-lifecycle.blade.php
MARKETPLACE_CRUD_NEW

(
    cd "$WORK"
    python3 - "$WORK/changes.patch" "$WORK/alternate-menu.patch" <<'VERIFY_PATCH'
from pathlib import Path
import subprocess
import sys


def apply(payload, *flags):
    return subprocess.run(['git', 'apply', *flags], input=payload, capture_output=True)


patch = Path(sys.argv[1]).read_bytes()
alternate = Path(sys.argv[2]).read_bytes()
menu = 'resources/views/dashboard/partials/marketplace-actions.blade.php'

for chunk in patch.split(b'diff --git ')[1:]:
    path = chunk.split(b'\n', 1)[0].split(b' b/', 1)[1].decode()
    payload = b'diff --git ' + chunk
    if apply(payload, '--reverse', '--check').returncode == 0:
        continue
    check = apply(payload, '--check')
    if check.returncode != 0 and path == menu:
        payload = alternate
        if apply(payload, '--reverse', '--check').returncode == 0:
            continue
        check = apply(payload, '--check')
    if check.returncode != 0:
        sys.stderr.buffer.write(check.stderr)
        raise SystemExit(f'MARKETPLACE_CRUD_SOURCE_CONFLICT={path}')
    applied = apply(payload)
    if applied.returncode != 0:
        sys.stderr.buffer.write(applied.stderr)
        raise SystemExit(f'MARKETPLACE_CRUD_PATCH_FAILED={path}')
VERIFY_PATCH
)
for FILE in app/Support/MarketplaceLifecycle.php resources/views/components/marketplace-owner-lifecycle.blade.php app/Http/Controllers/BuilderController.php app/Http/Controllers/Creator/ListingController.php app/Http/Controllers/Creator/ProductController.php app/Http/Controllers/Dashboard/MarketplaceActionController.php app/Http/Controllers/JobBoardController.php app/Models/AuditEvent.php app/Support/MarketplaceManagement.php resources/views/account/listings.blade.php resources/views/account/products.blade.php resources/views/builder/index.blade.php resources/views/dashboard/partials/marketplace-actions.blade.php resources/views/dashboard/partials/marketplace-manager.blade.php resources/views/jobs/mine.blade.php routes/web.php; do
    if ! cmp -s -- "$FILE" "$WORK/$FILE"; then
        "$PHP" -l "$WORK/$FILE"
        STAGED+=("$FILE")
    fi
done

if (( ${#STAGED[@]} > 0 )); then
    BACKUP="$(mktemp -d /root/bambodia-marketplace-crud-backup.XXXXXXXX)"
    printf 'BACKUP=%s\n' "$BACKUP"
    for FILE in "${STAGED[@]}"; do
        test "$(installed_hash "$FILE")" = "${ORIGINAL_HASH[$FILE]}"
        if [[ "${ORIGINAL_HASH[$FILE]}" != 'absent' ]]; then
            cp -a --parents -- "$FILE" "$BACKUP/"
        fi
    done
    for FILE in "${STAGED[@]}"; do
        PENDING="$(mktemp "$(dirname -- "$FILE")/.marketplace-crud.XXXXXXXX")"
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
    refresh_caches
fi

http_status() {
    curl -ksS --max-time 15 --resolve "$DOMAIN:443:127.0.0.1" \
        --output /dev/null --write-out '%{http_code}' "https://$DOMAIN$1"
}

test "$(http_status /)" = 200
test "$(http_status /builder)" = 302
test "$(http_status /my-jobs)" = 302
artisan route:list --path=my-jobs --json > "$WORK/routes.json"
python3 - "$WORK/routes.json" <<'VERIFY_ROUTES'
import json
from pathlib import Path
import sys

routes = json.loads(Path(sys.argv[1]).read_text())
assert any(
    row['name'] == 'jobs.status' and row['method'] == 'PATCH' and row['uri'] == 'my-jobs/{job}/status'
    for row in routes
), 'Missing owner job status route'
VERIFY_ROUTES
echo MARKETPLACE_CRUD_ROUTES=PASS
COMPLETE=1
printf 'BAMBODIA_MARKETPLACE_CRUD=PASS\n'
