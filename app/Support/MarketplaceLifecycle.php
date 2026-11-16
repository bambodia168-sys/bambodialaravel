<?php

namespace App\Support;

use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\ProductEntitlement;
use App\Models\ProductLicense;
use Illuminate\Validation\ValidationException;

final class MarketplaceLifecycle
{
    public static function targets(MarketplaceJob|MarketplaceListing $record): array
    {
        return match ($record->status) {
            'draft' => ['archived', 'deleted'],
            'pending', 'in_review', 'published', 'rejected', 'closed' => ['draft', 'archived', 'deleted'],
            'archived' => ['draft', 'deleted'],
            default => [],
        };
    }

    public static function assertAvailable(MarketplaceJob|MarketplaceListing $record, string $target): void
    {
        abort_unless(in_array($target, self::targets($record), true), $record->status === 'deleted' ? 404 : 409);

        if ($record instanceof MarketplaceListing && $record->type === 'product' && self::hasCustomerAccess($record)) {
            throw ValidationException::withMessages([
                'listing' => 'This product has an existing customer license or download entitlement. Contact the Support Center to manage it.',
            ]);
        }
    }

    private static function hasCustomerAccess(MarketplaceListing $listing): bool
    {
        return ProductLicense::query()->where('marketplace_listing_id', $listing->id)
            ->whereIn('status', ['active', 'suspended'])->whereNull('revoked_at')
            ->whereHas('entitlement', fn ($query) => $query->where('status', 'active')->whereNull('revoked_at'))
            ->exists()
            || ProductEntitlement::query()->where('status', 'active')->whereNull('revoked_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('version', fn ($query) => $query->where('marketplace_listing_id', $listing->id))->exists();
    }
}
