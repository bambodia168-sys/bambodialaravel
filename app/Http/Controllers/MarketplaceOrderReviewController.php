<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\MarketplaceOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceOrderReviewController extends Controller
{
    public function store(Request $request, string $number): RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:1500'],
        ]);

        DB::transaction(function () use ($request, $number, $data): void {
            $order = MarketplaceOrder::query()->where('number', $number)
                ->where('buyer_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($order->isReviewEligible(), 409);
            abort_if($order->review()->exists(), 409, 'This order already has a review.');
            $order->review()->create($data);
            AuditEvent::record('commerce.review.created', $request->user(), $request);
        }, 3);

        return redirect()->route('account.orders.show', $number)->with('status', 'Your verified purchase review was posted.');
    }

    public function visibility(Request $request, string $number): RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        $data = $request->validate(['visible' => ['required', 'boolean']]);

        DB::transaction(function () use ($request, $number, $data): void {
            $order = MarketplaceOrder::query()->where('number', $number)->lockForUpdate()->firstOrFail();
            $review = $order->review()->firstOrFail();
            $review->update(['hidden_at' => $data['visible'] ? null : ($review->hidden_at ?? now())]);
            if ($review->wasChanged('hidden_at')) {
                AuditEvent::record('commerce.review.visibility_changed', $request->user(), $request);
            }
        }, 3);

        return redirect()->route('dashboard.orders.show', $number)->with('status', 'Review visibility updated.');
    }
}
