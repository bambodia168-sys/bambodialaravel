<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\AccountNotification;
use App\Models\CommercePayment;
use App\Models\CommercePaymentMethod;
use App\Models\MarketplaceOrder;
use App\Support\CommerceGateway;
use App\Support\CommerceCollection;
use App\Support\CommerceQrGateway;
use App\Support\CommercePaymentStart;
use App\Support\OrderFulfillment;
use BaconQrCode\Common\ErrorCorrectionLevel;
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
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class CommercePaymentController extends Controller
{
    public function __construct(
        private readonly CommerceGateway $gateway,
        private readonly CommerceQrGateway $qrGateway,
        private readonly CommercePaymentStart $paymentStart,
    ) {}

    public function start(Request $request, string $number): RedirectResponse
    {
        abort_unless(CommerceCollection::enabled(), 404);
        $order = $this->buyerOrder($request, $number);
        $data = $request->validate(['method'=>['required', 'integer', 'min:1']]);
        $payment = $this->paymentStart->prepare($order, (int) $data['method']);
        $live = $payment->live_mode;
        if ($payment->dynamicQr()) {
            return redirect()->route('account.orders.show', $number);
        }
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
        $attempt = $this->buyerOrder($request, $number)->payments()
            ->where('number', $payment)->whereNotNull('remote_id')->firstOrFail();
        if ($attempt->provider === 'stripe' && $attempt->status === 'checkout_open') {
            try {
                $this->reconcile($attempt);
            } catch (HttpExceptionInterface $exception) {
                if (! in_array($exception->getStatusCode(), [409, 502, 503], true)) {
                    throw $exception;
                }

                return redirect()->route('account.orders.show', $number)
                    ->with('status', 'Payment confirmation is pending. You can check its status from your order.');
            }
        }

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

        $svg = $writer->writeString($attempt->qr_payload, ecLevel: ErrorCorrectionLevel::H());

        return response(str_replace('<svg ', '<svg shape-rendering="crispEdges" ', $svg), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function retryDelivery(Request $request, string $number): RedirectResponse
    {
        $this->enabled();

        $outcome = DB::transaction(function () use ($request, $number): array {
            $order = MarketplaceOrder::query()->where('number', $number)->lockForUpdate()->firstOrFail();
            abort_unless($order->status === 'completed' && $order->paidAttempt()->exists(), 409);

            if (! $this->deliveryPending($order)) {
                return ['status' => 'This order has no pending delivery.'];
            }

            $blocker = $this->fulfillGatewayPurchase($order);
            AuditEvent::record('commerce.order.delivery_retried', $request->user(), $request);

            return $blocker === null
                ? ['status' => 'Purchase delivery completed.']
                : ['error' => $blocker];
        }, 3);

        return redirect()->route('dashboard.commerce-operations')->withFragment('pending-deliveries')
            ->with($outcome);
    }

    public function webhook(Request $request, string $provider): Response
    {
        $this->enabled();
        $event = $this->gateway->webhook($request, $provider);
        if ($event !== null) {
            [$remote, $live] = $event;
            abort_unless(is_string($remote) && preg_match('/^[A-Za-z0-9_-]{1,200}$/D', $remote), 400);
            $payment = CommercePayment::query()->where('provider', $provider)
                ->where('live_mode', $live)->where('remote_id', $remote)->first();
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
                abort_unless(! $method->usesGateway() && $method->availableForCheckout(), 422);
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
                OrderFulfillment::deliverVerifiedPurchase($order);
                OrderFulfillment::notifyBuyer($order);
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

    public function reconcile(CommercePayment $payment, bool $capture = false): ?string
    {
        if (! in_array($payment->status, ['checkout_open', 'paid'], true)) {
            return null;
        }
        abort_if(! $payment->live_mode && app()->environment('production'), 409);

        $status = $reference = $url = null;
        if ($payment->status === 'checkout_open') {
            $result = $payment->dynamicQr()
                ? $this->qrGateway->refresh($payment)
                : $this->gateway->refresh($payment, $capture);
            [$status, $reference, $url] = array_pad($result, 3, null);
        }
        DB::transaction(function () use ($payment, $status, $reference): void {
            $order = MarketplaceOrder::query()->lockForUpdate()->findOrFail($payment->marketplace_order_id);
            $current = $order->payments()->lockForUpdate()->findOrFail($payment->id);
            if ($current->status === 'paid' && $current->settlement_reference !== null) {
                $this->fulfillGatewayPurchase($order);

                return;
            }
            if ($current->status !== 'checkout_open') {
                return;
            }
            if ($status === 'paid') {
                $this->awaiting($order);
                $this->settle($order, $current, $reference);
                $this->fulfillGatewayPurchase($order);
            } elseif ($status === 'expired') {
                $current->update(['status'=>'expired']);
            }
        }, 3);

        return $url;
    }

    private function deliveryPending(MarketplaceOrder $order): bool
    {
        return MarketplaceOrder::query()->whereKey($order->id)->deliveryPending()->exists();
    }

    private function fulfillGatewayPurchase(MarketplaceOrder $order): ?string
    {
        if (! $this->deliveryPending($order)) {
            return null;
        }

        try {
            OrderFulfillment::ensureReady($order);
            DB::transaction(fn () => OrderFulfillment::deliverVerifiedPurchase($order));
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            // Keep a verified payment on record if its approved delivery becomes unavailable.
            return $exception->getMessage();
        }

        OrderFulfillment::notifyBuyer($order);

        return null;
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
        OrderFulfillment::queueReceipt($order);
        OrderFulfillment::queueSellerPurchase($order);
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
