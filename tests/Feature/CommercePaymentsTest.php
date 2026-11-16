<?php

namespace Tests\Feature;

use App\Models\CommercePaymentMethod;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommercePaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.enabled'=>true, 'commerce.payments_enabled'=>true]);
        Http::preventStrayRequests();
    }

    public function test_stripe_checkout_verifies_amount_and_signed_webhook_is_idempotent(): void
    {
        config(['commerce.stripe.secret'=>'sk_test_fixture', 'commerce.stripe.webhook_secret'=>'whsec_fixture']);
        [$buyer, $order] = $this->order();
        $stripe = $this->method('stripe');
        $amount = 2300;
        Http::fake(function ($request) use ($order, &$amount) {
            if ($request->method() === 'POST') {
                return Http::response(['id'=>'cs_test_one', 'url'=>'https://checkout.stripe.com/c/pay/test']);
            }

            return Http::response(['id'=>'cs_test_one', 'livemode'=>false, 'mode'=>'payment', 'currency'=>'usd',
                'amount_total'=>$amount, 'client_reference_id'=>$order->payments()->sole()->number,
                'status'=>'complete', 'payment_status'=>'paid', 'payment_intent'=>'pi_one']);
        });
        $this->actingAs($buyer)->get(route('account.orders.show', $order->number))->assertOk()->assertSee('Pay with Stripe');
        $this->post(route('account.orders.payment.start', $order->number), ['method'=>$stripe->id])
            ->assertRedirect('https://checkout.stripe.com/c/pay/test');
        $payment = $order->payments()->sole();
        $verify = route('account.orders.payment.verify', [$order->number, $payment->number]);
        $this->get(route('account.orders.payment.return', [$order->number, $payment->number]))->assertRedirect();
        $this->patch(route('account.orders.cancel', $order->number))->assertConflict();
        $this->post($verify)->assertConflict();
        $this->assertNull($order->refresh()->receipt_number);
        $amount = 2500;
        $body = json_encode(['livemode'=>false, 'type'=>'checkout.session.completed', 'data'=>['object'=>['id'=>'cs_test_one']]]);
        $timestamp = time();
        $hook = route('commerce.webhook', 'stripe');
        $this->call('POST', $hook, [], [], [], ['HTTP_STRIPE_SIGNATURE'=>"t=$timestamp,v1=".str_repeat('0', 64)], $body)->assertBadRequest();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_fixture');
        $headers = ['HTTP_STRIPE_SIGNATURE'=>"t=$timestamp,v1=$signature"];
        $this->call('POST', $hook, [], [], [], $headers, $body)->assertNoContent();
        $receipt = $order->refresh()->receipt_number;
        $this->assertNotNull($receipt);
        $this->call('POST', $hook, [], [], [], $headers, $body)->assertNoContent();
        $this->assertSame($receipt, $order->refresh()->receipt_number);
        $this->get(route('account.orders.receipt', $order->number))->assertOk()->assertSee('Stripe · Verified');
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', $payment->number)
            && $request['line_items'][0]['price_data']['unit_amount'] === 2500);
    }

    public function test_paypal_capture_checks_the_merchant_and_reuses_a_completed_capture(): void
    {
        config(['commerce.paypal'=>['client_id'=>'client', 'secret'=>'secret', 'merchant_id'=>'MERCHANT', 'webhook_id'=>'WEBHOOK']]);
        [$buyer, $order] = $this->order();
        $method = $this->method('paypal');
        $captured = false;
        $verified = false;
        $merchant = 'OTHER_MERCHANT';
        Http::fake(function ($request) use ($order, &$captured, &$merchant, &$verified) {
            $url = $request->url();
            if (str_ends_with($url, '/oauth2/token')) {
                return Http::response(['access_token'=>'token']);
            }
            if (str_ends_with($url, '/orders')) {
                return Http::response(['id'=>'PAYPAL1','links'=>[['rel'=>'payer-action','href'=>'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL1']]]);
            }
            if (str_ends_with($url, '/verify-webhook-signature')) {
                return Http::response(['verification_status'=>$verified ? 'SUCCESS' : 'FAILURE']);
            }
            if (str_ends_with($url, '/capture')) {
                $captured = true;

                return Http::response([]);
            }
            $unit = ['custom_id'=>$order->payments()->sole()->number, 'amount'=>['currency_code'=>'USD','value'=>'25.00'],
                'payee'=>['merchant_id'=>$merchant]];
            if ($captured) {
                $unit['payments']['captures'][] = ['id'=>'CAPTURE1','status'=>'COMPLETED','final_capture'=>true,'amount'=>$unit['amount']];
            }

            return Http::response(['id'=>'PAYPAL1','intent'=>'CAPTURE','status'=>$captured ? 'COMPLETED' : 'APPROVED','purchase_units'=>[$unit]]);
        });
        $this->actingAs($buyer)->post(route('account.orders.payment.start', $order->number), ['method'=>$method->id])
            ->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL1');
        $verify = route('account.orders.payment.verify', [$order->number, $order->payments()->sole()->number]);
        $this->post($verify)->assertConflict();
        $this->assertFalse($captured);
        $merchant = 'MERCHANT';
        $headers = [
            'PayPal-Auth-Algo'=>'SHA256withRSA', 'PayPal-Cert-Url'=>'https://api.sandbox.paypal.com/cert',
            'PayPal-Transmission-Id'=>'transmission', 'PayPal-Transmission-Sig'=>'signature', 'PayPal-Transmission-Time'=>now()->toIso8601String(),
        ];
        $hook = route('commerce.webhook', 'paypal');
        $event = ['event_type'=>'CHECKOUT.ORDER.APPROVED', 'resource'=>['id'=>'PAYPAL1']];
        $this->postJson($hook, $event, $headers)->assertBadRequest();
        $this->assertFalse($captured);
        $verified = true;
        $this->postJson($hook, $event, $headers)->assertNoContent();
        $this->post($verify)->assertRedirect();
        $this->assertSame('completed', $order->refresh()->status);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/capture')));
    }

    public function test_manual_proof_is_private_and_confirmed_reference_cannot_be_reused(): void
    {
        Storage::fake('local');
        [$buyer, $order] = $this->order();
        $method = $this->method('khqr');
        $admin = $this->user('super_admin');
        $this->actingAs($admin)->get(route('dashboard.payment-methods.index'))->assertOk()->assertSee('KHQR')->assertSee('PayPal');
        $this->patch(route('dashboard.payment-methods.update', $method), ['available'=>true, 'instructions'=>'Use the merchant QR.',
            'qr'=>UploadedFile::fake()->image('merchant.png')])->assertRedirect();
        $this->actingAs($buyer)->get(route('account.orders.payment.qr', [$order->number, $method]))->assertOk();
        $submit = route('account.orders.payment.submit', $order->number);
        $this->post($submit, ['method'=>$method->id, 'reference'=>'BANK-123', 'proof'=>UploadedFile::fake()->image('proof.png')])->assertRedirect();
        $attempt = $order->payments()->sole();
        Storage::disk('local')->assertExists($attempt->proof_path);
        $proof = route('dashboard.orders.payment.proof', [$order->number, $attempt->number]);
        $review = route('dashboard.orders.payment.review', [$order->number, $attempt->number]);
        $this->get($proof)->assertForbidden();
        $this->get(route('account.orders.receipt', $order->number))->assertNotFound();
        $approval = ['decision'=>'approve', 'verified_reference'=>'BANK-123', 'verified_amount'=>'25.00', 'note'=>'Reconciled against statement.'];
        $this->actingAs($admin)->get($proof)->assertDownload();
        $this->patch($review, [...$approval, 'verified_amount'=>'24.00'])->assertConflict();
        $this->patch($review, $approval)->assertRedirect();
        $this->assertSame('completed', $order->refresh()->status);
        $this->get(route('dashboard.orders.receipt', $order->number))->assertOk()->assertSee('KHQR · Verified');
        [$secondBuyer, $secondOrder] = $this->order();
        $this->actingAs($secondBuyer)->post(route('account.orders.payment.submit', $secondOrder->number),
            ['method'=>$method->id, 'reference'=>'BANK-123', 'proof'=>UploadedFile::fake()->image('second.png')])->assertRedirect();
        $this->actingAs($admin)->patch(route('dashboard.orders.payment.review', [$secondOrder->number, $secondOrder->payments()->sole()->number]),
            $approval)->assertConflict();
        $this->assertNull($secondOrder->refresh()->receipt_number);
        $this->patch(route('dashboard.orders.payment.review', [$secondOrder->number, $secondOrder->payments()->sole()->number]),
            ['decision'=>'reject','note'=>'Reference already used.'])->assertRedirect();
        $this->assertSame('rejected', $secondOrder->payments()->sole()->status);
    }

    private function method(string $provider): CommercePaymentMethod
    {
        $method = CommercePaymentMethod::query()->where('provider', $provider)->sole();
        $method->update(['available'=>true, 'instructions'=>'Transfer to the merchant account.']);

        return $method;
    }

    private function user(string $role): User
    {
        return User::query()->create(['name'=>$role, 'email'=>Str::uuid().'@example.test', 'password'=>'local-test-password', 'role'=>$role]);
    }

    private function order(): array
    {
        $buyer = $this->user('customer');
        $seller = $this->user('freelancer');
        $listing = MarketplaceListing::query()->create(['user_id'=>$seller->id, 'type'=>'service', 'title'=>'Original service',
            'description'=>'Payment integration fixture.', 'status'=>'published', 'price'=>'25.00']);
        $order = MarketplaceOrder::query()->create(['number'=>(string) Str::uuid(), 'buyer_id'=>$buyer->id,
            'seller_id'=>$seller->id, 'marketplace_listing_id'=>$listing->id, 'request_key'=>(string) Str::uuid(),
            'listing_title'=>$listing->title, 'buyer_name'=>$buyer->name, 'seller_name'=>$seller->name,
            'total'=>'25.00', 'currency'=>'USD', 'status'=>'awaiting_payment']);

        return [$buyer, $order];
    }
}
