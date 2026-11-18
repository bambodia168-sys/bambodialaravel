<?php

namespace App\Support;

use App\Models\MarketplaceListing;

final class CommerceOffers
{
    public static function eligible(MarketplaceListing $listing, ?int $buyer = null): bool
    {
        return $listing->creator?->account_status === 'active'
            && ($buyer === null || (int) $listing->user_id !== $buyer)
            && ! PreviewContentReview::isPreviewTitle($listing->title);
    }

    public static function quotes(MarketplaceListing $listing): array
    {
        $tiers = $listing->type === 'product' ? ['regular', 'extended'] : ['standard'];
        $quotes = [];
        foreach ($tiers as $tier) {
            if ($quote = self::quote($listing, $tier)) {
                $quotes[$tier] = $quote;
            }
        }

        return $quotes;
    }

    public static function quote(MarketplaceListing $listing, string $tier): ?array
    {
        $license = null;
        if ($listing->type === 'product') {
            $license = match ($tier) {
                'regular' => $listing->licenseDefinition,
                'extended' => $listing->extendedLicenseDefinition,
                default => null,
            };
            if ($license?->status !== 'active' || ! $license->allowsTier($tier)) {
                return null;
            }
            $total = $tier === 'regular' ? ($listing->regular_price ?? $listing->price) : $listing->extended_price;
        } else {
            $total = $tier === 'standard' ? $listing->price : null;
        }

        if ($total === null || ! preg_match('/^\d{1,10}\.\d{2}$/D', $total)) {
            return null;
        }
        $currency = $listing->currency;
        $signature = hash('sha256', json_encode([
            $listing->id, $listing->user_id, $listing->title, $tier, $license?->id, $license?->name,
            $license?->version, $license?->permissions, $license?->restrictions, $total, $currency,
        ], JSON_THROW_ON_ERROR));

        return ['total' => $total, 'currency' => $currency, 'license' => $license?->name, 'signature' => $signature];
    }
}
