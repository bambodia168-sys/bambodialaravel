<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceOrder;
use App\Support\CommerceOperations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CommerceOperationsController extends Controller
{
    public function show(Request $request, string $number): Response
    {
        abort_unless(CommerceOperations::enabled(), 404);
        $buyer = $request->routeIs('account.orders.refund', 'localized.*.account.orders.refund');
        $order = CommerceOperations::orders()->with(['operations', 'paidAttempt'])
            ->when($buyer, fn (Builder $orders) => $orders->where('buyer_id', $request->user()->id))
            ->where('number', $number)->firstOrFail();
        $actions = CommerceOperations::actions($order, $request->user(), $buyer);
        $events = $buyer ? collect() : $order->operations?->events()->with('actor:id,name')->get();
        $action = $request->validate(['action' => ['nullable', Rule::in($actions)]])['action'] ?? null;

        return response()->view('commerce.operations.show', compact('order', 'buyer', 'actions', 'action', 'events'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $number): RedirectResponse
    {
        abort_unless(CommerceOperations::enabled(), 404);
        $buyer = $request->routeIs('account.orders.refund.store', 'localized.*.account.orders.refund.store');
        $allowed = $buyer ? ['request_refund'] : array_diff(array_keys(CommerceOperations::LABELS), ['request_refund']);
        $action = $request->validate(['action' => ['required', Rule::in($allowed)]])['action'];
        $rules = ['note' => ['required', 'string', 'min:5', 'max:500']];
        if (! in_array($action, ['request_refund', 'reject_refund'], true)) {
            $rules += [
                'reference' => ['required', 'regex:/^[A-Za-z0-9_.:\/-]{3,120}$/D'],
                'amount' => ['required', 'regex:/^\d{1,10}\.\d{2}$/D'],
                'confirmed' => ['accepted'],
            ];
        }
        if ($action === 'reconcile') {
            $rules += [
                'payment_reference' => ['required', 'string', 'max:230'],
                'seller_payable' => ['required', 'regex:/^\d{1,10}\.\d{2}$/D'],
            ];
        }
        CommerceOperations::record($number, $request->user(), $action, $request->validate($rules), $buyer);

        return redirect()->route($buyer ? 'account.orders.refund' : 'dashboard.commerce-operations.show', $number)
            ->with('status', 'Order operation recorded.');
    }
}
