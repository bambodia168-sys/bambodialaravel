<?php

namespace App\Support;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class VerifiedTrackingOrder
{
    public static function resolve(Request $request, string $reference, bool $sellerOnly = false): MarketplaceOrder
    {
        abort_unless(config('commerce.enabled'), 404);
        $userId = (int) $request->user()->id;
        $order = MarketplaceOrder::query()
            ->where(fn (Builder $query) => $query->where('seller_id', $userId)
                ->orWhere('buyer_id', $userId))
            ->matchingReference($reference)
            ->where('status', 'completed')->whereHas('paidAttempt')
            ->whereIn('marketplace_listing_id', MarketplaceListing::query()->select('id')
                ->whereIn('type', ['service', 'project', 'freelance']))
            ->with('serviceInquiry')->firstOrFail();

        abort_unless($order->serviceInquiry
            && (int) $order->serviceInquiry->client_id === (int) $order->buyer_id
            && (int) $order->serviceInquiry->creator_id === (int) $order->seller_id
            && (int) $order->serviceInquiry->marketplace_listing_id === (int) $order->marketplace_listing_id
            && $order->serviceInquiry->hasVerifiedOrder(), 404);
        abort_if($sellerOnly && (int) $order->seller_id !== $userId, 403);

        return $order;
    }
}
