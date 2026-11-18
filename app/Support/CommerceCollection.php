<?php

namespace App\Support;

use App\Models\FeatureFlag;

final class CommerceCollection
{
    public const FLAG = 'commerce_payment_collection';

    public static function enabled(): bool
    {
        return config('commerce.enabled') && (bool) (
            FeatureFlag::query()->where('key', self::FLAG)->value('enabled')
            ?? config('commerce.payments_enabled')
        );
    }
}
