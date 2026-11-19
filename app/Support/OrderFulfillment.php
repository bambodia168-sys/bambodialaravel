<?php

namespace App\Support;

use App\Models\AccountNotification;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\ProductEntitlement;
use App\Models\ProductFileVersion;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
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
        $blocker = self::readinessBlocker($order, verifyIntegrity: true);
        abort_if($blocker !== null, 409, $blocker);
    }

    public static function readinessBlocker(MarketplaceOrder $order, bool $verifyIntegrity = false): ?string
    {
        $listing = MarketplaceListing::query()->find($order->marketplace_listing_id);
        if (! $listing || (int) $listing->user_id !== (int) $order->seller_id) {
            return 'The original listing or seller on this order is unavailable.';
        }

        if ($order->license_tier === null) {
            return in_array($listing->type, self::SERVICE_TYPES, true)
                ? null : 'Service access is unavailable for this order.';
        }

        $buyer = User::query()->find($order->buyer_id);
        if ($buyer?->account_status !== 'active' || ! in_array($buyer->role, self::BUYER_ROLES, true)) {
            return 'The buyer account is ineligible for product delivery.';
        }
        if ($listing->type !== 'product' || $listing->status !== 'published') {
            return 'The original product must be published before delivery.';
        }

        $version = ProductFileVersion::query()->find($order->fulfillment_version_id);
        if (! $version || (int) $version->marketplace_listing_id !== (int) $listing->id || $version->status !== 'approved') {
            return 'The original release on this order is unavailable or no longer approved.';
        }
        if (! VaultReleaseGate::screened($version)) {
            return 'The original release needs a current clean malware scan.';
        }
        if (! VaultReleaseGate::rightsReviewed($version)) {
            return 'The original release needs a verified rights review.';
        }

        $definition = LicenseDefinition::query()->find($order->fulfillment_definition_id);
        $listingDefinitionId = $order->license_tier === 'extended'
            ? $listing->extended_license_definition_id : $listing->license_definition_id;

        if ($definition?->status !== 'active' || (int) $definition->id !== (int) $listingDefinitionId
            || ! $definition->allowsTier($order->license_tier)
            || ! hash_equals((string) $order->fulfillment_terms_hash, self::termsHash($definition))) {
            return 'The original license terms on this order are unavailable or have changed.';
        }

        $fileAvailable = $verifyIntegrity
            ? ProductVault::verifiedPath($version->storage_path, $version->sha256, (int) $version->byte_size) !== null
            : Storage::disk('local')->exists($version->storage_path);
        if (! $fileAvailable) {
            return 'The original file is missing or failed its integrity check.';
        }

        try {
            ProductReadiness::assertListing($listing);
        } catch (ValidationException) {
            return 'The listing needs an approved license and an available cover before fulfillment.';
        }

        return null;
    }

    public static function entitlementBlocker(MarketplaceOrder $order, ProductEntitlement $grant): ?string
    {
        if (! $grant->isActive()) {
            return 'The existing download entitlement is inactive. Review it before retrying.';
        }

        if ((int) $grant->user_id !== (int) $order->buyer_id
            || (int) $grant->product_file_version_id !== (int) $order->fulfillment_version_id) {
            return 'The existing download entitlement does not match the buyer and original release.';
        }

        return null;
    }

    public static function deliverVerifiedPurchase(MarketplaceOrder $order): void
    {
        abort_unless($order->status === 'completed' && $order->paidAttempt()->exists(), 409);

        if ($order->license_tier !== null) {
            $grant = $order->entitlement()->lockForUpdate()->first() ?? $order->entitlement()->create([
                'manual_slot' => null,
                'user_id' => $order->buyer_id,
                'product_file_version_id' => $order->fulfillment_version_id,
                'granted_by' => $order->seller_id,
                'grant_reason' => 'Verified purchase for order '.$order->number,
                'status' => 'active',
            ]);
            $blocker = self::entitlementBlocker($order, $grant);
            abort_if($blocker !== null, 409, $blocker);
            if (! $grant->license()->exists()) {
                ProductLicenseLifecycle::issuePurchase($grant, $order);
            }

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

    public static function notifyBuyer(MarketplaceOrder $order): void
    {
        $message = $order->license_tier === null
            ? 'Your service workspace is ready. Open your order to get started.'
            : 'Your purchase is ready. Open your order for the download and license details.';

        AccountNotification::deliver((int) $order->buyer_id, 'commerce.payment.approved', 'Purchase ready',
            $message, route('account.orders.show', $order->number, false));
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
