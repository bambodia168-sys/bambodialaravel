<?php

namespace App\Support;

use App\Models\KnowledgeArticle;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only inventory of publicly exposed demo/preview markers and help coverage.
 *
 * No edits, policy publication, records from private roles, secrets, or
 * filesystem operations belong in this service.
 */
final class MarketplaceReadinessAudit
{
    private const SAMPLE_LIMIT = 25;

    public static function inspect(): array
    {
        $listings = MarketplaceListing::query()->publiclyAvailable();
        $jobs = MarketplaceJob::query()->where('status', 'published');

        $counts = [
            'public_listings' => (clone $listings)->count(),
            'published_jobs' => (clone $jobs)->count(),
            'published_knowledge_articles' => KnowledgeArticle::query()
                ->where('status', 'published')->count(),
            'draft_knowledge_articles' => KnowledgeArticle::query()
                ->where('status', 'draft')->count(),
        ];

        $samples = [];
        $marked = ['public_listings' => 0, 'published_jobs' => 0];

        self::scanTitles($listings, 'listing', 'public_listings', $samples, $marked);
        self::scanTitles($jobs, 'job', 'published_jobs', $samples, $marked);

        $notices = [];
        if ($counts['published_knowledge_articles'] === 0) {
            $notices[] = [
                'code' => 'knowledge_base_unpublished',
                'severity' => 'review',
                'message' => 'No Knowledge Base articles are published. Review draft content before publication.',
            ];
        }

        return [
            'schema_version' => 1,
            'status' => ($marked['public_listings'] > 0 || $marked['published_jobs'] > 0 || $notices !== [])
                ? 'review_required' : 'pass',
            'read_only' => true,
            'counts' => $counts,
            'preview_markers' => $marked,
            'sample_limit' => self::SAMPLE_LIMIT,
            'sample_truncated' => array_sum($marked) > count($samples),
            'samples' => $samples,
            'notices' => $notices,
        ];
    }

    /**
     * Process only public records in deterministic small chunks. The report's
     * sample is bounded even for a large database; counts remain complete.
     */
    private static function scanTitles(
        Builder $query,
        string $kind,
        string $countKey,
        array &$samples,
        array &$marked
    ): void {
        (clone $query)->select(['id', 'public_id', 'title'])
            ->orderBy('id')
            ->chunkById(200, static function (Collection $rows) use ($kind, $countKey, &$samples, &$marked): void {
                foreach ($rows as $row) {
                    $title = (string) $row->title;
                    if (preg_match('/\[\s*(?:LAYOUT TEST|DEMO|SAMPLE)\s*\]|\bPREVIEW ONLY\b/iu', $title) !== 1) {
                        continue;
                    }

                    $marked[$countKey]++;
                    if (count($samples) >= self::SAMPLE_LIMIT) {
                        continue;
                    }

                    $samples[] = [
                        'kind' => $kind,
                        'public_id' => (string) $row->public_id,
                        'title' => $title,
                        'reason' => 'explicit_preview_marker',
                    ];
                }
            }, 'id');
    }
}
