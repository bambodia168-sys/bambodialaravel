<?php

namespace Tests\Feature;

use App\Models\CommercePaymentMethod;
use App\Models\CommerceGatewaySetting;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
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

    public function test_configuration_dialog_stores_secrets_encrypted_and_keeps_them_out_of_responses(): void
    {
        $stripe = $this->method('stripe');
        $page = route('dashboard.payment-methods.index');
        $save = route('dashboard.payment-methods.update', $stripe);
        $this->actingAs($this->user('admin'))->patch($save, ['available'=>0])->assertForbidden();
        $this->actingAs($this->user('super_admin'))->get($page)->assertOk()
            ->assertSee('aria-haspopup="dialog"', false)->assertSee('Configure Stripe')->assertSee('Webhook signing secret');
        $this->from($page)->patch($save, [
            'configure_method'=>$stripe->id, 'available'=>'0', 'gateway'=>['secret'=>'sk_test_incomplete_secret'],
        ])->assertRedirect($page)->assertSessionHasErrors('gateway')->assertSessionMissing('_old_input.gateway');
        $this->get($page)->assertSee('data-payment-reopen', false)->assertDontSee('sk_test_incomplete_secret');
        $credentials = ['secret'=>'sk_test_private_fixture', 'webhook_secret'=>'whsec_private_fixture'];
        $this->patch($save, ['available'=>'1', 'gateway'=>$credentials])->assertRedirect();
        $settings = CommerceGatewaySetting::forMode('stripe', false)->sole();
        $this->assertSame($credentials, $settings->credentials);
        $this->assertNull(CommerceGatewaySetting::forMode('stripe', true)->sole()->credentials);
        $this->assertStringNotContainsString($credentials['secret'], DB::table('commerce_gateway_settings')->where('id', $settings->id)->value('credentials'));
        $this->get($page)->assertSee('Credentials saved')->assertDontSee($credentials['secret']);
        $this->patch($save, ['available'=>'0', 'gateway'=>['secret'=>'', 'webhook_secret'=>'']])->assertRedirect();
        $this->assertSame($credentials, $settings->refresh()->credentials);
    }

    public function test_bakong_order_qr_has_bill_and_expiry_and_only_verified_payment_issues_receipt(): void
    {
        [$buyer, $order] = $this->order();
        $method = $this->method('bakong');
        $config = ['account_id'=>'merchant@devb', 'merchant_name'=>'Bambodia', 'merchant_city'=>'Phnom Penh', 'token'=>'sandbox-token'];
        $admin = $this->user('super_admin');
        $save = route('dashboard.payment-methods.update', $method);
        $this->actingAs($admin)->patch($save, ['available'=>1, 'gateway'=>$config])->assertRedirect();
        $start = route('account.orders.payment.start', $order->number);
        $orderPage = route('account.orders.show', $order->number);
        $this->actingAs($buyer)->get($orderPage)->assertSee('Generate QR for Bakong');
        $this->post($start, ['method'=>$method->id])->assertRedirect($orderPage);
        $this->post($start, ['method'=>$method->id])->assertRedirect($orderPage);
        $attempt = $order->payments()->sole();
        $this->assertStringContainsString('5303840540525.00', $attempt->qr_payload);
        $this->assertStringContainsString('62240120'.substr(str_replace('-', '', $attempt->number), 0, 20), $attempt->qr_payload);
        $this->assertStringContainsString('99340013', $attempt->qr_payload);
        $this->assertSame(md5($attempt->qr_payload), $attempt->remote_id);
        $qr = route('account.orders.payment.order-qr', [$order->number, $attempt->number]);
        $verify = route('account.orders.payment.verify', [$order->number, $attempt->number]);
        $this->actingAs($this->user('customer'))->get($qr)->assertNotFound();
        $this->actingAs($buyer)->get($qr)->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('<svg', false);
        $this->get($orderPage)->assertSee('data-order-payment-poll', false);
        $this->actingAs($admin)->patch($save, ['available'=>1, 'gateway'=>['account_id'=>'other@devb']])->assertSessionHasErrors('gateway');
        $this->patch($save, ['available'=>1, 'gateway'=>['token'=>'refreshed-token']])->assertRedirect();

        $hash = str_repeat('ab', 32);
        Http::fake(['https://sit-api-bakong.nbc.gov.kh/v1/check_transaction_by_md5'=>Http::sequence()
            ->push(['responseCode'=>1, 'errorCode'=>1, 'data'=>null])
            ->push(['responseCode'=>0, 'data'=>['hash'=>$hash, 'toAccountId'=>'different@devb', 'currency'=>'USD', 'amount'=>25]])
            ->push(['responseCode'=>0, 'data'=>['hash'=>$hash, 'toAccountId'=>'merchant@devb', 'currency'=>'USD', 'amount'=>25]])]);
        $this->actingAs($buyer)->postJson($verify)->assertOk()->assertJson(['status'=>'checkout_open', 'qr_visible'=>true]);
        $this->postJson($verify)->assertConflict();
        $this->assertNull($order->refresh()->receipt_number);
        $this->postJson($verify)->assertOk()->assertJson(['status'=>'paid', 'qr_visible'=>false]);
        $receipt = $order->refresh()->receipt_number;
        $this->assertNotNull($receipt);
        $this->postJson($verify)->assertOk()->assertJson(['status'=>'paid']);
        $this->assertSame($receipt, $order->refresh()->receipt_number);
        $this->get($qr)->assertNotFound();
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer refreshed-token') && $request['md5'] === $attempt->remote_id);
    }

    public function test_aba_payway_generates_and_verifies_a_signed_order_bill(): void
    {
        [$buyer, $order] = $this->order();
        $method = $this->method('aba');
        $this->actingAs($this->user('super_admin'))->patch(route('dashboard.payment-methods.update', $method), [
            'available'=>1, 'gateway'=>['merchant_id'=>'testing-shop', 'secret'=>'aba-test-secret'],
        ])->assertRedirect();
        $payload = '00020101021230510016abaakhppxxx@abaa01151250212145328460208ABA Bank520459875303840540525.005802KH5925OLD ME 25 CHAR WINNER IP26010Phnom Penh62570115MC-REF-KH-1500068340010PAYWAY@ABA0208104514230604A2279934001317598053453370113175980552533763043138';
        $verified = 24;
        Http::fake(function ($request) use ($payload, &$verified) {
            if (str_ends_with($request->url(), '/generate-qr')) {
                return Http::response(['status'=>['code'=>'0'], 'currency'=>'USD', 'amount'=>25, 'qrString'=>$payload]);
            }

            return Http::response(['status'=>['code'=>'00', 'tran_id'=>$request['tran_id']], 'data'=>[
                'payment_status'=>'APPROVED', 'payment_status_code'=>0, 'payment_currency'=>'USD', 'payment_amount'=>$verified, 'refund_amount'=>0,
            ]]);
        });
        $start = route('account.orders.payment.start', $order->number);
        $this->actingAs($buyer)->post($start, ['method'=>$method->id])->assertRedirect();
        $attempt = $order->payments()->sole();
        Http::assertSent(fn ($request) => $request->url() === 'https://checkout-sandbox.payway.com.kh/api/payment-gateway/v1/payments/generate-qr'
            && $request['tran_id'] === $attempt->remote_id
            && $request['hash'] === base64_encode(hash_hmac('sha512',
                $request['req_time'].'testing-shop'.$attempt->remote_id.'25.00purchaseabapay_khqrUSD6template3_color', 'aba-test-secret', true)));
        $route = route('account.orders.payment.verify', [$order->number, $attempt->number]);
        $this->postJson($route)->assertConflict();
        $this->assertNull($order->refresh()->receipt_number);
        $verified = 25;
        $this->artisan('commerce:reconcile-qr')->assertSuccessful();
        $this->assertSame('completed', $order->refresh()->status);
        $this->assertSame('aba:'.$attempt->remote_id, $attempt->refresh()->settlement_reference);
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
