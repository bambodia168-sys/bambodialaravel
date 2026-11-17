<?php

namespace App\Http\Controllers;

use App\Models\CommercePaymentMethod;
use App\Models\AuditEvent;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\User;
use App\Support\PreviewContentReview;
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

        return response()->view('commerce.show', compact('order', 'section', 'workspace', 'methods'))
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
        $quotes = collect($record->type === 'product' ? ['regular', 'extended'] : ['standard'])
            ->mapWithKeys(fn (string $tier) => [$tier => $this->quote($record, $tier)])->filter();
        abort_if($quotes->isEmpty(), 404);
        $methods = CommercePaymentMethod::query()->where('available', true)->orderBy('name')->get();
        $requestKey = (string) Str::uuid();

        return response()->view('commerce.create', compact('record', 'quotes', 'methods', 'requestKey'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $listing): RedirectResponse
    {
        abort_unless(config('commerce.enabled'), 404);
        $data = $request->validate([
            'tier' => ['required', Rule::in(['regular', 'extended', 'standard'])],
            'request_key' => ['required', 'uuid'],
            'quote' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'payment_method' => ['nullable', 'integer', 'min:1'],
        ]);

        $order = DB::transaction(function () use ($request, $listing, $data): MarketplaceOrder {
            $buyer = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($buyer->account_status === 'active', 403);
            $existing = MarketplaceOrder::query()->where('buyer_id', $buyer->id)
                ->where('request_key', $data['request_key'])->first();
            if ($existing) {
                $matches = MarketplaceListing::query()->whereKey($existing->marketplace_listing_id)
                    ->where('public_id', $listing)->exists();
                abort_unless($matches && ($existing->license_tier ?? 'standard') === $data['tier'], 409);

                return $existing;
            }

            $record = $this->availableListing($listing, lock: true);
            abort_if((int) $record->user_id === (int) $buyer->id, 403);
            $quote = $this->quote($record, $data['tier']);
            abort_unless($quote && hash_equals($quote['signature'], $data['quote']), 409, 'The listing price or terms changed. Reopen the order form.');
            $noPaymentDue = $quote['total'] === '0.00';
            $method = $noPaymentDue ? null : $this->availableMethod($data['payment_method'] ?? null);

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
        abort_if(PreviewContentReview::isPreviewTitle($listing->title) || $listing->creator?->account_status !== 'active', 404);

        return $listing;
    }

    private function availableMethod(?int $id): ?CommercePaymentMethod
    {
        return $id === null ? null
            : CommercePaymentMethod::query()->where('available', true)->lockForUpdate()->findOrFail($id);
    }

    private function quote(MarketplaceListing $listing, string $tier): ?array
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
