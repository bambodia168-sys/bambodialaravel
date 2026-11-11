<?php

namespace App\Support;

use App\Models\FeatureFlag;
use App\Models\MarketplaceListing;
use App\Models\User;

final class BuilderAccess
{
    public const TYPES = ['product', 'project', 'service', 'freelance', 'job'];

    public static function allowedTypes(User $user): array
    {
        $allowed = [];
        if (in_array($user->role, ['freelancer', 'super_admin'], true)
            && FeatureFlag::isEnabled('creator_submissions')) {
            $allowed = MarketplaceListing::TYPES;
        }
        if (in_array($user->role, ['customer', 'subscriber', 'super_admin'], true)) {
            $allowed[] = 'job';
        }

        return $allowed;
    }

    public static function assertEditable(User $user, string $type, ?string $reference): void
    {
        abort_unless(in_array($type, self::allowedTypes($user), true), 403);

        if ($reference === null) {
            return;
        }

        if ($type === 'job') {
            $job = \App\Models\MarketplaceJob::query()
                ->where('public_id', $reference)
                ->where('client_id', $user->getKey())
                ->firstOrFail();
            abort_unless(in_array($job->status, ['pending', 'rejected'], true), 409);
            return;
        }

        $listing = MarketplaceListing::query()
            ->where('public_id', $reference)
            ->where('user_id', $user->getKey())
            ->where('type', $type)
            ->firstOrFail();
        abort_unless(in_array($listing->status, ['draft','rejected'], true), 409);
    }
}
