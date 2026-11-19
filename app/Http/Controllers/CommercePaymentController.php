<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\AccountNotification;
use App\Models\CommercePayment;
use App\Models\CommerceGatewaySetting;
use App\Models\CommercePaymentMethod;
use App\Models\MarketplaceOrder;
use App\Support\CommerceGateway;
use App\Support\CommerceCollection;
use App\Support\CommerceQrGateway;
use App\Support\OrderFulfillment;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
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
    public function __construct(private readonly CommerceGateway $gateway, private readonly CommerceQrGateway $qrGateway) {}

    public function start(Request $request, string $number): RedirectResponse|JsonResponse
    {
        abort_unless(CommerceCollection::enabled(), 404);
        $order = $this->buyerOrder($request, $number);
        $data = $request->validate(['method'=>['required', 'integer', 'min:1']]);
        $live = (bool) config('commerce.live_gateways');
        $formConfigured = CommerceGateway::stripeFormKey($live) !== null;
        $payment = DB::transaction(function () use ($order, $data, $live, $formConfigured, $request): CommercePayment {
            $locked = MarketplaceOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->awaiting($locked);
            $active = $locked->payments()->whereIn('status', CommercePayment::ACTIVE)->first();
            if ($active) {
                abort_unless($active->commerce_payment_method_id === (int) $data['method']
                    && in_array($active->status, ['starting', 'checkout_open'], true), 409, 'An existing payment is still open.');

                return $active;
            }
            $method = CommercePaymentMethod::query()->where('available', true)->lockForUpdate()->findOrFail($data['method']);
            abort_unless($method->usesGateway(), 422, 'This gateway is unavailable.');
            abort_if($method->provider === 'stripe' && $formConfigured && ! $request->expectsJson(),
                422, 'Enable JavaScript and reopen this order to pay with Stripe.');
            CommerceGatewaySetting::forMode($method->provider, $live)->lockForUpdate()->firstOrFail();
            abort_unless($method->availableForCheckout($live), 422, 'This gateway is unavailable.');

            return $locked->payments()->create([
                'number'=>(string) Str::uuid(), 'commerce_payment_method_id'=>$method->id,
                'method_name'=>$method->name, 'provider'=>$method->provider, 'live_mode'=>$live, 'status'=>'starting',
            ]);
        }, 3);
        abort_unless($payment->live_mode === $live && CommerceGateway::ready($payment->provider, $live)
            && ($live || ! app()->environment('production')), 503);
        $embedded = $payment->provider === 'stripe' && $formConfigured;
        abort_if($embedded && ! $request->expectsJson(), 422, 'Enable JavaScript and reopen this order to pay with Stripe.');
        if ($payment->dynamicQr()) {
            if ($payment->remote_id === null) {
                // Serialize generation so concurrent clicks never open two bank bills.
                DB::transaction(function () use ($payment): void {
                    $locked = CommercePayment::query()->lockForUpdate()->findOrFail($payment->id);
                    if ($locked->status === 'starting' && $locked->remote_id === null) {
                        abort_if($locked->provider === 'aba' && $locked->created_at->lt(now()->subMinutes(5)),
                            409, 'This QR payment needs settlement review.');
                        $locked->update($this->qrGateway->issue($locked) + ['status' => 'checkout_open']);
                    }
                }, 1);
            }

            return redirect()->route('account.orders.show', $number);
        }
        if ($payment->remote_id) {
            $checkout = $this->reconcile($payment, false);

            return $this->checkoutResponse($payment, $number, $embedded, $checkout);
        }

        // Never start another hosted checkout once the provider's idempotency window may have expired.
        abort_if($payment->created_at->lt(now()->subHour()), 409, 'This checkout needs settlement review.');
        [$remote, $url, $clientSecret] = $this->gateway->start($payment);
        DB::transaction(function () use ($payment, $remote): void {
            $locked = CommercePayment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless($locked->remote_id === null || $locked->remote_id === $remote, 409);
            if ($locked->status === 'starting') {
                $locked->update(['remote_id'=>$remote, 'status'=>'checkout_open']);
            }
        }, 3);

        return $this->checkoutResponse($payment, $number, $embedded, ['url' => $url, 'client_secret' => $clientSecret]);
    }

    private function checkoutResponse(CommercePayment $payment, string $number, bool $embedded, array $checkout): RedirectResponse|JsonResponse
    {
        $url = $checkout['url'] ?? null;
        $trusted = $this->gateway->trustedCheckout($payment->provider, $payment->live_mode, $url);
        $orderUrl = route('account.orders.show', $number);
        if ($embedded) {
            $data = isset($checkout['client_secret'])
                ? ['client_secret' => $checkout['client_secret'], 'order_url' => $orderUrl]
                : ['redirect_url' => $trusted ? $url : $orderUrl];

            return response()->json($data)->header('Cache-Control', 'private, no-store');
        }

        return $trusted ? redirect()->away($url)
            : redirect()->route('account.orders.show', $number)->with('status', 'Payment status updated.');
    }

    public function returned(Request $request, string $number, string $payment): RedirectResponse
    {
        $this->buyerOrder($request, $number)->payments()->where('number', $payment)->whereNotNull('remote_id')->firstOrFail();

        return redirect()->route('account.orders.show', $number);
    }

    public function verify(Request $request, string $number, string $payment): RedirectResponse|JsonResponse
    {
        $attempt = $this->buyerOrder($request, $number)->payments()
            ->where('number', $payment)->whereNotNull('remote_id')->firstOrFail();
        $this->reconcile($attempt, true);
        if ($request->expectsJson()) {
            $attempt->refresh();
            $collecting = CommerceCollection::enabled();

            return response()->json([
                'status' => $attempt->status,
                'qr_visible' => $collecting && $attempt->qrVisible(),
                'collection_paused' => ! $collecting,
            ])
                ->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('account.orders.show', $number)->with('status', 'Payment status updated.');
    }

    public function orderQr(Request $request, string $number, string $payment): Response
    {
        abort_unless(CommerceCollection::enabled(), 404);
        $attempt = $this->buyerOrder($request, $number)->payments()->where('number', $payment)->firstOrFail();
        abort_unless($attempt->dynamicQr() && $attempt->qrVisible() && ($attempt->live_mode || ! app()->environment('production')), 404);
        $writer = new Writer(new ImageRenderer(new RendererStyle(288, 4), new SvgImageBackEnd));

        return response($writer->writeString($attempt->qr_payload), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
        abort_unless(CommerceCollection::enabled(), 404);
        $order = $this->buyerOrder($request, $number);
        $data = $request->validate([
            'method'=>['required', 'integer', 'min:1'],
            'reference'=>['required', 'string', 'regex:/^[A-Za-z0-9.-]{3,100}$/D'],
            'proof'=>['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ]);
        $path = $data['proof']->store('commerce/payment-proofs', 'local');
        abort_unless($path, 503, 'Unable to save the payment proof.');
        try {
            DB::transaction(function () use ($order, $data, $path, $request): void {
                $locked = MarketplaceOrder::query()->lockForUpdate()->findOrFail($order->id);
                $this->awaiting($locked);
                abort_if($locked->payments()->whereIn('status', CommercePayment::ACTIVE)->exists(), 409, 'An existing payment is still open.');
                $method = CommercePaymentMethod::query()->where('available', true)->lockForUpdate()->findOrFail($data['method']);
                abort_unless(! $method->usesGateway() && $method->availableForCheckout(false), 422);
                OrderFulfillment::ensureReady($locked);
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

        return redirect()->route('account.orders.show', $number)->with('status', 'Payment receipt submitted for review.');
    }

    public function proof(string $number, string $payment): StreamedResponse
    {
        $this->enabled();
        $attempt = $this->findPayment($number, $payment);
        abort_unless($attempt->proof_path && Storage::disk('local')->exists($attempt->proof_path), 404);

        return Storage::disk('local')->download($attempt->proof_path, 'payment-receipt.'.pathinfo($attempt->proof_path, PATHINFO_EXTENSION),
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
            $orderPath = route('account.orders.show', $order->number, false);
            $current->update(['reviewed_by'=>$request->user()->id, 'review_note'=>$data['note'], 'reviewed_at'=>now()]);
            if ($data['decision'] === 'approve') {
                abort_unless($data['verified_amount'] === $order->total, 409, 'The verified USD amount must match the order total.');
                abort_unless($current->proof_path && Storage::disk('local')->exists($current->proof_path),
                    409, 'The submitted receipt file is unavailable.');
                OrderFulfillment::ensureReady($order);
                $this->settle($order, $current, strtoupper($data['verified_reference']));
                OrderFulfillment::deliverApprovedTransfer($order);
                AccountNotification::deliver((int) $order->buyer_id, 'commerce.payment.approved', 'Payment approved',
                    'Your purchase is ready. Open the order to access it.', $orderPath);
            } else {
                $current->update(['status'=>'rejected']);
                AuditEvent::record('commerce.payment.rejected', $request->user(), $request);
                AccountNotification::deliver((int) $order->buyer_id, 'commerce.payment.rejected', 'Payment receipt rejected',
                    'Open the order to review your payment status and submit a corrected receipt.', $orderPath);
            }
        }, 3);

        return redirect()->route('dashboard.orders.show', $number)->with('status', 'Payment review saved.');
    }

    public function qr(Request $request, string $number, CommercePaymentMethod $method): StreamedResponse
    {
        abort_unless(CommerceCollection::enabled(), 404);
        $this->awaiting($this->buyerOrder($request, $number));
        abort_unless($method->available && ! $method->usesGateway() && $method->qr_path
            && Storage::disk('local')->exists($method->qr_path), 404);

        return Storage::disk('local')->response($method->qr_path, headers: [
            'Cache-Control'=>'private, no-store', 'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function reconcile(CommercePayment $payment, bool $capture = false): array
    {
        if ($payment->status !== 'checkout_open') {
            return [];
        }
        abort_if(! $payment->live_mode && app()->environment('production'), 409);
        $result = $payment->dynamicQr()
            ? $this->qrGateway->refresh($payment)
            : $this->gateway->refresh($payment, $capture);
        [$status, $reference, $url, $clientSecret] = array_pad($result, 4, null);
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

        return ['url' => $url, 'client_secret' => $clientSecret];
    }

    private function settle(MarketplaceOrder $order, CommercePayment $payment, ?string $reference): void
    {
        abort_unless(is_string($reference) && preg_match('/^[A-Za-z0-9_.-]{1,200}$/D', $reference), 409);
        $provider = $payment->dynamicQr() || in_array($payment->provider, ['stripe','paypal'], true) ? $payment->provider : 'manual';
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
        abort_unless(config('commerce.enabled'), 404);
    }
}
