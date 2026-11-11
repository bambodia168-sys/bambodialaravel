<?php

namespace App\Support;

use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only dependency inventory before an administrator reviews withdrawal.
 *
 * This deliberately has NO mutation method, no private user identifiers in
 * its output, and cannot determine that a listing is legally safe to remove.
 */
final class PreviewContentReview
{
    private const LIMIT = 50;

    public static function inspect(): array
    {
        $records = [];
        $totals = ['listings' => 0, 'jobs' => 0];
        $blocked = 0;
        $mediaReview = 0;

        $listings = MarketplaceListing::query()->publiclyAvailable()
            ->select([
                'id', 'public_id', 'title', 'type', 'user_id', 'status',
                'cover_media_id', 'featured_media_id', 'gallery_media_ids', 'description', 'updated_at',
            ])->orderBy('id');

        self::scan($listings, 'listing', $records, $totals, $blocked, $mediaReview);

        $jobs = MarketplaceJob::query()->where('status', 'published')
            ->select([
                'id', 'public_id', 'title', 'client_id', 'status',
                'featured_media_id', 'gallery_media_ids', 'description', 'updated_at',
            ])->orderBy('id');

        self::scan($jobs, 'job', $records, $totals, $blocked, $mediaReview);

        return [
            'schema_version' => 1,
            'read_only' => true,
            'operation' => 'review_only',
            'status' => array_sum($totals) > 0 ? 'manual_review_required' : 'no_preview_markers',
            'counts' => [
                'public_preview_listings' => $totals['listings'],
                'published_preview_jobs' => $totals['jobs'],
                'customer_activity_blocked' => $blocked,
                'media_review_required' => $mediaReview,
            ],
            'sample_limit' => self::LIMIT,
            'sample_truncated' => array_sum($totals) > count($records),
            'records' => $records,
            'next_action' => 'Review ownership, attachments, saved items, proposals, contracts and customer activity. No status changes are authorized by this report.',
        ];
    }

    /**
     * Snapshot one marked record, including withdrawn records for restoration.
     * The caller must own authorization; this method never writes.
     */
    public static function record(string $kind, int $id): array
    {
        if ($kind === 'listing') {
            $record = MarketplaceListing::query()->findOrFail($id);
            abort_unless(in_array($record->status, ['published', 'withdrawn'], true)
                && self::isPreviewTitle((string) $record->title), 404);

            return self::listingReport($record);
        }

        abort_unless($kind === 'job', 404);
        $record = MarketplaceJob::query()->findOrFail($id);
        abort_unless(in_array($record->status, ['published', 'withdrawn'], true)
            && self::isPreviewTitle((string) $record->title), 404);

        return self::jobReport($record);
    }

    public static function isPreviewTitle(string $title): bool
    {
        return preg_match('/\[\s*(?:LAYOUT TEST|DEMO|SAMPLE)\s*\]|\bPREVIEW ONLY\b/iu', $title) === 1;
    }

    private static function scan(
        Builder $query,
        string $kind,
        array &$records,
        array &$totals,
        int &$blocked,
        int &$mediaReview
    ): void {
        $query->chunkById(200, static function (Collection $items) use (
            $kind, &$records, &$totals, &$blocked, &$mediaReview
        ): void {
            foreach ($items as $item) {
                if (! self::isPreviewTitle((string) $item->title)) {
                    continue;
                }

                $totals[$kind === 'listing' ? 'listings' : 'jobs']++;
                $report = $kind === 'listing'
                    ? self::listingReport($item)
                    : self::jobReport($item);

                if ($report['customer_activity_blocked']) {
                    $blocked++;
                }
                if ($report['attachments_present']) {
                    $mediaReview++;
                }
                if (count($records) < self::LIMIT) {
                    $records[] = $report;
                }
            }
        }, 'id');
    }

    private static function listingReport(MarketplaceListing $listing): array
    {
        $id = (int) $listing->getKey();
        $fileVersions = DB::table('product_file_versions')
            ->where('marketplace_listing_id', $id)
            ->select('id');

        $related = [
            'saved_by_accounts' => DB::table('saved_listings')->where('marketplace_listing_id', $id)->count(),
            'service_inquiries' => DB::table('service_inquiries')->where('marketplace_listing_id', $id)->count(),
            'comments' => self::subjectCount('detail_comments', 'listing', $id),
            'support_tickets' => self::contextCount('support_tickets', 'listing', $id),
            'support_resources' => self::subjectCount('detail_support_resources', 'listing', $id),
            'product_file_versions' => DB::table('product_file_versions')->where('marketplace_listing_id', $id)->count(),
            'product_entitlements' => DB::table('product_entitlements')
                ->whereIn('product_file_version_id', $fileVersions)->count(),
            'product_licenses' => DB::table('product_licenses')->where('marketplace_listing_id', $id)->count(),
            'product_downloads' => DB::table('product_download_events')
                ->whereIn('product_file_version_id', $fileVersions)->count(),
        ];

        $mediaReferences = self::mediaCount(
            $listing->featured_media_id,
            $listing->cover_media_id,
            $listing->gallery_media_ids
        );

        $activityKeys = [
            'saved_by_accounts', 'service_inquiries', 'comments', 'support_tickets',
            'support_resources', 'product_entitlements', 'product_licenses', 'product_downloads',
        ];

        return self::baseReport(
            'listing', $listing, (int) $listing->user_id,
            $related, $activityKeys, $mediaReferences > 0 || $related['product_file_versions'] > 0,
            $mediaReferences
        );
    }

    private static function jobReport(MarketplaceJob $job): array
    {
        $id = (int) $job->getKey();
        $related = [
            'saved_by_accounts' => DB::table('saved_jobs')->where('marketplace_job_id', $id)->count(),
            'proposals' => DB::table('job_proposals')->where('marketplace_job_id', $id)->count(),
            'contracts' => DB::table('freelance_contracts')->whereIn(
                'job_proposal_id',
                DB::table('job_proposals')->select('id')->where('marketplace_job_id', $id)
            )->count(),
            'comments' => self::subjectCount('detail_comments', 'job', $id),
            'support_tickets' => self::contextCount('support_tickets', 'job', $id),
            'support_resources' => self::subjectCount('detail_support_resources', 'job', $id),
        ];

        $mediaReferences = self::mediaCount($job->featured_media_id, null, $job->gallery_media_ids);

        return self::baseReport(
            'job', $job, (int) $job->client_id,
            $related, array_keys($related), $mediaReferences > 0,
            $mediaReferences
        );
    }

    private static function baseReport(
        string $kind,
        MarketplaceListing|MarketplaceJob $record,
        int $ownerId,
        array $related,
        array $activityKeys,
        bool $attachments,
        int $mediaReferences
    ): array {
        $owner = DB::table('users')->where('id', $ownerId)->first(['id', 'role']);
        $blocked = false;

        foreach ($activityKeys as $key) {
            if ($related[$key] > 0) {
                $blocked = true;
                break;
            }
        }

        $review = ! $owner ? 'owner_missing'
            : ($blocked ? 'customer_activity_blocked'
                : ($attachments ? 'media_or_rights_review' : 'ownership_confirmation_required'));

        // Fingerprint permits a future, separately approved moderation flow to
        // detect changed records. It does not authorize publication changes.
        $fingerprint = hash('sha256', json_encode([
            $kind, $record->getKey(), $record->public_id, $record->title,
            $record->status, $record->updated_at?->toISOString(), $ownerId,
            $owner?->role, hash('sha256', (string) $record->description),
            $record->featured_media_id, $record->cover_media_id ?? null,
            $record->gallery_media_ids, $related, $mediaReferences,
        ], JSON_THROW_ON_ERROR));

        return [
            'kind' => $kind,
            'public_id' => (string) $record->public_id,
            'title' => (string) $record->title,
            'published_status' => $record->status,
            'owner_exists' => $owner !== null,
            'owner_role' => $owner?->role,
            'related_counts' => $related,
            'attached_media_references' => $mediaReferences,
            'attachments_present' => $attachments,
            'customer_activity_blocked' => $blocked,
            'review_status' => $review,
            'review_fingerprint' => $fingerprint,
            'auto_unpublish_allowed' => false,
        ];
    }

    private static function subjectCount(string $table, string $kind, int $id): int
    {
        return DB::table($table)->where('subject_type', $kind)->where('subject_id', $id)->count();
    }

    private static function contextCount(string $table, string $kind, int $id): int
    {
        return DB::table($table)->where('context_type', $kind)->where('context_id', $id)->count();
    }

    private static function mediaCount(mixed $featured, mixed $cover, mixed $gallery): int
    {
        // The report counts references only; it does not dereference private media.
        $ids = collect([$featured, $cover, ... (is_array($gallery) ? $gallery : [])])
            ->filter(static fn ($id) => is_scalar($id) && (string) $id !== '')
            ->map(static fn ($id) => (string) $id)
            ->unique();

        return $ids->count();
    }
}
