<?php

namespace App\Support;

use App\Models\AccountNotification;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\User;

final class SubmissionNotifications
{
    public static function send(MarketplaceListing|MarketplaceJob $record): void
    {
        // Draft saves and already-published records are not new review submissions.
        if ($record->status !== 'pending') {
            return;
        }
        $ownerId = MarketplaceManagement::ownerId($record);
        $type = MarketplaceManagement::type($record);
        $label = $record instanceof MarketplaceJob ? 'Job' : 'Listing';

        AccountNotification::deliver(
            $ownerId,
            'marketplace.submission.received',
            $label.' submitted for review',
            '“'.$record->title.'” is waiting for a review.',
            '/builder?='.$record->public_id,
        );

        $reviewPath = route('builder', ['type' => $type, 'item' => $record->public_id, 'mode' => 'preview'], false);
        foreach (User::query()->whereIn('role', ['admin', 'super_admin'])->where('account_status', 'active')
            ->whereKeyNot($ownerId)->cursor() as $reviewer) {
            if (UserManagementAccess::allows($reviewer, 'marketplace_review')) {
                AccountNotification::deliver(
                    $reviewer->id,
                    'marketplace.review.requested',
                    $label.' ready for review',
                    'Review the submission “'.$record->title.'”.',
                    $reviewPath,
                );
            }
        }
    }
}
