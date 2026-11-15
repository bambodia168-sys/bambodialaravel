<?php

namespace App\Http\Controllers;

use App\Support\BuilderContent;
use App\Support\BuilderGallery;
use App\Support\BuilderSubmissionTerms;
use App\Support\BuilderFeaturedMedia;
use App\Support\BambodiaTaxonomy;
use App\Support\MarketplaceManagement;
use App\Support\UserManagementAccess;
use App\Http\Controllers\Creator\ProductController;
use App\Models\AuditEvent;
use App\Models\AiDraftProvider;
use App\Models\FeatureFlag;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\Policy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BuilderController extends Controller
{
    public function show(Request $request): View
    {
        $allowedTypes = $this->allowedTypes($request);
        $raw = (string) $request->server('QUERY_STRING', '');
        $record = null;
        $type = null;
        $managerMode = null;

        if ($request->query->has('mode') || $request->query->has('item')) {
            $type = $request->query('type');
            $publicId = $request->query('item');
            $managerMode = $request->query('mode');
            abort_unless(in_array($type, ['product', 'project', 'service', 'freelance', 'job'], true)
                && is_string($publicId) && preg_match('/^[0-9]{9}$/D', $publicId) === 1
                && in_array($managerMode, ['manage', 'preview'], true), 404);
            abort_unless(MarketplaceManagement::mayView($request->user()), 403);
            $record = MarketplaceManagement::record($type, $publicId);
            if ($managerMode === 'manage') {
                abort_unless(UserManagementAccess::allows($request->user(), 'marketplace_review'), 403);
                abort_unless(MarketplaceManagement::mayEdit($record), 409);
            }
        } elseif ($raw === '') {
            // The builder landing page lets authorized users choose what to create.
        } elseif (preg_match('/^=([0-9]{9})$/D', $raw, $match) === 1) {
            // The public ID is not authorization: fetch resources belonging to the session user.
            $listing = MarketplaceListing::query()
                ->where('public_id', $match[1])
                ->where('user_id', $request->user()->id)->first();
            $job = MarketplaceJob::query()
                ->where('public_id', $match[1])
                ->where('client_id', $request->user()->id)->first();

            // These models allocate IDs independently. Never choose an ambiguous record.
            abort_if($listing !== null && $job !== null, 409, 'This ID is ambiguous. Contact support.');
            $record = $listing ?? $job;
            abort_unless($record !== null, 404);
            $type = $listing?->type ?? 'job';
        } elseif (preg_match('/^type=(product|project|service|freelance|job)$/D', $raw, $match) === 1) {
            $type = $match[1];
        } else {
            abort(404);
        }

        if ($type !== null && $managerMode === null) {
            abort_unless(in_array($type, $allowedTypes, true), 403);
        }

        $canEdit = $managerMode === 'manage' || ($managerMode === null && ($record === null
            || ($type === 'job'
                ? in_array($record->status, ['draft', 'pending', 'rejected'], true)
                : in_array($record->status, ['draft', 'rejected'], true))));

        $jobCategoryChoices = $type === 'job'
            ? BambodiaTaxonomy::jobEditorChoices($record?->category)
            : [];
        $categories = $type !== null && $type !== 'job'
            ? MarketplaceCategory::query()->where('type', $type)->where('is_active', true)
                ->with('parent:id,name')->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'type', 'name', 'parent_id'])
            : collect();
        $publishedLegalPolicies = Policy::query()->where('status', 'published')
            ->whereIn('title', ['Regular License', 'Extended License', 'Terms of Service', 'Privacy Policy', 'Creator and Seller Agreement', 'Refund and Cancellation Policy'])
            ->orderBy('title')->get(['id', 'title', 'public_id', 'slug']);
        $termsPublications = BuilderSubmissionTerms::publications();
        $termsSha256 = BuilderSubmissionTerms::digest($termsPublications);
        $suggestedTerms = BuilderSubmissionTerms::suggestedTerms($termsPublications);
        $licenses = $type === 'product'
            ? LicenseDefinition::query()->where('status', 'active')
                ->orderBy('name')->get(['id','name','version','tier','public_id','slug','permissions','restrictions'])
            : collect();
        $regularLicenses = $licenses->filter(fn (LicenseDefinition $definition) => $definition->allowsTier('regular'));
        $extendedLicenses = $licenses->filter(fn (LicenseDefinition $definition) => $definition->allowsTier('extended'));
        $ownerId = $managerMode !== null ? MarketplaceManagement::ownerId($record) : (int) $request->user()->id;
        $attachedMediaIds = $record ? array_map('intval', MarketplaceManagement::mediaIds($record)) : [];
        $assets = $type !== null
            ? MediaAsset::query()
                ->when($managerMode === 'preview',
                    fn ($query) => $query->whereIn('id', $attachedMediaIds),
                    fn ($query) => $query->where(fn ($scope) => $scope->where('user_id', $ownerId)->orWhereIn('id', $attachedMediaIds)))
                ->latest()->get(['id', 'user_id', 'public_id', 'alt_text', 'mime_type'])
            : collect();

        $productVersions = $managerMode === null && $type === 'product' && $record
            ? \App\Models\ProductFileVersion::query()
                ->where('marketplace_listing_id', $record->id)->latest('id')->get()
            : collect();

        $aiDraftReady = AiDraftProvider::configured()?->ready() ?? false;

        return view('builder.index', compact(
            'type', 'record', 'canEdit', 'managerMode', 'ownerId', 'allowedTypes', 'categories', 'jobCategoryChoices', 'licenses', 'regularLicenses', 'extendedLicenses', 'assets',
            'termsPublications', 'termsSha256', 'suggestedTerms', 'productVersions', 'publishedLegalPolicies', 'aiDraftReady'
        ));
    }

    public function previewContent(Request $request): JsonResponse
    {
        abort_unless(count($this->allowedTypes($request)) > 0
            || UserManagementAccess::allows($request->user(), 'marketplace_review'), 403);
        $values = $request->validate([
            'html' => ['required', 'string', 'max:100000'],
        ]);
        $safe = BuilderContent::sanitize($values['html']) ?? '';
        if (mb_strlen($safe) > 60000) {
            return response()->json(['message' => 'Formatted HTML exceeds the 60,000-character limit.'], 422);
        }
        return response()->json([
            'html' => $safe,
            'text' => BuilderContent::plainText($safe),
        ]);
    }

    public function updateJob(Request $request): RedirectResponse
    {
        abort_unless(in_array('job', $this->allowedTypes($request), true), 403);
        $raw = (string) $request->server('QUERY_STRING', '');
        abort_unless(preg_match('/^=([0-9]{9})$/D', $raw, $match) === 1, 404);

        $editableJob = MarketplaceJob::query()
            ->where('public_id', $match[1])
            ->where('client_id', $request->user()->id)
            ->firstOrFail();

        BuilderContent::prepare($request);
        $termsHash = BuilderSubmissionTerms::requireAcceptance($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:8', 'max:180'],
            'description' => ['required', 'string', 'min:70', 'max:10000'],
            'description_html' => ['nullable', 'string', 'max:60000'],
            ...BuilderFeaturedMedia::rules(),
            ...BuilderGallery::rules(),
            'category' => ['required', Rule::in(array_keys(BambodiaTaxonomy::jobEditorChoices($editableJob->category)))],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        $status = DB::transaction(function () use ($request, $match, $data, $termsHash): string {
            $job = MarketplaceJob::query()->where('public_id', $match[1])
                ->lockForUpdate()->firstOrFail();
            abort_unless((int) $job->client_id === (int) $request->user()->id, 404);
            abort_unless(in_array($job->status, ['draft', 'pending', 'rejected'], true), 409,
                'Only drafts, pending jobs, or rejected jobs can be edited.');
            BuilderFeaturedMedia::check($data, (int) $job->client_id, $job->featured_media_id);
            BuilderGallery::check($data, $request, (int) $job->client_id, $job->gallery_media_ids ?? []);
            $job->update([
                ...$data,
                'status' => $job->status === 'draft' ? 'draft' : 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);
            BuilderSubmissionTerms::record($termsHash, (int)$request->user()->id, 'job', (int)$job->id);
            AuditEvent::record('job.updated', $request->user(), $request);

            return $job->status;
        });

        return redirect('/builder?='.$match[1])
            ->with('status', $status === 'draft' ? 'Job draft saved.' : 'Job saved and queued for administrator review.');
    }

    public function updateManaged(Request $request, string $type, string $publicId): RedirectResponse
    {
        $expected = $request->validate([
            'expected_owner' => ['required', 'integer', 'min:1'],
            'expected_revision' => ['required', 'string', 'size:64'],
        ]);

        $record = DB::transaction(function () use ($request, $type, $publicId, $expected) {
            $record = MarketplaceManagement::record($type, $publicId, true);
            abort_unless(MarketplaceManagement::mayEdit($record), 409);
            $ownerId = MarketplaceManagement::ownerId($record);
            abort_unless($ownerId === (int) $expected['expected_owner']
                && hash_equals(MarketplaceManagement::revision($record), $expected['expected_revision']), 409,
                'This listing changed while the form was open. Reopen it before saving.');

            $request->merge(['builder_mode' => '1', 'status' => 'draft']);
            if ($request->has('featured_media_id') && !$request->has('featured_kind')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['featured_kind' => 'Choose a featured media type.']);
            }
            if ($type === 'product') {
                $data = app(ProductController::class)->validated($request, $ownerId, $record);
                unset($data['status']);
            } else {
                $data = $this->managedListingData($request, $record, $type, $ownerId);
            }
            // Preserve publication, identity, ownership and the creator's original acceptance.
            $record->update($data);
            AuditEvent::record($type === 'job' ? 'job.manager.updated' : 'listing.manager.updated', $request->user(), $request);

            return $record;
        }, 3);

        return redirect(MarketplaceManagement::builderUrl($record))->with('status', 'Listing changes saved.');
    }

    private function managedListingData(Request $request, MarketplaceJob|MarketplaceListing $record, string $type, int $ownerId): array
    {
        BuilderContent::prepare($request);
        $isJob = $record instanceof MarketplaceJob;
        $amount = ['nullable', 'numeric', 'min:0', 'max:9999999999.99'];
        $data = $request->validate([
            'title' => ['required', 'string', 'min:'.($isJob ? '8' : '4'), 'max:180'],
            'description' => ['required', 'string', 'min:'.($isJob ? '70' : '40'), 'max:10000'],
            'description_html' => ['nullable', 'string', 'max:60000'],
            ...BuilderFeaturedMedia::rules(),
            ...BuilderGallery::rules(),
            ...($isJob ? [
                'category' => ['required', Rule::in(array_keys(BambodiaTaxonomy::jobEditorChoices($record->category)))],
                'budget' => $amount,
            ] : [
                'category_id' => ['nullable', 'integer', Rule::exists('marketplace_categories', 'id')
                    ->where('type', $type)->where('is_active', true)],
                'price' => $amount,
                'license_terms' => ['nullable', 'string', 'max:20000'],
            ]),
        ]);
        BuilderFeaturedMedia::check($data, $ownerId, $record->featured_media_id);
        BuilderGallery::check($data, $request, $ownerId, $record->gallery_media_ids ?? []);

        return $data;
    }

    private function allowedTypes(Request $request): array
    {
        return \App\Support\BuilderAccess::allowedTypes($request->user());
    }
}
