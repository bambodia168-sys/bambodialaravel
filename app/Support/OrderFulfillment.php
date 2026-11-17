<?php

namespace App\Support;

use App\Models\AccountNotification;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\ProductEntitlement;
use App\Models\ProductFileVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class OrderFulfillment
{
    public const BUYER_ROLES = ['customer', 'subscriber', 'freelancer'];

    public static function availableRelease(MarketplaceListing $listing): ?ProductFileVersion
    {
        $versions = ProductFileVersion::query()->where('marketplace_listing_id', $listing->id)
            ->where('status', 'approved')->latest('reviewed_at')->latest('id')->cursor();

        return $versions->first(fn (ProductFileVersion $version) => $version->isApproved()
            && Storage::disk('local')->exists($version->storage_path));
    }

    public static function termsHash(LicenseDefinition $definition): string
    {
        return hash('sha256', json_encode([
            $definition->id, $definition->name, $definition->tier, $definition->version,
            $definition->permissions, $definition->restrictions,
        ], JSON_THROW_ON_ERROR));
    }

    public static function canDeliver(MarketplaceOrder $order): bool
    {
        $product = MarketplaceListing::query()->find($order->marketplace_listing_id);
        $version = ProductFileVersion::query()->find($order->fulfillment_version_id);
        $definition = LicenseDefinition::query()->find($order->fulfillment_definition_id);
        $selectedDefinition = $order->license_tier === 'extended'
            ? $product?->extended_license_definition_id : $product?->license_definition_id;

        return $product?->type === 'product' && $product->status === 'published' && CommerceOffers::eligible($product)
            && (int) $product->user_id === (int) $order->seller_id
            && $version && (int) $version->marketplace_listing_id === (int) $product->id && $version->isApproved()
            && $definition?->status === 'active' && (int) $definition->id === (int) $selectedDefinition
            && $definition->allowsTier($order->license_tier)
            && hash_equals((string) $order->fulfillment_terms_hash, self::termsHash($definition))
            && ProductVault::verifiedPath($version->storage_path, $version->sha256, (int) $version->byte_size) !== null;
    }

    public static function deliver(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            $order = MarketplaceOrder::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->status !== 'completed' || ! $order->fulfillment_version_id) {
                return false;
            }
            if ($order->entitlement()->exists()) {
                return true;
            }
            if ($order->total !== '0.00' && ! $order->paidAttempt()->exists()) {
                return false;
            }
            $buyer = User::query()->find($order->buyer_id);
            if (! $buyer?->hasVerifiedEmail() || $buyer->account_status !== 'active'
                || ! in_array($buyer->role, self::BUYER_ROLES, true) || ! self::canDeliver($order)) {
                return false;
            }

            $grant = ProductEntitlement::query()->create([
                'marketplace_order_id' => $order->id,
                'manual_slot' => null,
                'user_id' => $order->buyer_id,
                'product_file_version_id' => $order->fulfillment_version_id,
                'granted_by' => $order->seller_id,
                'grant_reason' => 'Automatic delivery for order '.$order->number,
                'status' => 'active',
            ]);
            ProductLicenseLifecycle::issuePurchase($grant, $order);
            AccountNotification::deliver((int) $order->buyer_id, 'license.issued', 'Your purchase is ready',
                'Open your downloads and licenses to use the purchased product.', '/account/downloads');

            return true;
        }, 3);
    }

    public static function afterCommit(MarketplaceOrder $order): void
    {
        if ($order->fulfillment_version_id === null) {
            return;
        }

        DB::afterCommit(function () use ($order): void {
            try {
                self::deliver($order->id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
