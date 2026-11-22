<?php

namespace App\Support;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class MarketplaceManagement
{
    public const REVIEW_STATUSES = ['pending', 'in_review'];

    public const STATUSES = [
        'draft' => 'Draft', 'pending' => 'Pending', 'in_review' => 'In Review',
        'published' => 'Published', 'rejected' => 'Rejected',
    ];

    public static function type(MarketplaceJob|MarketplaceListing $record): string
    {
        return $record instanceof MarketplaceJob ? 'job' : $record->type;
    }

    public static function record(string $type, string $publicId, bool $lock = false): MarketplaceJob|MarketplaceListing
    {
        $query = $type === 'job'
            ? MarketplaceJob::query()
            : MarketplaceListing::query()->where('type', $type);

        return $query->where('public_id', $publicId)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
    }

    public static function ownerId(MarketplaceJob|MarketplaceListing $record): int
    {
        return (int) ($record instanceof MarketplaceJob ? $record->client_id : $record->user_id);
    }

    public static function mediaIds(MarketplaceJob|MarketplaceListing $record): array
    {
        return array_filter([
            ...($record->gallery_media_ids ?? []),
            $record->featured_media_id,
            $record instanceof MarketplaceListing ? $record->cover_media_id : null,
        ]);
    }

    public static function revision(MarketplaceJob|MarketplaceListing $record): string
    {
        return hash('sha256', json_encode($record->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public static function indexUrl(string $type, array $filters = []): string
    {
        return match ($type) {
            'job' => route('dashboard.jobs.index', $filters),
            'product' => route('dashboard.products.index', $filters),
            default => route('dashboard.marketplace.index', ['type' => $type, ...$filters]),
        };
    }

    public static function builderUrl(MarketplaceJob|MarketplaceListing $record, bool $preview = false): string
    {
        return route('builder', [
            'type' => self::type($record), 'item' => $record->public_id,
            'mode' => $preview ? 'preview' : 'manage',
        ]);
    }

    public static function mayView(User $actor): bool
    {
        return $actor->account_status === 'active' && (in_array($actor->role, ['admin', 'super_admin'], true)
            || UserManagementAccess::allows($actor, 'marketplace_transfer'));
    }

    public static function mayEdit(MarketplaceJob|MarketplaceListing $record): bool
    {
        return in_array($record->status, ['draft', 'pending', 'in_review', 'rejected'], true);
    }

    public static function applyFilters(Builder $query, Request $request, string $type, array $statuses): array
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(array_keys($statuses))],
            'q' => ['nullable', 'string', 'max:120'], 'search' => ['nullable', 'string', 'max:120'],
            'owner' => ['nullable', 'integer', 'min:1'],
            'category' => $type === 'job' ? ['nullable', 'string', 'max:64'] : ['nullable', 'integer', 'min:1'],
        ]);
        $status = $data['status'] ?? 'all';
        $search = trim($data['q'] ?? $data['search'] ?? '');
        $owner = $data['owner'] ?? null;
        $category = $data['category'] ?? null;

        $query->when($status === 'review_queue', fn ($q) => $q->whereIn('status', self::REVIEW_STATUSES))
            ->when(!in_array($status, ['all', 'review_queue'], true), fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($match) => $match
                ->where('title', 'like', '%'.$search.'%')->orWhere('public_id', 'like', '%'.$search.'%')))
            ->when($owner !== null, fn ($q) => $q->where($type === 'job' ? 'client_id' : 'user_id', $owner))
            ->when($category !== null, fn ($q) => $q->where($type === 'job' ? 'category' : 'category_id', $category));

        return compact('type', 'status', 'search', 'owner', 'category', 'statuses');
    }

    public static function actions(MarketplaceJob|MarketplaceListing $record): array
    {
        $statuses = match ($record->status) {
            'draft' => ['pending', 'in_review'],
            'pending' => ['published', 'draft', 'in_review', 'rejected'],
            'in_review' => ['published', 'draft', 'pending', 'rejected'],
            'rejected' => ['draft', 'pending', 'in_review'],
            'published' => PreviewContentReview::isPreviewTitle($record->title) ? [] : ['draft'],
            default => [],
        };

        return array_intersect_key([
            'published' => 'Publish', 'draft' => 'Draft', 'in_review' => 'In Review',
            'pending' => 'Pending', 'rejected' => 'Rejected',
        ], array_flip($statuses));
    }

    public static function changeStatus(
        MarketplaceJob|MarketplaceListing $record, string $target, Request $request
    ): void {
        abort_unless(isset(self::actions($record)[$target]), 409, 'This status change is unavailable for the current record.');
        $isJob = $record instanceof MarketplaceJob;
        if ($target === 'published' && ! $isJob) {
            ProductReadiness::assertListing($record);
        }
        if ($record->status === 'published') {
            abort_if(PreviewContentReview::hasCustomerActivity($record), 409,
                'Active customer records must be resolved before this listing can return to draft.');
        }

        $wasAwaitingReview = in_array($record->status, self::REVIEW_STATUSES, true);
        $record->status = $target;
        $isDecision = in_array($target, ['published', 'rejected'], true);
        if ($isJob) {
            $record->reviewed_by = $isDecision || $target === 'in_review' ? $request->user()->id : null;
            $record->reviewed_at = $isDecision ? now() : null;
        }
        $record->save();

        $kind = $isJob ? 'job' : 'listing';
        AuditEvent::record($kind.'.review.'.$target, $request->user(), $request);
        if ($isDecision) {
            $isProduct = !$isJob && $record->type === 'product';
            AccountNotification::deliver(
                (int) ($isJob ? $record->client_id : $record->user_id),
                $kind.'.reviewed',
                $isJob ? 'Job review completed' : ($isProduct ? 'Digital product reviewed' : 'Marketplace listing reviewed'),
                mb_substr('Your submission “'.$record->title.'” was '.$target.' after review.', 0, 500),
                '/builder?='.$record->public_id,
                emailTemplate: $target === 'published' ? $kind.'.published' : null,
            );
        } elseif (!$wasAwaitingReview && in_array($target, self::REVIEW_STATUSES, true)) {
            SubmissionNotifications::send($record);
        }
    }
}
