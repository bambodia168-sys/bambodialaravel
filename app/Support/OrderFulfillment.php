<?php

namespace App\Support;

use App\Models\AccountNotification;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\ProductFileVersion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class OrderFulfillment
{
    private const BUYER_ROLES = ['customer', 'subscriber', 'freelancer'];

    private const SERVICE_TYPES = ['service', 'freelance', 'project'];

    public static function snapshot(?LicenseDefinition $definition, MarketplaceListing $listing, User $buyer): array
    {
        if (! $definition || ! in_array($buyer->role, self::BUYER_ROLES, true)) {
            return [];
        }

        $version = ProductFileVersion::query()->where('marketplace_listing_id', $listing->id)
            ->where('status', 'approved')->latest('reviewed_at')->latest('id')->cursor()
            ->first(fn (ProductFileVersion $version) => self::usableVersion($version));

        return $version ? [
            'fulfillment_version_id' => $version->id,
            'fulfillment_definition_id' => $definition->id,
            'fulfillment_terms_hash' => self::termsHash($definition),
        ] : [];
    }

    public static function ensureOfferReady(MarketplaceListing $listing, array $snapshot): void
    {
        abort_if($snapshot === [], 409, 'This product is not ready for purchase. Its approved download or license is unavailable.');
        try {
            ProductReadiness::assertListing($listing);
        } catch (ValidationException) {
            abort(409, 'The product needs an approved license and an available cover before purchase.');
        }
    }

    public static function ensureReady(MarketplaceOrder $order): void
    {
        $listing = MarketplaceListing::query()->findOrFail($order->marketplace_listing_id);
        abort_unless((int) $listing->user_id === (int) $order->seller_id, 409, 'The seller on this order has changed.');
        if ($order->license_tier === null) {
            abort_unless(in_array($listing->type, self::SERVICE_TYPES, true), 409, 'Service access is unavailable for this order.');

            return;
        }

        $buyer = User::query()->find($order->buyer_id);
        $definition = LicenseDefinition::query()->find($order->fulfillment_definition_id);
        $version = ProductFileVersion::query()->find($order->fulfillment_version_id);
        $listingDefinitionId = $order->license_tier === 'extended'
            ? $listing->extended_license_definition_id : $listing->license_definition_id;

        abort_unless($buyer?->account_status === 'active' && in_array($buyer->role, self::BUYER_ROLES, true)
            && $listing->type === 'product' && $listing->status === 'published'
            && $version && (int) $version->marketplace_listing_id === (int) $listing->id && self::usableVersion($version)
            && $definition?->status === 'active' && (int) $definition->id === (int) $listingDefinitionId
            && $definition->allowsTier($order->license_tier)
            && hash_equals((string) $order->fulfillment_terms_hash, self::termsHash($definition)),
            409, 'The approved file or license for this order is unavailable. Review the product before placing a new order.');
        try {
            ProductReadiness::assertListing($listing);
        } catch (ValidationException) {
            abort(409, 'The listing needs an approved license and an available cover before fulfillment.');
        }
    }

    public static function deliverApprovedTransfer(MarketplaceOrder $order): void
    {
        abort_unless($order->status === 'completed' && $order->paidAttempt()->exists(), 409);

        if ($order->license_tier !== null) {
            $grant = $order->entitlement()->create([
                'manual_slot' => null,
                'user_id' => $order->buyer_id,
                'product_file_version_id' => $order->fulfillment_version_id,
                'granted_by' => $order->seller_id,
                'grant_reason' => 'Verified purchase for order '.$order->number,
                'status' => 'active',
            ]);
            ProductLicenseLifecycle::issuePurchase($grant, $order);

            return;
        }

        $inquiry = $order->serviceInquiry()->create([
            'marketplace_listing_id' => $order->marketplace_listing_id,
            'client_id' => $order->buyer_id,
            'creator_id' => $order->seller_id,
            'subject' => mb_substr($order->listing_title, 0, 180),
            'currency' => $order->currency,
            'status' => 'open',
        ]);
        AccountNotification::deliver((int) $order->seller_id, 'commerce.service.purchased', 'New service purchase',
            'Open the private conversation to coordinate delivery with the buyer.', route('inquiries.show', $inquiry, false));
    }

    private static function usableVersion(ProductFileVersion $version): bool
    {
        return $version->isApproved()
            && ProductVault::verifiedPath($version->storage_path, $version->sha256, (int) $version->byte_size) !== null;
    }

    private static function termsHash(LicenseDefinition $definition): string
    {
        return hash('sha256', json_encode([
            $definition->id, $definition->name, $definition->tier, $definition->version,
            $definition->permissions, $definition->restrictions,
        ], JSON_THROW_ON_ERROR));
    }
}
