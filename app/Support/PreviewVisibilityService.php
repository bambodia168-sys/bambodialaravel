<?php

namespace App\Support;

use App\Models\AccountNotification;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\PreviewVisibilityAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One-record reversible visibility lifecycle; never a bulk cleanup tool.
 *
 * Disabled by default. Operator-provided evidence references are attestations;
 * automatic verification of a real filesystem/database backup is a separate
 * production release gate and is not claimed by this service.
 */
final class PreviewVisibilityService
{
    public function change(
        string $kind,
        string $publicId,
        string $action,
        array $input,
        User $actor
    ): void {
        abort_unless($actor->role === 'super_admin', 403);
        abort_unless(config('preview_visibility.enabled', false) === true, 403,
            'Preview visibility writes have not been enabled after host acceptance.');
        abort_unless(in_array($kind, ['listing', 'job'], true), 404);
        abort_unless(in_array($action, ['withdraw', 'restore'], true), 404);
        abort_unless($input['confirm_public_id'] === $publicId, 422,
            'Enter the exact public identifier to confirm this one-record action.');

        DB::transaction(function () use ($kind, $publicId, $action, $input, $actor): void {
            $record = ($kind === 'listing'
                ? MarketplaceListing::query()
                : MarketplaceJob::query())
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->firstOrFail();

            $before = (string) $record->status;
            if ($action === 'withdraw') {
                abort_unless($before === 'published', 409, 'Only published records can be withdrawn.');
            } else {
                abort_unless($before === 'withdrawn', 409, 'Only withdrawn records can be restored.');
            }

            $report = PreviewContentReview::record($kind, (int) $record->id);
            abort_unless(hash_equals(
                (string) $report['review_fingerprint'],
                (string) $input['expected_fingerprint']
            ), 409, 'The review snapshot is outdated. Review current dependencies again.');

            abort_unless($report['owner_exists'], 409, 'The owner must be verified.');
            abort_unless(PreviewContentReview::isPreviewTitle((string) $record->title), 409,
                'Only explicitly marked preview content is eligible.');

            if ($action === 'withdraw') {
                abort_if($report['customer_activity_blocked'], 409,
                    'Customer activity exists. The record needs separate resolution.');
                abort_if($report['attachments_present']
                    && empty($input['rights_review_reference']), 409,
                    'Media and rights evidence must be reviewed before withdrawal.');
                $after = 'withdrawn';
            } else {
                // Restoration never republishes immediately; the existing
                // editorial review queue must approve it again.
                $last = PreviewVisibilityAction::query()
                    ->where('subject_kind', $kind)
                    ->where('subject_id', $record->id)
                    ->latest('id')->first();
                abort_unless($last && $last->action === 'withdraw'
                    && $last->to_status === 'withdrawn', 409,
                    'No matching withdrawal audit event exists.');
                $after = 'pending';
            }

            $record->update(['status' => $after]);

            PreviewVisibilityAction::query()->create([
                'subject_kind' => $kind,
                'subject_id' => $record->id,
                'subject_public_id' => (string) $record->public_id,
                'actor_id' => $actor->id,
                'action' => $action === 'withdraw' ? 'withdraw' : 'restore',
                'from_status' => $before,
                'to_status' => $after,
                'review_fingerprint' => $report['review_fingerprint'],
                'owner_confirmation_reference' => $input['owner_confirmation_reference'],
                'backup_reference' => $input['backup_reference'],
                'rights_review_reference' => $input['rights_review_reference'] ?? null,
                'reason' => $input['reason'],
            ]);

            $ownerId = $kind === 'job' ? (int) $record->client_id : (int) $record->user_id;
            AccountNotification::deliver(
                $ownerId,
                $kind === 'job' ? 'job.reviewed' : 'listing.reviewed',
                $action === 'withdraw' ? 'Preview removed from public view' : 'Preview returned for review',
                $action === 'withdraw'
                    ? 'Your preview-marked content is no longer publicly listed. Its records and history are retained.'
                    : 'Your preview-marked content has returned to the pending moderation queue; it is not public.',
                $kind === 'job' ? '/my-jobs'
                    : ($record->type === 'product' ? '/studio/products' : '/studio/listings')
            );
        }, 3);
    }
}
