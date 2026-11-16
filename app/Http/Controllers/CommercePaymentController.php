<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\CommercePayment;
use App\Models\CommercePaymentMethod;
use App\Models\MarketplaceOrder;
use App\Support\CommerceGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class CommercePaymentController extends Controller
{
    public function __construct(private readonly CommerceGateway $gateway) {}

    public function start(Request $request, string $number): RedirectResponse
    {
        $order = $this->buyerOrder($request, $number);
        $data = $request->validate(['method'=>['required', 'integer', 'min:1']]);
        $live = (bool) config('commerce.live_gateways');
        $payment = DB::transaction(function () use ($order, $data, $live): CommercePayment {
            $locked = MarketplaceOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->awaiting($locked);
            $active = $locked->payments()->whereIn('status', CommercePayment::ACTIVE)->first();
            if ($active) {
                abort_unless($active->commerce_payment_method_id === (int) $data['method']
                    && in_array($active->status, ['starting', 'checkout_open'], true), 409, 'An existing payment is still open.');

                return $active;
            }
            $method = CommercePaymentMethod::query()->where('available', true)->lockForUpdate()->findOrFail($data['method']);
            abort_unless($method->automatic() && CommerceGateway::ready($method->provider, $live)
                && ($live || ! app()->environment('production')), 422, 'This gateway is unavailable.');

            return $locked->payments()->create([
                'number'=>(string) Str::uuid(), 'commerce_payment_method_id'=>$method->id,
                'method_name'=>$method->name, 'provider'=>$method->provider, 'live_mode'=>$live, 'status'=>'starting',
            ]);
        }, 3);
        abort_unless($payment->live_mode === $live && CommerceGateway::ready($payment->provider, $live)
            && ($live || ! app()->environment('production')), 503);
        if ($payment->remote_id) {
            $url = $this->reconcile($payment, false);
            if ($this->gateway->trustedCheckout($payment->provider, $live, $url)) {
                return redirect()->away($url);
            }

            return redirect()->route('account.orders.show', $number)->with('status', 'Payment status updated.');
        }

        // Never start another hosted checkout once the provider's idempotency window may have expired.
        abort_if($payment->created_at->lt(now()->subHour()), 409, 'This checkout needs settlement review.');
        [$remote, $url] = $this->gateway->start($payment);
        DB::transaction(function () use ($payment, $remote): void {
            $locked = CommercePayment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless($locked->remote_id === null || $locked->remote_id === $remote, 409);
            if ($locked->status === 'starting') {
                $locked->update(['remote_id'=>$remote, 'status'=>'checkout_open']);
            }
        }, 3);

        return redirect()->away($url);
    }

    public function returned(Request $request, string $number, string $payment): RedirectResponse
    {
        $this->buyerOrder($request, $number)->payments()->where('number', $payment)->whereNotNull('remote_id')->firstOrFail();

        return redirect()->route('account.orders.show', $number);
    }

    public function verify(Request $request, string $number, string $payment): RedirectResponse
    {
        $attempt = $this->buyerOrder($request, $number)->payments()
            ->where('number', $payment)->whereNotNull('remote_id')->firstOrFail();
        $this->reconcile($attempt, true);

        return redirect()->route('account.orders.show', $number)->with('status', 'Payment status updated.');
    }

    public function webhook(Request $request, string $provider): Response
    {
        $this->enabled();
        $remote = $this->gateway->webhook($request, $provider);
        if ($remote !== null) {
            abort_unless(preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $remote), 400);
            $payment = CommercePayment::query()->where('provider', $provider)
                ->where('live_mode', (bool) config('commerce.live_gateways'))->where('remote_id', $remote)->first();
            if ($payment) {
                $this->reconcile($payment, $provider === 'paypal');
            }
        }

        return response()->noContent();
    }

    public function submit(Request $request, string $number): RedirectResponse
    {
        $order = $this->buyerOrder($request, $number);
        $data = $request->validate([
            'method'=>['required', 'integer', 'min:1'],
            'reference'=>['required', 'string', 'regex:/^[A-Za-z0-9.-]{3,100}$/D'],
            'proof'=>['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $path = $data['proof']->store('commerce/payment-proofs', 'local');
        abort_unless($path, 503, 'Unable to save the payment proof.');
        try {
            DB::transaction(function () use ($order, $data, $path, $request): void {
                $locked = MarketplaceOrder::query()->lockForUpdate()->findOrFail($order->id);
                $this->awaiting($locked);
                abort_if($locked->payments()->whereIn('status', CommercePayment::ACTIVE)->exists(), 409, 'An existing payment is still open.');
                $method = CommercePaymentMethod::query()->where('available', true)->lockForUpdate()->findOrFail($data['method']);
                abort_unless(! $method->automatic() && filled($method->instructions), 422);
                $locked->payments()->create([
                    'number'=>(string) Str::uuid(), 'commerce_payment_method_id'=>$method->id,
                    'method_name'=>$method->name, 'provider'=>$method->provider, 'status'=>'manual_review', 'live_mode'=>true,
                    'submitted_reference'=>strtoupper($data['reference']), 'proof_path'=>$path,
                ]);
                AuditEvent::record('commerce.payment.submitted', $request->user(), $request);
            }, 3);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return redirect()->route('account.orders.show', $number)->with('status', 'Payment proof submitted for verification.');
    }

    public function proof(string $number, string $payment): StreamedResponse
    {
        $this->enabled();
        $attempt = $this->findPayment($number, $payment);
        abort_unless($attempt->proof_path && Storage::disk('local')->exists($attempt->proof_path), 404);

        return Storage::disk('local')->download($attempt->proof_path, 'payment-proof.'.pathinfo($attempt->proof_path, PATHINFO_EXTENSION),
            ['Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff']);
    }

    public function review(Request $request, string $number, string $payment): RedirectResponse
    {
        $this->enabled();
        $data = $request->validate([
            'decision'=>['required', 'in:approve,reject'], 'note'=>['required', 'string', 'max:300'],
            'verified_reference'=>['required_if:decision,approve', 'nullable', 'regex:/^[A-Za-z0-9.-]{3,100}$/D'],
            'verified_amount'=>['required_if:decision,approve', 'nullable', 'regex:/^\d{1,10}\.\d{2}$/D'],
        ]);
        $attempt = $this->findPayment($number, $payment);
        DB::transaction(function () use ($attempt, $data, $request): void {
            $order = MarketplaceOrder::query()->lockForUpdate()->findOrFail($attempt->marketplace_order_id);
            $current = $order->payments()->lockForUpdate()->findOrFail($attempt->id);
            abort_unless($current->status === 'manual_review', 409);
            abort_if(in_array((int) $request->user()->id, [(int) $order->buyer_id, (int) $order->seller_id], true), 403);
            $this->awaiting($order);
            $current->update(['reviewed_by'=>$request->user()->id, 'review_note'=>$data['note'], 'reviewed_at'=>now()]);
            if ($data['decision'] === 'approve') {
                abort_unless($data['verified_amount'] === $order->total, 409, 'The verified USD amount must match the order total.');
                $this->settle($order, $current, strtoupper($data['verified_reference']));
            } else {
                $current->update(['status'=>'rejected']);
                AuditEvent::record('commerce.payment.rejected', $request->user(), $request);
            }
        }, 3);

        return redirect()->route('dashboard.orders.show', $number)->with('status', 'Payment review saved.');
    }

    public function qr(Request $request, string $number, CommercePaymentMethod $method): StreamedResponse
    {
        $this->awaiting($this->buyerOrder($request, $number));
        abort_unless($method->available && ! $method->automatic() && $method->qr_path
            && Storage::disk('local')->exists($method->qr_path), 404);

        return Storage::disk('local')->response($method->qr_path, headers: [
            'Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    private function reconcile(CommercePayment $payment, bool $capture): ?string
    {
        if ($payment->status !== 'checkout_open') {
            return null;
        }
        abort_if(! $payment->live_mode && app()->environment('production'), 409);
        [$status, $reference, $url] = array_pad($this->gateway->refresh($payment, $capture), 3, null);
        DB::transaction(function () use ($payment, $status, $reference): void {
            $order = MarketplaceOrder::query()->lockForUpdate()->findOrFail($payment->marketplace_order_id);
            $current = $order->payments()->lockForUpdate()->findOrFail($payment->id);
            if ($current->status !== 'checkout_open') {
                return;
            }
            if ($status === 'paid') {
                $this->awaiting($order);
                $this->settle($order, $current, $reference);
            } elseif ($status === 'expired') {
                $current->update(['status'=>'expired']);
            }
        }, 3);

        return $url;
    }

    private function settle(MarketplaceOrder $order, CommercePayment $payment, ?string $reference): void
    {
        abort_unless(is_string($reference) && preg_match('/^[A-Za-z0-9_.-]{1,200}$/D', $reference), 409);
        $provider = in_array($payment->provider, ['stripe','paypal'], true) ? $payment->provider : 'manual';
        $reference = $provider.':'.$reference;
        try {
            $payment->update(['status'=>'paid', 'settlement_reference'=>$reference]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            abort(409, 'This settlement reference has already been used.');
        }
        $order->update(['status'=>'completed', 'completed_at'=>now(),
            'receipt_number'=>(string) Str::uuid(), 'payment_method'=>$payment->method_name]);
        AuditEvent::record('commerce.payment.verified', request()->user(), request());
    }

    private function findPayment(string $number, string $payment): CommercePayment
    {
        return MarketplaceOrder::query()->where('number', $number)->firstOrFail()
            ->payments()->where('number', $payment)->firstOrFail();
    }

    private function buyerOrder(Request $request, string $number): MarketplaceOrder
    {
        $this->enabled();

        return MarketplaceOrder::query()->where('buyer_id', $request->user()->id)->where('number', $number)->firstOrFail();
    }

    private function awaiting(MarketplaceOrder $order): void
    {
        abort_unless($order->status === 'awaiting_payment' && $order->total !== '0.00' && $order->currency === 'USD', 409);
    }

    private function enabled(): void
    {
        abort_unless(config('commerce.enabled') && config('commerce.payments_enabled'), 404);
    }
}
