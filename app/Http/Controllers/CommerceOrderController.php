<?php

namespace App\Http\Controllers;

use App\Models\CommercePaymentMethod;
use App\Models\CommerceCart;
use App\Models\CommercePayment;
use App\Models\AuditEvent;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\User;
use App\Support\CommerceOffers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CommerceOrderController extends Controller
{
    public function index(Request $request): View
    {
        [$orders, $section, $workspace, $heading] = $this->workspace($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(MarketplaceOrder::STATUSES))],
            'q' => ['nullable', 'string', 'max:180'],
        ]);
        $status = $filters['status'] ?? null;
        $search = trim($filters['q'] ?? '');
        $orders = $orders->when($status, fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $match) => $match->where('number', $search)->orWhere('listing_title', 'like', '%'.$search.'%')
            ))
            ->latest('id')->paginate(10)->withQueryString();

        return view('commerce.orders', compact('orders', 'section', 'workspace', 'heading', 'status', 'search'));
    }

    public function show(Request $request, string $number): Response
    {
        [$orders, $section, $workspace] = $this->workspace($request);
        $order = $orders->where('number', $number)->firstOrFail();

        $methods = $section === 'account.orders' && $order->status === 'awaiting_payment'
            ? CommercePaymentMethod::query()->where('available', true)->orderBy('name')->get()
            : collect();

        $payments = config('commerce.payments_enabled') ? $order->payments()->latest('id')->get() : collect();

        return response()->view('commerce.show', compact('order', 'section', 'workspace', 'methods', 'payments'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function receipt(Request $request, string $number): Response
    {
        [$orders, $section, $workspace] = $this->workspace($request);
        $order = $orders->where('number', $number)->where('status', 'completed')
            ->whereNotNull('receipt_number')->firstOrFail();

        return response()->view('commerce.receipt', compact('order', 'section', 'workspace'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, string $listing): Response
    {
        abort_unless(config('commerce.enabled'), 404);
        $record = $this->availableListing($listing);
        abort_if((int) $record->user_id === (int) $request->user()->id, 403);
        $quotes = CommerceOffers::quotes($record);
        abort_if($quotes === [], 404);
        $methods = CommercePaymentMethod::query()->where('available', true)->orderBy('name')->get();
        $requestKey = (string) Str::uuid();

        return response()->view('commerce.create', compact('record', 'quotes', 'methods', 'requestKey'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $listing): RedirectResponse
    {
        return $this->place($request, $listing, false);
    }

    public function placeFromCart(Request $request, string $listing): RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('checkout.verification');
        }

        return $this->place($request, $listing, true);
    }

    private function place(Request $request, string $listing, bool $fromCart): RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        $data = $request->validate([
            'tier' => ['required', Rule::in(['regular', 'extended', 'standard'])],
            'request_key' => ['required', 'uuid'],
            'quote' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'payment_method' => ['nullable', 'integer', 'min:1'],
        ]);

        $order = DB::transaction(function () use ($request, $listing, $data, $fromCart): MarketplaceOrder {
            $buyer = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($buyer->account_status === 'active' && (! $fromCart || $buyer->hasVerifiedEmail()), 403);
            $existing = MarketplaceOrder::query()->where('buyer_id', $buyer->id)
                ->where('request_key', $data['request_key'])->first();
            if ($existing) {
                $matches = MarketplaceListing::query()->whereKey($existing->marketplace_listing_id)
                    ->where('public_id', $listing)->exists();
                abort_unless($matches && ($existing->license_tier ?? 'standard') === $data['tier'], 409);

                return $existing;
            }

            $cart = $fromCart ? CommerceCart::query()->whereKey($buyer->id)->lockForUpdate()->first() : null;
            $items = $cart?->items ?? [];
            if ($fromCart) {
                $line = $items[$listing] ?? null;
                abort_unless($line && $line['request_key'] === $data['request_key']
                    && $line['tier'] === $data['tier'], 409, 'The cart changed. Review checkout again.');
            }

            $record = $this->availableListing($listing, lock: true);
            abort_if((int) $record->user_id === (int) $buyer->id, 403);
            $quote = CommerceOffers::quote($record, $data['tier']);
            abort_unless($quote && hash_equals($quote['signature'], $data['quote']), 409, 'The listing price or terms changed. Reopen the order form.');
            $noPaymentDue = $quote['total'] === '0.00';
            if ($fromCart && ! $noPaymentDue) {
                abort_unless(config('commerce.payments_enabled') && $quote['currency'] === 'USD'
                    && CommercePaymentMethod::query()->where('available', true)->get()
                        ->contains(fn (CommercePaymentMethod $method) => $method->availableForCheckout((bool) config('commerce.live_gateways'))),
                    422, 'Payment is not available for this item.');
            }
            $method = ($noPaymentDue || $fromCart) ? null : $this->availableMethod($data['payment_method'] ?? null);

            $order = MarketplaceOrder::query()->create([
                'number' => (string) Str::uuid(),
                'buyer_id' => $buyer->id,
                'seller_id' => $record->user_id,
                'marketplace_listing_id' => $record->id,
                'request_key' => $data['request_key'],
                'listing_title' => $record->title,
                'buyer_name' => $buyer->name,
                'seller_name' => $record->creator->name,
                'license_tier' => $record->type === 'product' ? $data['tier'] : null,
                'license_name' => $quote['license'],
                'total' => $quote['total'],
                'currency' => $quote['currency'],
                'payment_method' => $method?->name,
                'status' => $noPaymentDue ? 'completed' : 'awaiting_payment',
                'receipt_number' => $noPaymentDue ? (string) Str::uuid() : null,
                'completed_at' => $noPaymentDue ? now() : null,
            ]);
            if ($cart) {
                unset($items[$listing]);
                $cart->update(['items' => $items]);
            }
            AuditEvent::record('commerce.order.created', $buyer, $request);

            return $order;
        }, 3);

        return redirect()->route('account.orders.show', $order->number)->with('status', 'Order recorded.');
    }

    public function cancel(Request $request, string $number): RedirectResponse
    {
        [$orders] = $this->workspace($request);
        DB::transaction(function () use ($orders, $number, $request): void {
            $order = $orders->where('number', $number)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'cancelled') {
                abort_unless($order->status === 'awaiting_payment', 409);
                abort_if($order->payments()->whereIn('status', CommercePayment::ACTIVE)->exists(), 409, 'An existing payment is still open.');
                $order->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                AuditEvent::record('commerce.order.cancelled', $request->user(), $request);
            }
        }, 3);

        return redirect()->route('account.orders.show', $number)->with('status', 'Order cancelled.');
    }

    public function updatePaymentMethod(Request $request, string $number): RedirectResponse
    {
        [$orders] = $this->workspace($request);
        $data = $request->validate(['payment_method' => ['present', 'nullable', 'integer', 'min:1']]);

        DB::transaction(function () use ($orders, $number, $data, $request): void {
            $order = $orders->where('number', $number)->lockForUpdate()->firstOrFail();
            abort_unless($order->status === 'awaiting_payment', 409);
            abort_if($order->payments()->whereIn('status', CommercePayment::ACTIVE)->exists(), 409, 'An existing payment is still open.');
            $method = $this->availableMethod($data['payment_method'] ?? null);
            $order->update(['payment_method' => $method?->name]);
            if ($order->wasChanged('payment_method')) {
                AuditEvent::record('commerce.order.payment_method_changed', $request->user(), $request);
            }
        }, 3);

        return redirect()->route('account.orders.show', $number)->with('status', 'Payment preference updated.');
    }

    private function workspace(Request $request): array
    {
        abort_unless(config('commerce.enabled'), 404);
        $orders = MarketplaceOrder::query();
        $user = $request->user();
        $route = (string) $request->route()->getName();
        if (str_contains($route, 'dashboard.orders.')) {
            return [$orders, 'dashboard.orders', 'admin', 'Marketplace Orders'];
        }
        if (str_contains($route, 'studio.orders.')) {
            return [$orders->where('seller_id', $user->id), 'studio.orders', 'creator', 'Sales Orders'];
        }

        return [$orders->where('buyer_id', $user->id), 'account.orders', 'account', 'Order History'];
    }

    private function availableListing(string $publicId, bool $lock = false): MarketplaceListing
    {
        $listing = MarketplaceListing::query()->publiclyAvailable()
            ->where('public_id', $publicId)->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->firstOrFail();
        $listing->load(['creator', 'licenseDefinition', 'extendedLicenseDefinition']);
        abort_unless(CommerceOffers::eligible($listing), 404);

        return $listing;
    }

    private function availableMethod(?int $id): ?CommercePaymentMethod
    {
        return $id === null ? null
            : CommercePaymentMethod::query()->where('available', true)->lockForUpdate()->findOrFail($id);
    }
}
