<?php

namespace App\Http\Controllers;

use App\Support\BuilderContent;
use App\Support\BuilderGallery;
use App\Support\BuilderSubmissionTerms;
use App\Support\BuilderFeaturedMedia;
use App\Models\AuditEvent;
use App\Models\FeatureFlag;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BuilderController extends Controller
{
    private const JOB_CATEGORIES = ['development', 'design', 'writing', 'marketing', 'business', 'other'];

    public function show(Request $request): View
    {
        $allowedTypes = $this->allowedTypes($request);
        $raw = (string) $request->server('QUERY_STRING', '');
        $record = null;
        $type = null;

        if ($raw === '') {
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

        if ($type !== null) {
            abort_unless(in_array($type, $allowedTypes, true), 403);
        }

        $canEdit = $record === null
            || ($type === 'job'
                ? in_array($record->status, ['pending', 'rejected'], true)
                : in_array($record->status, ['draft', 'rejected'], true));

        $categories = $type !== null && $type !== 'job'
            ? MarketplaceCategory::query()->where('type', $type)->where('is_active', true)
                ->orderBy('sort_order')->orderBy('name')->get(['id', 'type', 'name'])
            : collect();
        $termsPublications = BuilderSubmissionTerms::publications();
        $termsSha256 = BuilderSubmissionTerms::digest($termsPublications);
        $suggestedTerms = BuilderSubmissionTerms::suggestedTerms($termsPublications);
        $licenses = $type === 'product'
            ? LicenseDefinition::query()->where('status', 'active')
                ->orderBy('name')->get(['id', 'name', 'version'])
            : collect();
        $assets = $type !== null
            ? MediaAsset::query()->where('user_id', $request->user()->id)
                ->whereIn('review_status', ['approved', 'pending'])->latest()
                ->get(['id', 'public_id', 'alt_text', 'mime_type', 'review_status'])
            : collect();

        $productVersions = $type === 'product' && $record
            ? \App\Models\ProductFileVersion::query()
                ->where('marketplace_listing_id', $record->id)->latest('id')->get()
            : collect();

        return view('builder.index', compact(
            'type', 'record', 'canEdit', 'allowedTypes', 'categories', 'licenses', 'assets',
            'termsPublications', 'termsSha256', 'suggestedTerms', 'productVersions'
        ));
    }

    public function previewContent(Request $request): JsonResponse
    {
        abort_unless(count($this->allowedTypes($request)) > 0, 403);
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

        BuilderContent::prepare($request);
        $termsHash = BuilderSubmissionTerms::requireAcceptance($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:8', 'max:180'],
            'description' => ['required', 'string', 'min:70', 'max:10000'],
            'description_html' => ['nullable', 'string', 'max:60000'],
            ...BuilderFeaturedMedia::rules(),
            ...BuilderGallery::rules(),
            'category' => ['required', Rule::in(self::JOB_CATEGORIES)],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        BuilderFeaturedMedia::check($data, (int)$request->user()->id);
        BuilderGallery::check($data, $request);
        DB::transaction(function () use ($request, $match, $data, $termsHash): void {
            $job = MarketplaceJob::query()->where('public_id', $match[1])
                ->lockForUpdate()->firstOrFail();
            abort_unless((int) $job->client_id === (int) $request->user()->id, 404);
            abort_unless(in_array($job->status, ['pending', 'rejected'], true), 409,
                'Only pending or rejected job posts can be edited.');
            $job->update([
                ...$data,
                'status' => 'pending',
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);
            BuilderSubmissionTerms::record($termsHash, (int)$request->user()->id, 'job', (int)$job->id);
            AuditEvent::record('job.updated', $request->user(), $request);
        });

        return redirect('/builder?='.$match[1])
            ->with('status', 'Job saved and queued for administrator review.');
    }

    private function allowedTypes(Request $request): array
    {
        $allowed = [];
        if (in_array($request->user()->role, ['freelancer', 'super_admin'], true)
            && FeatureFlag::isEnabled('creator_submissions')) {
            $allowed = MarketplaceListing::TYPES;
        }
        if (in_array($request->user()->role, ['customer', 'subscriber', 'super_admin'], true)) {
            $allowed[] = 'job';
        }

        return $allowed;
    }
}
