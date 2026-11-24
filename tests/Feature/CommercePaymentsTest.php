<?php

namespace Tests\Feature;

use App\Models\CommercePaymentMethod;
use App\Models\CommerceGatewaySetting;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MediaAsset;
use App\Models\ProductFileVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_only_super_admin_can_enable_and_pause_collection_without_interrupting_receipt_review(): void
    {
        Storage::fake('local');
        config(['commerce.payments_enabled' => false]);
        [$buyer, $order] = $this->order();
        $admin = $this->user('super_admin');
        $settings = route('dashboard.payment-methods.index');
        $toggle = route('dashboard.payment-methods.processing');
        $orderPage = route('account.orders.show', $order->number);
        $submit = route('account.orders.payment.submit', $order->number);

        $this->actingAs($buyer)->patch($toggle, ['enabled' => true])->assertForbidden();
        $this->actingAs($this->user('admin'))->patch($toggle, ['enabled' => true])->assertForbidden();
        $this->actingAs($admin)->get($settings)->assertOk()->assertSee('Enable payments');
        $this->from($settings)->patch($toggle, ['enabled' => true])->assertRedirect($settings)
            ->assertSessionHasErrorsIn('paymentProcessing', 'enabled');

        $method = $this->method('khqr');
        $this->patch(route('dashboard.payment-methods.update', $method), [
            'available' => true, 'qr' => UploadedFile::fake()->image('merchant.png'),
        ])->assertRedirect();
        $qr = route('account.orders.payment.qr', [$order->number, $method]);
        $this->actingAs($buyer)->get($qr)->assertNotFound();
        $this->actingAs($admin)->patch($toggle, ['enabled' => true])->assertRedirect();
        $this->get($settings)->assertOk()->assertSee('Pause new payments');
        $this->get(route('dashboard.features'))->assertOk();

        $receipt = [
            'method' => $method->id, 'reference' => 'PAUSE-E2E-REF', 'proof' => UploadedFile::fake()->image('proof.png'),
        ];
        $this->actingAs($buyer)->get($orderPage)->assertOk()->assertSee('KHQR')->assertSee('data-payment-stamp="awaiting"', false);
        $this->get($qr)->assertOk();
        $this->post($submit, $receipt)->assertRedirect();
        $attempt = $order->payments()->sole();

        $this->actingAs($admin)->patch($toggle, ['enabled' => false])->assertRedirect();
        $this->get($settings)->assertOk()->assertSee('Enable payments');
        $this->get(route('dashboard.orders.payment.proof', [$order->number, $attempt->number]))->assertDownload();
        $this->get(route('dashboard.orders.show', $order->reference()))->assertOk()
            ->assertSee('Pending review')->assertSee('Review transfer')->assertSee('Download payment receipt')
            ->assertDontSee('Official receipt')->assertDontSee('Verify this receipt');
        $this->actingAs($buyer)->get($orderPage)->assertOk()->assertSee('payment receipt is awaiting review');
        $this->get($qr)->assertNotFound();
        $this->post($submit, $receipt)->assertNotFound();
        $this->patch(route('account.orders.cancel', $order->number))->assertConflict();
        $this->actingAs($admin)->patch(route('dashboard.orders.payment.review', [$order->number, $attempt->number]), [
            'decision' => 'approve', 'verified_reference' => 'PAUSE-E2E-REF', 'verified_amount' => '25.00',
            'note' => 'Verified against the statement.',
        ])->assertRedirect();
        $this->actingAs($buyer)->get($orderPage)->assertOk()->assertSee('Open service workspace');
        $this->get(route('account.orders.receipt', $order->number))->assertOk();
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
        $this->actingAs($this->user('super_admin'))
            ->patch(route('dashboard.payment-methods.processing'), ['enabled' => false])->assertRedirect();
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
        $this->actingAs($buyer)->get(route('account.orders.receipt', $order->number))->assertOk()->assertSee('Stripe · Verified');
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
        $this->get(route('account.orders.show', $order->number))->assertOk()
            ->assertSee('awaiting review')->assertDontSee('Open service workspace');
        $this->assertDatabaseCount('service_inquiries', 0);
        $approval = ['decision'=>'approve', 'verified_reference'=>'BANK-123', 'verified_amount'=>'25.00', 'note'=>'Reconciled against statement.'];
        $this->actingAs($admin)->get($proof)->assertDownload();
        $this->patch($review, [...$approval, 'verified_amount'=>'24.00'])->assertConflict();
        $this->patch($review, $approval)->assertRedirect();
        $this->assertSame('completed', $order->refresh()->status);
        $this->get(route('dashboard.orders.receipt', $order->number))->assertOk()->assertSee('KHQR · Verified');
        $service = $order->serviceInquiry()->sole();
        $serviceUrl = route('inquiries.show', $service);
        $this->actingAs($buyer)->get(route('account.orders.show', $order->number))->assertOk()->assertSee('Open service workspace');
        $this->get($serviceUrl)->assertOk()->assertSee('Service workspace');
        $this->post(route('inquiries.reply', $service), ['body' => 'Please let me know how to start.'])->assertRedirect();
        $seller = User::query()->findOrFail($order->seller_id);
        $this->actingAs($seller)->get($serviceUrl)->assertOk()->assertSee('Please let me know how to start.');
        [$secondBuyer, $secondOrder] = $this->order();
        $this->actingAs($secondBuyer)->get($serviceUrl)->assertNotFound();
        $this->actingAs($secondBuyer)->post(route('account.orders.payment.submit', $secondOrder->number),
            ['method'=>$method->id, 'reference'=>'BANK-123', 'proof'=>UploadedFile::fake()->image('second.png')])->assertRedirect();
        $this->actingAs($admin)->patch(route('dashboard.orders.payment.review', [$secondOrder->number, $secondOrder->payments()->sole()->number]),
            $approval)->assertConflict();
        $this->assertNull($secondOrder->refresh()->receipt_number);
        $this->patch(route('dashboard.orders.payment.review', [$secondOrder->number, $secondOrder->payments()->sole()->number]),
            ['decision'=>'reject','note'=>'Reference already used.'])->assertRedirect();
        $this->assertSame('rejected', $secondOrder->payments()->sole()->status);
        $this->assertFalse($secondOrder->serviceInquiry()->exists());
        $this->actingAs($secondBuyer)->get(route('account.orders.show', $secondOrder->number))->assertOk()
            ->assertSee('receipt was rejected')->assertDontSee('Open service workspace');
    }

    public function test_saved_manual_qr_can_be_previewed_by_super_admin_with_checkout_disabled(): void
    {
        Storage::fake('local');
        config(['commerce.payments_enabled' => false]);

        $admin = $this->user('super_admin');
        $bank = $this->method('bank_transfer');
        $image = UploadedFile::fake()->image('merchant.png');
        $preview = route('dashboard.payment-methods.qr-preview', $bank);
        $page = route('dashboard.payment-methods.index');
        $save = route('dashboard.payment-methods.update', $bank);

        $this->get($preview)->assertRedirect(route('login'));
        $this->actingAs($admin)->get($preview)->assertNotFound();
        $this->patch($save, ['available' => false, 'qr' => $image])->assertRedirect();
        $this->get($page)->assertOk()->assertSee($preview)->assertSee('Saved Bank Transfer merchant QR');
        $response = $this->get($preview)->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(file_get_contents($image->getPathname()), $response->getContent());

        $this->actingAs($this->user('admin'))->get($preview)->assertForbidden();
        $this->actingAs($this->user('customer'))->get($preview)->assertForbidden();

        $this->actingAs($admin);
        $dynamic = $this->method('aba');
        $dynamic->update(['qr_path' => $bank->refresh()->qr_path]);
        $this->get(route('dashboard.payment-methods.qr-preview', $dynamic))->assertNotFound();

        $this->patch($save, ['available' => false, 'remove_qr' => true])->assertRedirect();
        $this->get($preview)->assertNotFound();
        $this->get($page)->assertOk()->assertDontSee($preview);
    }

    public function test_paid_service_delivery_review_and_order_support_remain_participant_scoped(): void
    {
        Storage::fake('local');
        [$buyer, $order] = $this->order();
        $seller = User::query()->findOrFail($order->seller_id);
        $admin = $this->user('super_admin');
        $outsider = $this->user('customer');
        $method = $this->method('bank_transfer');
        $orderUrl = route('account.orders.show', $order->number);
        $review = route('account.orders.review.store', $order->number);

        $this->actingAs($buyer)->post($review, ['rating' => 5])->assertConflict();
        $this->post(route('account.orders.payment.submit', $order->number), [
            'method' => $method->id, 'reference' => 'SERVICE-TRANSFER',
            'proof' => UploadedFile::fake()->image('proof.png'),
        ])->assertRedirect($orderUrl);
        $this->actingAs($seller)->get(route('studio.commerce-operations'))
            ->assertOk()->assertDontSee('Receipts awaiting review')->assertDontSee($order->reference());
        $this->actingAs($admin)->get(route('dashboard.commerce-operations'))
            ->assertOk()->assertSee('Receipts awaiting review')->assertSee($order->reference());
        $this->patch(route('dashboard.orders.payment.review', [$order->number, $order->payments()->sole()->number]), [
            'decision' => 'approve', 'verified_reference' => 'SERVICE-TRANSFER',
            'verified_amount' => '25.00', 'note' => 'Verified against the statement.',
        ])->assertRedirect();
        $this->get(route('dashboard.commerce-operations'))->assertOk()->assertDontSee('Receipts awaiting review');

        $inquiry = $order->serviceInquiry()->sole();
        $workspace = route('inquiries.show', $inquiry);
        $requirements = route('inquiries.delivery.requirements', $inquiry);
        $submit = route('inquiries.delivery.submit', $inquiry);
        $decide = route('inquiries.delivery.decide', $inquiry);
        $this->actingAs($outsider)->get($workspace)->assertNotFound();
        $this->post($requirements, ['requirements' => 'Unauthorized requirements.'])->assertNotFound();
        $this->post($review, ['rating' => 5])->assertNotFound();
        $this->actingAs($seller)->post($submit, ['notes' => 'Premature delivery.'])->assertConflict();
        $this->post($requirements, ['requirements' => 'Unauthorized requirements.'])->assertForbidden();

        $this->actingAs($buyer)->get($workspace)->assertOk()->assertSee('Send requirements');
        $this->post($requirements, ['requirements' => 'Install the agreed website package on the staging domain.'])
            ->assertRedirect($workspace);
        $this->post($requirements, ['requirements' => 'Duplicate requirements.'])->assertConflict();
        $this->patch(route('inquiries.close', $inquiry))->assertConflict();
        $this->post($review, ['rating' => 5])->assertConflict();

        $this->actingAs($seller)->get($workspace)->assertOk()->assertSee('Submit delivery')->assertSee('staging domain');
        $this->post($submit, ['notes' => 'First delivery for review.', 'artifact' => UploadedFile::fake()->create('unsafe.exe', 1)])
            ->assertSessionHasErrors('artifact');
        $artifact = "%PDF-1.4
Private installation report.
%%EOF
";
        $this->post($submit, [
            'notes' => 'First delivery for review.',
            'artifact' => UploadedFile::fake()->createWithContent('installation.pdf', $artifact),
        ])->assertRedirect($workspace)->assertSessionHasNoErrors();
        $first = $inquiry->deliveries()->sole();
        $download = route('inquiries.delivery.download', [$inquiry, $first->id]);
        $this->actingAs($outsider)->get($download)->assertNotFound();
        $this->actingAs($buyer)->get($download)->assertDownload('bambodia-delivery-'.$first->id.'.pdf');

        Storage::disk('local')->put($first->storage_path, 'Changed data');
        $this->get($download)->assertConflict();
        $this->patch($decide, ['delivery' => $first->id, 'decision' => 'accept'])->assertConflict();
        Storage::disk('local')->put($first->storage_path, $artifact);
        $this->patch($decide, ['delivery' => $first->id, 'decision' => 'revise'])->assertSessionHasErrors('feedback');
        $this->patch($decide, ['delivery' => $first->id, 'decision' => 'revise', 'feedback' => 'Add the setup notes.'])
            ->assertRedirect($workspace);
        $this->actingAs($seller)->get($workspace)->assertOk()->assertSee('Add the setup notes.');
        $this->post($submit, ['notes' => 'Updated delivery and setup notes.'])->assertRedirect($workspace);
        $latest = $inquiry->deliveries()->latest('id')->firstOrFail();
        $this->actingAs($buyer)->patch($decide, ['delivery' => $first->id, 'decision' => 'accept'])->assertConflict();
        $this->patch($decide, ['delivery' => $latest->id, 'decision' => 'accept'])->assertRedirect($workspace);
        $this->get($workspace)->assertOk()->assertSee('Delivery accepted')->assertSee('Updated delivery and setup notes.');
        $this->assertDatabaseHas('account_notifications', ['user_id' => $seller->id, 'event' => 'commerce.service.accepted']);

        $this->get($orderUrl)->assertOk()->assertSee('Review your purchase');
        $this->post($review, ['rating' => 6])->assertSessionHasErrors('rating');
        $this->post($review, ['rating' => 5, 'body' => 'Installation was completed as agreed.'])->assertRedirect($orderUrl);
        $this->post($review, ['rating' => 4])->assertConflict();
        $listingUrl = MarketplaceListing::query()->findOrFail($order->marketplace_listing_id)->publicUrl();
        $this->actingAs($outsider)->get($listingUrl)->assertOk()->assertSee('Verified buyer')
            ->assertSee('Installation was completed as agreed.');
        $this->actingAs($admin)->get(route('dashboard.orders.show', $order->number))->assertOk()->assertSee('Hide review');
        $this->patch(route('dashboard.orders.review.visibility', $order->number), ['visible' => false])->assertRedirect();
        $this->get($listingUrl)->assertOk()->assertDontSee('Installation was completed as agreed.');

        $ticket = ['order_number' => $order->reference(), 'subject' => 'Delivery support', 'message' => 'Please help with access to the final delivery.'];
        $this->actingAs($outsider)->post(route('support.store'), $ticket)->assertNotFound();
        $response = $this->actingAs($buyer)->post(route('support.store'), $ticket)->assertRedirect();
        $this->assertDatabaseHas('support_tickets', ['user_id' => $buyer->id, 'context_type' => 'order', 'context_id' => $order->id]);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee($order->reference());
    }

    public function test_product_checkout_rejects_missing_download_at_entry_and_submission(): void
    {
        Storage::fake('local');
        $buyer = $this->user('customer');
        $seller = $this->user('freelancer');
        [$product, $release] = $this->product($seller, $this->user('super_admin'));
        $checkout = route('account.orders.create', $product->public_id);
        $form = $this->actingAs($buyer)->get($checkout)->assertOk();

        Storage::disk('local')->delete($release->storage_path);
        $this->get($checkout)->assertConflict();
        $this->post(route('account.orders.store', $product->public_id), [
            'tier' => 'regular', 'request_key' => $form->viewData('requestKey'),
            'quote' => $form->viewData('quotes')['regular']['signature'],
        ])->assertConflict();
        $this->assertDatabaseCount('marketplace_orders', 0);
    }

    public function test_product_receipt_rejection_grants_nothing_and_a_corrected_approval_delivers_immediately(): void
    {
        Storage::fake('local');
        $buyer = $this->user('customer');
        $admin = $this->user('super_admin');
        $seller = $this->user('freelancer');
        [$product, $release] = $this->product($seller, $admin);
        $method = $this->method('bank_transfer');
        $this->actingAs($buyer);
        $purchase = route('account.orders.store', $product->public_id);
        $offer = $this->get(route('account.orders.create', $product->public_id))->assertOk();
        $this->post($purchase, [
            'tier' => 'regular', 'request_key' => $offer->viewData('requestKey'),
            'quote' => $offer->viewData('quotes')['regular']['signature'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $order = MarketplaceOrder::query()->sole();
        $this->assertSame($release->id, $order->fulfillment_version_id);
        $orderUrl = route('account.orders.show', $order->number);
        $receiptUrl = route('account.orders.receipt', $order->number);
        $submit = route('account.orders.payment.submit', $order->number);

        $this->get($orderUrl)->assertOk()->assertSee('Transfer to the merchant account.')->assertSee('Payment receipt');
        $transfer = ['method' => $method->id, 'reference' => 'TRANSFER-FIRST'];
        $this->post($submit, $transfer)->assertSessionHasErrors('proof');
        $this->post($submit, $transfer + ['proof' => UploadedFile::fake()->create('receipt.pdf', 12, 'application/pdf')])
            ->assertRedirect($orderUrl)->assertSessionHasNoErrors();
        $first = $order->payments()->sole();
        $this->assertSame('manual_review', $first->status);
        $this->get($receiptUrl)->assertNotFound();
        $this->assertDatabaseCount('product_licenses', 0);
        $review = route('dashboard.orders.payment.review', [$order->number, $first->number]);
        $this->actingAs($seller)->patch($review, ['decision' => 'reject', 'note' => 'Unauthorized.'])->assertForbidden();
        $this->actingAs($admin)->get(route('dashboard.orders.payment.proof', [$order->number, $first->number]))
            ->assertDownload('payment-receipt.pdf');
        $this->patch($review, ['decision' => 'reject', 'note' => 'No matching transfer.'])->assertRedirect();

        $this->actingAs($buyer)->get($orderUrl)->assertOk()->assertSee('receipt was rejected')
            ->assertDontSee('Download product')->assertDontSee('My licenses');
        $this->get($receiptUrl)->assertNotFound();
        $this->get(route('account.downloads'))->assertOk()->assertDontSee($product->title);
        $this->get(route('account.licenses.index'))->assertOk()->assertDontSee($product->title);
        $this->assertDatabaseCount('product_entitlements', 0);
        $this->assertDatabaseCount('product_licenses', 0);
        $this->assertDatabaseHas('account_notifications', ['user_id' => $buyer->id, 'event' => 'commerce.payment.rejected']);

        $this->post($submit, [
            'method' => $method->id, 'reference' => 'TRANSFER-CORRECTED', 'proof' => UploadedFile::fake()->image('receipt.png'),
        ])->assertRedirect($orderUrl);
        $retry = $order->payments()->where('status', 'manual_review')->sole();
        $approval = [
            'decision' => 'approve', 'verified_reference' => 'TRANSFER-CORRECTED', 'verified_amount' => $order->total,
            'note' => 'Verified amount and recipient in the bank statement.',
        ];
        $approve = route('dashboard.orders.payment.review', [$order->number, $retry->number]);
        $this->actingAs($admin);
        $artifact = Storage::disk('local')->get($release->storage_path);
        Storage::disk('local')->put($release->storage_path, 'mismatched file');
        $this->patch($approve, $approval)->assertConflict();
        $this->assertSame('manual_review', $retry->refresh()->status);
        $this->assertSame('awaiting_payment', $order->refresh()->status);
        $this->assertDatabaseCount('product_licenses', 0);
        Storage::disk('local')->put($release->storage_path, $artifact);

        $this->patch($approve, $approval)->assertRedirect();
        $this->patch($approve, $approval)->assertConflict();
        $grant = $order->entitlement()->sole();
        $this->assertSame('verified_purchase', $grant->license()->sole()->source);
        $this->assertSame($buyer->id, $grant->user_id);
        $this->assertDatabaseCount('product_licenses', 1);
        $this->assertDatabaseHas('account_notifications', ['user_id' => $buyer->id, 'event' => 'commerce.payment.approved']);
        $this->actingAs($buyer)->get($orderUrl)->assertOk()->assertSee('Download product')->assertSee('My licenses');
        $this->get($receiptUrl)->assertOk();
        $download = route('account.downloads.file', $grant);
        $this->get($download)->assertDownload('bambodia-'.$product->public_id.'-1.0.zip');
        $this->get(route('account.licenses.index'))->assertOk()->assertSee($product->title);
        $this->get($orderUrl)->assertOk()->assertSee('Review your purchase');
        $this->post(route('account.orders.review.store', $order->number), ['rating' => 4])->assertRedirect($orderUrl);
        $product->update(['preview_url' => 'https://preview.example.com/verified-product']);
        $this->get('/search?type=product')->assertOk()->assertSeeInOrder([
            'class="marketplace-card-price"',
            'class="marketplace-card-footer-actions"',
            'class="marketplace-card-live-preview"',
            'aria-label="4.0 out of 5 from 1 verified purchase rating"',
        ], false);
        $this->actingAs($this->user('customer'))->get($download)->assertNotFound();
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
        $this->get($page)->assertSee('Sandbox credentials saved')->assertDontSee($credentials['secret']);
        $this->patch($save, ['available'=>'0', 'gateway'=>['secret'=>'', 'webhook_secret'=>'']])->assertRedirect();
        $this->assertSame($credentials, $settings->refresh()->credentials);
    }

    public static function deliveryConditions(): array
    {
        return [
            'ready at confirmation' => [false, false],
            'archive temporarily unavailable' => [true, false],
            'existing download without a license' => [true, true],
        ];
    }

    #[DataProvider('deliveryConditions')]
    public function test_verified_gateway_purchase_issues_one_installer_license(bool $archiveUnavailable, bool $existingGrant): void
    {
        Storage::fake('local');
        config(['commerce.stripe.secret' => 'sk_test_product_fixture', 'commerce.stripe.webhook_secret' => 'whsec_product_fixture']);
        $buyer = $this->user('customer');
        $seller = $this->user('freelancer');
        $admin = $this->user('super_admin');
        [$product, $release] = $this->product($seller, $admin);
        $stripe = $this->method('stripe');
        $this->actingAs($buyer);
        $offer = $this->get(route('account.orders.create', $product->public_id))->assertOk();
        $this->post(route('account.orders.store', $product->public_id), [
            'tier' => 'regular', 'request_key' => $offer->viewData('requestKey'),
            'quote' => $offer->viewData('quotes')['regular']['signature'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $order = MarketplaceOrder::query()->sole();
        $orderUrl = route('account.orders.show', $order->number);
        $start = route('account.orders.payment.start', $order->number);
        $archive = Storage::disk('local')->get($release->storage_path);
        Storage::disk('local')->put($release->storage_path, 'wrong file');
        $this->post($start, ['method' => $stripe->id])->assertConflict();
        $this->assertDatabaseCount('commerce_payments', 0);
        Storage::disk('local')->put($release->storage_path, $archive);

        $paid = false;
        $refundResponses = [];
        Http::fake(function ($request) use ($order, &$paid, &$refundResponses) {
            if ($request->url() === 'https://api.stripe.com/v1/refunds/re_product_fixture') {
                return Http::response(array_shift($refundResponses));
            }
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'cs_test_product_fixture', 'url' => 'https://checkout.stripe.com/c/pay/product-fixture']);
            }

            return Http::response([
                'id' => 'cs_test_product_fixture', 'livemode' => false, 'mode' => 'payment', 'currency' => 'usd',
                'amount_total' => 3900, 'client_reference_id' => $order->payments()->sole()->number,
                'status' => $paid ? 'complete' : 'open', 'payment_status' => $paid ? 'paid' : 'unpaid',
                'payment_intent' => 'pi_test_product_fixture',
            ]);
        });
        $this->post($start, ['method' => $stripe->id])->assertRedirect('https://checkout.stripe.com/c/pay/product-fixture');
        $attempt = $order->payments()->sole();
        $return = route('account.orders.payment.return', [$order->number, $attempt->number]);
        $verify = route('account.orders.payment.verify', [$order->number, $attempt->number]);
        $event = json_encode(['livemode' => false, 'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => $attempt->remote_id]]], JSON_THROW_ON_ERROR);
        $time = time();
        $signature = hash_hmac('sha256', $time.'.'.$event, 'whsec_product_fixture');
        $sendEvent = fn () => $this->call('POST', route('commerce.webhook', 'stripe'), [], [], [],
            ['HTTP_STRIPE_SIGNATURE' => "t=$time,v1=$signature"], $event);
        $this->get($return)->assertRedirect($orderUrl);
        $this->assertDatabaseCount('product_licenses', 0);
        $this->assertSame('awaiting_payment', $order->refresh()->status);
        $retry = route('dashboard.commerce-operations.retry-delivery', $order->number);
        $this->actingAs($admin)->post($retry)->assertConflict();
        $this->actingAs($buyer);

        $paid = true;
        $originalGrant = null;
        if ($archiveUnavailable) {
            Storage::disk('local')->delete($release->storage_path);
            $this->get($return)->assertRedirect($orderUrl);
            $this->assertDatabaseCount('product_licenses', 0);

            if ($existingGrant) {
                $originalGrant = $order->entitlement()->create([
                    'user_id' => $buyer->id, 'product_file_version_id' => $release->id,
                    'granted_by' => $seller->id, 'grant_reason' => 'Original order grant fixture', 'status' => 'active',
                ]);
            }

            $dashboard = route('dashboard.commerce-operations');
            $this->get($orderUrl)->assertOk()->assertSee('Purchase delivery pending')->assertDontSee('Download product');
            $this->post($verify)->assertRedirect($orderUrl);
            $this->post($retry)->assertForbidden();
            $this->actingAs($seller)->get(route('studio.commerce-operations'))
                ->assertOk()->assertDontSee('Paid orders awaiting delivery')->assertDontSee('Retry delivery');
            $this->actingAs($this->user('admin'))->post($retry)->assertForbidden();
            $requestsBeforeRetry = Http::recorded()->count();
            $this->actingAs($admin)->get($dashboard)->assertOk()
                ->assertSee('Paid orders awaiting delivery')->assertSee($order->number)
                ->assertSee('The original file is missing or failed its integrity check.')
                ->assertSee('Retry delivery');
            $this->artisan('commerce:alert-delayed-delivery')->expectsOutput('0 delayed delivery notification(s) sent.')->assertSuccessful();
            $order->update(['completed_at' => now()->subMinutes(31)]);
            $this->artisan('commerce:alert-delayed-delivery')->expectsOutput('1 delayed delivery notification(s) sent.')->assertSuccessful();
            $this->artisan('commerce:alert-delayed-delivery')->expectsOutput('0 delayed delivery notification(s) sent.')->assertSuccessful();
            $this->assertDatabaseHas('account_notifications', [
                'user_id' => $admin->id, 'event' => 'commerce.delivery.delayed',
                'target_path' => route('dashboard.commerce-operations', ['order' => $order->number], false).'#pending-deliveries',
            ]);
            $this->post($retry)->assertRedirect($dashboard.'#pending-deliveries')
                ->assertSessionHas('error', 'The original file is missing or failed its integrity check.');
            $this->assertDatabaseCount('product_licenses', 0);

            Storage::disk('local')->put($release->storage_path, $archive);
            $release->update(['malware_scanned_at' => now()->subYear()]);
            $this->get($dashboard)->assertOk()->assertSee('The original release needs a current clean malware scan.');
            $this->post($retry)->assertSessionHas('error', 'The original release needs a current clean malware scan.');
            $release->update(['malware_scanned_at' => now()]);

            if ($originalGrant) {
                $originalGrant->update(['status' => 'revoked', 'revoked_at' => now()]);
                $this->post($retry)->assertSessionHas('error', 'The existing download entitlement is inactive. Review it before retrying.');
                $this->assertSame('revoked', $originalGrant->fresh()->status);
                $this->assertDatabaseCount('product_licenses', 0);
                $originalGrant->update(['status' => 'active', 'revoked_at' => null]);
            }

            config(['commerce.payments_enabled' => false]);
            $receipt = $order->fresh()->receipt_number;
            $this->post($retry)->assertSessionHas('status', 'Purchase delivery completed.');
            $this->post($retry)->assertSessionHas('status', 'This order has no pending delivery.');
            $this->assertSame($requestsBeforeRetry, Http::recorded()->count());
            $this->assertSame($receipt, $order->fresh()->receipt_number);
            $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'event' => 'commerce.order.delivery_retried']);
            $this->get($dashboard)->assertOk()->assertSee('No paid orders are awaiting delivery.')->assertDontSee('Retry delivery');
            $this->actingAs($buyer);
        } else {
            $sendEvent()->assertNoContent();
        }
        $this->assertSame('completed', $order->refresh()->status);
        $this->assertSame('paid', $attempt->refresh()->status);
        $grant = $order->entitlement()->sole();
        if ($originalGrant) {
            $this->assertTrue($grant->is($originalGrant));
        }
        $license = $grant->license()->sole();
        $this->assertSame('verified_purchase', $license->source);
        $this->get($orderUrl)->assertOk()->assertSee('Ready to install')->assertSee('Download product')
            ->assertSee('My licenses')->assertSee('Installation guide')->assertDontSee($license->key_ciphertext);
        $this->get(route('account.licenses.index'))->assertOk()->assertSee($product->title)->assertDontSee($license->key_ciphertext);
        $this->get(route('account.downloads.file', $grant))->assertDownload();
        $this->actingAs($this->user('super_admin'))->get(route('dashboard.licenses.index'))
            ->assertOk()->assertSee($product->title)->assertDontSee($license->key_ciphertext);
        $this->actingAs($buyer);
        $installation = ['license_key' => $license->key_ciphertext, 'domain' => 'installer.example.test', 'product_id' => $product->public_id];
        $this->postJson(route('api.v1.licenses.activate'), $installation)->assertOk()->assertJsonPath('status', 'activated');
        $this->postJson(route('api.v1.licenses.validate'), $installation)->assertOk()->assertJsonPath('valid', true);
        $this->postJson(route('api.v1.licenses.activate'), array_replace($installation, ['product_id' => '999999999']))
            ->assertForbidden()->assertJsonPath('valid', false);

        $sendEvent()->assertNoContent();
        $this->assertDatabaseCount('product_entitlements', 1);
        $this->assertDatabaseCount('product_licenses', 1);
        $this->assertDatabaseHas('account_notifications', [
            'user_id' => $buyer->id, 'event' => 'commerce.payment.approved', 'title' => 'Purchase ready',
            'target_path' => route('account.orders.show', $order->number, false),
        ]);

        $refund = route('dashboard.orders.stripe-refund', $order->number);
        $data = ['reference' => 're_product_fixture', 'access' => $archiveUnavailable ? 'revoke' : 'retain',
            'note' => 'Reviewed this order and the applicable access terms.'];
        $this->post($refund, $data)->assertForbidden();
        $this->actingAs($admin)->get(route('dashboard.orders.show', $order->number))
            ->assertOk()->assertSee('Record a Stripe refund');
        $provider = [
            'id' => $data['reference'], 'object' => 'refund', 'status' => 'succeeded',
            'payment_intent' => 'pi_test_product_fixture', 'currency' => 'usd', 'amount' => 3900,
        ];
        $refundResponses = [
            array_replace($provider, ['amount' => 1900]),
            array_replace($provider, ['status' => 'pending']),
            array_replace($provider, ['payment_intent' => 'pi_other_order']),
            $provider,
            $provider,
        ];
        foreach (range(1, 3) as $attemptNumber) {
            $this->post($refund, $data)->assertConflict();
        }
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('active', $license->fresh()->status);
        $this->post($refund, $data)->assertRedirect(route('dashboard.orders.show', $order->number));
        $this->post($refund, $data)->assertRedirect(route('dashboard.orders.show', $order->number));
        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame($data['access'], $order->operations()->sole()->refund_access);
        $this->assertSame(1, DB::table('account_notifications')->where('event', 'commerce.refund.confirmed')->count());
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://api.stripe.com/v1/refunds/re_product_fixture');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->actingAs($buyer)->get($orderUrl)->assertOk()->assertSee('Full refund confirmed');
        $this->get(route('account.orders.receipt', $order->number))->assertOk()->assertSee('Full refund confirmed');
        if ($archiveUnavailable) {
            $this->assertSame('revoked', $license->fresh()->status);
            $this->postJson(route('api.v1.licenses.validate'), $installation)->assertForbidden();
            $this->get(route('account.downloads.file', $grant))->assertForbidden();
        } else {
            $this->postJson(route('api.v1.licenses.validate'), $installation)->assertOk();
            $this->get(route('account.downloads.file', $grant))->assertDownload();
        }
    }

    public function test_stripe_credentials_can_be_prepared_without_switching_the_active_environment(): void
    {
        config(['commerce.live_gateways' => false]);
        $stripe = $this->method('stripe');
        $page = route('dashboard.payment-methods.index');
        $save = route('dashboard.payment-methods.update', $stripe);
        $sandbox = ['secret' => 'sk_test_stripe_mode_fixture', 'webhook_secret' => 'whsec_sandbox_mode_fixture'];
        $live = ['secret' => 'sk_live_stripe_mode_fixture', 'webhook_secret' => 'whsec_live_mode_fixture'];
        $this->actingAs($this->user('admin'))->patch($save, [
            'available' => true, 'stripe_environment' => 'live', 'gateway' => $live,
        ])->assertForbidden();
        $this->actingAs($this->user('super_admin'))->get($page)
            ->assertOk()->assertSee('Credentials environment')->assertSee('Live Production');

        $this->from($page)->patch($save, [
            'available' => true, 'stripe_environment' => 'live', 'activate_stripe_environment' => true,
        ])->assertRedirect($page)->assertSessionHasErrors('stripe_environment');
        $this->patch($save, [
            'configure_method' => $stripe->id, 'available' => true, 'stripe_environment' => 'sandbox', 'gateway' => $live,
        ])->assertRedirect($page)->assertSessionHasErrors('gateway.secret');
        $this->assertNull(session()->getOldInput('gateway'));
        $this->patch($save, [
            'available' => true, 'stripe_environment' => 'live', 'gateway' => $live,
        ])->assertRedirect($page)->assertSessionHasNoErrors();
        $this->assertFalse(CommerceGatewaySetting::checkoutIsLive('stripe'));
        $this->assertNull(CommerceGatewaySetting::forMode('stripe', false)->firstOrFail()->credentials);

        $this->patch($save, [
            'available' => true, 'stripe_environment' => 'sandbox', 'gateway' => $sandbox,
        ])->assertRedirect($page)->assertSessionHasNoErrors();
        $this->assertSame($live, CommerceGatewaySetting::effective('stripe', true));
        $this->assertSame($sandbox, CommerceGatewaySetting::effective('stripe', false));
        $this->get($page)->assertOk()->assertSee('Sandbox credentials saved')
            ->assertSee('Live Production credentials saved')
            ->assertDontSee($live['secret'])->assertDontSee($live['webhook_secret'])
            ->assertDontSee($sandbox['secret'])->assertDontSee($sandbox['webhook_secret']);
    }

    public function test_live_stripe_checkout_and_signed_events_use_the_selected_mode(): void
    {
        config(['commerce.live_gateways' => false, 'app.url' => 'https://bambodia.example.test']);
        $this->app->instance('env', 'production');
        $this->withSession(['_token' => 'stripe-mode-csrf-fixture'])->withHeader('X-CSRF-TOKEN', 'stripe-mode-csrf-fixture');
        $stripe = $this->method('stripe');
        [$buyer, $order] = $this->order();
        $start = route('account.orders.payment.start', $order->number);
        $settings = route('dashboard.payment-methods.index');
        $save = route('dashboard.payment-methods.update', $stripe);
        $admin = $this->user('super_admin');
        CommerceGatewaySetting::forMode('stripe', false)->firstOrFail()->forceFill([
            'credentials' => ['secret' => 'sk_test_inactive_fixture', 'webhook_secret' => 'whsec_inactive_fixture'],
        ])->save();
        $this->actingAs($buyer)->post($start, ['method' => $stripe->id])->assertUnprocessable();
        $this->actingAs($admin)->from($settings)->patch($save, [
            'available' => true, 'stripe_environment' => 'live', 'activate_stripe_environment' => true,
            'gateway' => ['secret' => 'sk_live_active_fixture', 'webhook_secret' => 'whsec_active_fixture'],
        ])->assertRedirect($settings)->assertSessionHasNoErrors();
        $this->assertTrue(CommerceGatewaySetting::checkoutIsLive('stripe'));
        $this->assertFalse(CommerceGatewaySetting::checkoutIsLive('paypal'));
        $this->assertTrue(\App\Support\CommerceCollection::enabled());

        Http::fake(function ($request) use ($order) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'cs_live_mode_fixture', 'url' => 'https://checkout.stripe.com/c/pay/fixture']);
            }

            return Http::response([
                'id' => 'cs_live_mode_fixture', 'livemode' => true, 'mode' => 'payment', 'currency' => 'usd',
                'amount_total' => 2500, 'client_reference_id' => $order->payments()->sole()->number,
                'status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => 'pi_live_mode_fixture',
            ]);
        });
        $this->actingAs($buyer)->get(route('account.orders.show', $order->number))->assertOk()->assertSee('Pay with Stripe');
        $this->post($start, ['method' => $stripe->id])->assertRedirect('https://checkout.stripe.com/c/pay/fixture');
        $attempt = $order->payments()->sole();
        $this->assertTrue($attempt->live_mode);
        $this->actingAs($admin)->from($settings)->patch($save, [
            'available' => true, 'stripe_environment' => 'sandbox', 'activate_stripe_environment' => true,
        ])->assertRedirect($settings)->assertSessionHasErrors('stripe_environment');

        $sendEvent = function (bool $live, string $signingSecret): \Illuminate\Testing\TestResponse {
            $body = json_encode(['livemode' => $live, 'type' => 'checkout.session.completed',
                'data' => ['object' => ['id' => 'cs_live_mode_fixture']]], JSON_THROW_ON_ERROR);
            $time = time();
            $signature = hash_hmac('sha256', $time.'.'.$body, $signingSecret);

            return $this->call('POST', route('commerce.webhook', 'stripe'), [], [], [],
                ['HTTP_STRIPE_SIGNATURE' => "t=$time,v1=$signature"], $body);
        };
        $sendEvent(true, 'whsec_inactive_fixture')->assertBadRequest();
        $sendEvent(false, 'whsec_inactive_fixture')->assertNoContent();
        $this->assertNull($order->refresh()->receipt_number);
        $sendEvent(true, 'whsec_active_fixture')->assertNoContent();
        $this->assertNotNull($order->refresh()->receipt_number);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer sk_live_active_fixture'));

        $this->patch($save, [
            'available' => true, 'stripe_environment' => 'sandbox', 'activate_stripe_environment' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(CommerceGatewaySetting::checkoutIsLive('stripe'));
        $sendEvent(true, 'whsec_active_fixture')->assertNoContent();
    }

    public function test_bakong_keeps_environments_separate_and_switches_new_checkouts_to_production(): void
    {
        config(['commerce.live_gateways' => false, 'app.url' => 'https://bambodia.example.test']);
        $this->app->instance('env', 'production');
        $this->withSession(['_token' => 'bakong-mode-csrf-fixture'])->withHeader('X-CSRF-TOKEN', 'bakong-mode-csrf-fixture');
        $method = $this->method('bakong');
        $method->update(['available'=>false]);
        [$buyer, $order] = $this->order();
        $page = route('dashboard.payment-methods.index');
        $save = route('dashboard.payment-methods.update', $method);
        $start = route('account.orders.payment.start', $order->number);
        $admin = $this->user('super_admin');
        $sandbox = ['account_id'=>'sandbox@devb', 'merchant_name'=>'Sandbox Shop', 'merchant_city'=>'Phnom Penh', 'token'=>'sandbox-mode-token'];
        $production = ['account_id'=>'production@bank', 'merchant_name'=>'Bambodia', 'merchant_city'=>'Battambang', 'token'=>'production-mode-token'];

        $this->actingAs($this->user('admin'))->patch($save, [
            'available'=>true, 'bakong_environment'=>'live', 'gateway'=>['live'=>$production],
        ])->assertForbidden();
        $this->actingAs($admin)->from($page)->patch($save, [
            'available'=>true, 'bakong_environment'=>'live',
        ])->assertRedirect($page)->assertSessionHasErrors('bakong_environment');
        $this->patch($save, [
            'available'=>true, 'bakong_environment'=>['sandbox', 'live'],
        ])->assertRedirect($page)->assertSessionHasErrors('bakong_environment');
        $this->patch($save, [
            'available'=>true, 'gateway'=>['sandbox'=>$sandbox, 'live'=>['token'=>$production['token']]],
        ])->assertRedirect($page)->assertSessionHasErrors('gateway');
        $this->assertNull(CommerceGatewaySetting::forMode('bakong', false)->firstOrFail()->credentials);
        $this->assertNull(session()->getOldInput('gateway'));
        $this->patch($save, [
            'available'=>false, 'gateway'=>['sandbox'=>$sandbox, 'live'=>$production],
        ])->assertRedirect($page)->assertSessionHasNoErrors();
        $this->patch($save, [
            'available'=>true, 'bakong_environment'=>'sandbox',
            'gateway'=>['sandbox'=>array_fill_keys(array_keys($sandbox), null)],
        ])->assertRedirect($page)->assertSessionHasErrors([
            'available'=>'Bakong Sandbox can be used for checkout on a test installation. Select Production and configure its credentials to make Bakong available here.',
        ]);
        $this->assertFalse($method->fresh()->available);
        $this->assertFalse(CommerceGatewaySetting::checkoutIsLive('bakong'));
        foreach ([0=>$sandbox, 1=>$production] as $live => $credentials) {
            $settings = CommerceGatewaySetting::forMode('bakong', (bool) $live)->sole();
            $this->assertSame($credentials, $settings->credentials);
            $this->assertStringNotContainsString($credentials['token'], $settings->getRawOriginal('credentials'));
        }
        $response = $this->get($page)->assertOk()->assertSee('Sandbox API token')->assertSee('Production API token')
            ->assertSee('name="bakong_environment" value="sandbox" checked', false)
            ->assertDontSee($sandbox['token'])->assertDontSee($production['token']);
        $this->assertMatchesRegularExpression('/data-bakong-fields="live"\s+hidden disabled/', $response->getContent());
        $this->actingAs($buyer)->post($start, ['method'=>$method->id])->assertNotFound();
        $this->assertFalse($order->payments()->exists());

        $this->actingAs($admin)->patch($save, ['available'=>true, 'bakong_environment'=>'live'])
            ->assertRedirect($page)->assertSessionHasNoErrors();
        $this->assertTrue(CommerceGatewaySetting::checkoutIsLive('bakong'));
        $this->assertFalse(CommerceGatewaySetting::checkoutIsLive('stripe'));
        $this->assertFalse(CommerceGatewaySetting::checkoutIsLive('aba'));

        $this->actingAs($buyer)->post($start, ['method'=>$method->id])->assertRedirect();
        $attempt = $order->payments()->sole();
        $this->assertTrue($attempt->live_mode);
        $this->assertStringContainsString($production['account_id'], $attempt->qr_payload);
        $this->actingAs($admin)->patch($save, ['available'=>true, 'bakong_environment'=>'sandbox'])
            ->assertRedirect($page)->assertSessionHasErrors('bakong_environment');
        $this->assertTrue(CommerceGatewaySetting::checkoutIsLive('bakong'));

        Http::fake(['https://api-bakong.nbc.gov.kh/v1/check_transaction_by_md5'=>Http::response([
            'responseCode'=>0, 'data'=>['hash'=>str_repeat('cd', 32), 'toAccountId'=>$production['account_id'], 'currency'=>'USD', 'amount'=>25],
        ])]);
        $this->actingAs($buyer)->postJson(route('account.orders.payment.verify', [$order->number, $attempt->number]))
            ->assertOk()->assertJsonPath('status', 'paid');
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://api-bakong.nbc.gov.kh/v1/check_transaction_by_md5'
            && $request->hasHeader('Authorization', 'Bearer '.$production['token']) && $request['md5'] === $attempt->remote_id);
        $this->assertNotNull($order->refresh()->receipt_number);

        $this->actingAs($admin)->patch($save, ['available'=>true, 'bakong_environment'=>'sandbox'])
            ->assertRedirect($page)->assertSessionHasNoErrors();
        $this->assertFalse(CommerceGatewaySetting::checkoutIsLive('bakong'));
        $this->assertFalse($method->fresh()->availableForCheckout());
        $this->assertSame($production, CommerceGatewaySetting::effective('bakong', true));
    }

    public function test_bakong_can_save_hidden_settings_with_the_current_environment_selected_before_configuration(): void
    {
        config(['commerce.live_gateways' => false]);
        $method = $this->method('bakong');

        $this->actingAs($this->user('super_admin'))->patch(route('dashboard.payment-methods.update', $method), [
            'available'=>false, 'bakong_environment'=>'sandbox',
            'gateway'=>['sandbox'=>['token'=>null]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($method->fresh()->available);
        $this->assertFalse(CommerceGatewaySetting::checkoutIsLive('bakong'));
        $this->assertNull(CommerceGatewaySetting::forMode('bakong', false)->sole()->credentials);
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
        $this->get($orderPage)->assertSee('data-order-payment-poll', false)->assertSee('data-order-payment-code', false)
            ->assertSee('images/payments/khqr-white.svg', false)
            ->assertSee('<span class="khqr-payment-card__recipient">Bambodia</span>', false)
            ->assertSeeInOrder(['khqr-payment-card__total', '25.00', 'USD'], false)
            ->assertDontSee('sandbox-token');
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
        $this->assertDatabaseHas('email_deliveries', ['event' => 'commerce.receipt', 'user_id' => $buyer->id, 'status' => 'queued']);
        $this->assertDatabaseHas('email_deliveries', ['event' => 'commerce.seller_purchase', 'user_id' => $order->seller_id, 'status' => 'queued']);
        $this->post(route('account.orders.receipt.email', $order->number))->assertRedirect()->assertSessionHas('status');
        $this->assertSame(1, \App\Models\EmailDelivery::query()->where('event', 'commerce.receipt')->count());
        $this->get($qr)->assertNotFound();
        $this->get($orderPage)->assertDontSee('data-order-payment-code', false);
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
        $billReferences = [];
        Http::fake(function ($request) use ($payload, &$verified, &$billReferences) {
            if (str_ends_with($request->url(), '/generate-qr')) {
                $billReferences[] = $request['tran_id'];
                if (count($billReferences) === 1) {
                    return Http::response([], 502);
                }
                return Http::response(['status'=>['code'=>'0'], 'currency'=>'USD', 'amount'=>25, 'qrString'=>$payload]);
            }

            return Http::response(['status'=>['code'=>'00', 'tran_id'=>$request['tran_id']], 'data'=>[
                'payment_status'=>'APPROVED', 'payment_status_code'=>0, 'payment_currency'=>'USD', 'payment_amount'=>$verified, 'refund_amount'=>0,
            ]]);
        });
        $page = route('account.orders.show', $order->number);
        $this->actingAs($buyer)->patch(route('account.orders.payment-method', $order->number), ['payment_method'=>$method->id])
            ->assertRedirect($page)->assertSessionHas('error');
        $this->get($page)->assertOk()->assertSee('Retry QR generation');
        $this->assertSame('starting', $order->payments()->sole()->status);
        $start = route('account.orders.payment.start', $order->number);
        $this->post($start, ['method'=>$method->id])->assertRedirect();
        $attempt = $order->payments()->sole();
        $this->assertSame([$attempt->remote_id, $attempt->remote_id], $billReferences);
        $this->get($page)->assertOk()->assertSee('data-order-payment-code', false)
            ->assertSee('OLD ME 25 CHAR WINNER IP');
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

    public function test_baray_sandbox_and_production_settings_are_saved_independently_and_switch_requires_confirmation(): void
    {
        $admin = $this->user('super_admin');
        $method = $this->method('baray');
        $save = route('dashboard.payment-methods.update', $method);

        $dev = [
            'api_key' => 'baray_dev_test_key',
            'sk' => base64_encode(str_repeat('d', 32)),
            'iv' => base64_encode(str_repeat('s', 16)),
        ];
        $prod = [
            'api_key' => 'baray_live_test_key',
            'sk' => base64_encode(str_repeat('p', 32)),
            'iv' => base64_encode(str_repeat('l', 16)),
        ];

        $this->actingAs($admin)->get(route('dashboard.payment-methods.index'))->assertOk()
            ->assertSee('Sandbox / Development')->assertSee('Production credentials')
            ->assertSee('gateway[sandbox][api_key]', false)
            ->assertSee('gateway[live][api_key]', false)
            ->assertDontSee('baray_dev_test_key');

        $this->patch($save, [
            'available' => false,
            'baray_environment' => 'sandbox',
            'gateway' => ['sandbox' => $dev, 'live' => $prod],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($dev, CommerceGatewaySetting::forMode('baray', false)->sole()->credentials);
        $this->assertSame($prod, CommerceGatewaySetting::forMode('baray', true)->sole()->credentials);
        $this->assertFalse(CommerceGatewaySetting::checkoutIsLive('baray'));
        $this->assertFalse($method->fresh()->available);

        // Configuration alone must not activate Production.
        $this->patch($save, [
            'available' => false,
            'baray_environment' => 'live',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(CommerceGatewaySetting::checkoutIsLive('baray'));

        $this->patch($save, [
            'available' => false,
            'baray_environment' => 'live',
            'activate_baray_environment' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(CommerceGatewaySetting::checkoutIsLive('baray'));
        $this->assertSame($dev, CommerceGatewaySetting::forMode('baray', false)->sole()->credentials);
        $this->assertSame($prod, CommerceGatewaySetting::forMode('baray', true)->sole()->credentials);
        $this->assertFalse($method->fresh()->available);

        $this->patch($save, [
            'available' => false,
            'baray_environment' => 'sandbox',
            'gateway' => ['sandbox' => ['api_key' => 'baray_dev_rotated']],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('baray_dev_rotated',
            CommerceGatewaySetting::forMode('baray', false)->sole()->credentials['api_key']);
        $this->assertSame($prod, CommerceGatewaySetting::forMode('baray', true)->sole()->credentials);
        $this->assertTrue(CommerceGatewaySetting::checkoutIsLive('baray'));

        // Never leak either environment's key into the admin HTML.
        $this->get(route('dashboard.payment-methods.index'))->assertOk()
            ->assertDontSee('baray_live_test_key')->assertDontSee('baray_dev_rotated');
    }

    public function test_baray_encrypted_checkout_only_fulfills_on_bank_confirmed_webhook(): void
    {
        $key = base64_encode(str_repeat('k', 32));
        $iv = base64_encode(str_repeat('v', 16));
        config(['commerce.baray' => ['api_key' => 'bry_dev_fixture_key', 'sk' => $key, 'iv' => $iv]]);
        [$buyer, $order] = $this->order();
        $method = $this->method('baray');

        Http::fake(function ($request) use ($key, $iv) {
            $this->assertSame('https://api.baray.io/pay', $request->url());
            $this->assertTrue($request->hasHeader('x-api-key', 'bry_dev_fixture_key'));
            $payload = json_decode(openssl_decrypt(base64_decode($request['data'], true),
                'aes-256-cbc', base64_decode($key), OPENSSL_RAW_DATA, base64_decode($iv)), true);
            $this->assertSame('USD', $payload['currency']);
            $this->assertSame('25.00', $payload['amount']);
            $this->assertNotEmpty($payload['order_id']);
            $this->assertStringContainsString('/return', $payload['custom_success_url']);

            return Http::response([
                '_id' => 'itn-fixture-123', 'order_id' => $payload['order_id'],
                'amount' => '25.00', 'currency' => 'USD', 'target' => 'DEVELOPMENT',
            ]);
        });

        $this->actingAs($buyer)
            ->post(route('account.orders.payment.start', $order->number), ['method' => $method->id])
            ->assertRedirect(route('account.orders.show', $order->number));
        $this->get(route('account.orders.show', $order->number))->assertOk()
            ->assertSee('Open secure Baray checkout')
            ->assertSee('https://pay.baray.io/itn-fixture-123')
            ->assertSee('target="_blank"', false)
            ->assertSee('data-baray-status-url=', false);

        $attempt = $order->payments()->sole();
        $this->assertSame('checkout_open', $attempt->status);

        $this->post(route('account.orders.payment.start', $order->number), ['method' => $method->id])
            ->assertRedirect(route('account.orders.show', $order->number));
        Http::assertSentCount(1);
        $statusUrl = route('account.orders.payment.baray-status', [$order->number, $attempt->number]);
        $this->getJson($statusUrl)->assertOk()
            ->assertHeader('Cache-Control', 'private, no-store')
            ->assertJson([
                'payment_status' => 'checkout_open',
                'order_status' => 'awaiting_payment',
                'confirmed' => false,
                'order_url' => route('account.orders.show', $order->number),
            ]);

        // An unrelated account must never inspect a buyer's payment.
        $this->actingAs($this->user('customer'))->getJson($statusUrl)->assertNotFound();
        $this->actingAs($buyer)->getJson($statusUrl)->assertOk()->assertJsonPath('confirmed', false);

        $this->get(route('account.orders.payment.return', [$order->number, $attempt->number]))->assertRedirect();
        $this->post(route('account.orders.payment.verify', [$order->number, $attempt->number]))->assertRedirect();
        $this->assertSame('awaiting_payment', $order->refresh()->status);
        $this->assertNull($order->receipt_number);

        $hook = route('commerce.webhook', 'baray');
        $this->postJson($hook, ['encrypted_order_id' => 'not-a-valid-webhook'])->assertBadRequest();
        $this->assertSame('awaiting_payment', $order->refresh()->status);

        $encrypted = base64_encode(openssl_encrypt($attempt->number,
            'aes-256-cbc', base64_decode($key), OPENSSL_RAW_DATA, base64_decode($iv)));
        $this->postJson($hook, ['encrypted_order_id' => $encrypted, 'bank' => 'aba'])->assertNoContent();
        $this->assertSame('completed', $order->refresh()->status);
        $this->assertSame('baray:itn-fixture-123', $attempt->refresh()->settlement_reference);
        $this->getJson($statusUrl)->assertOk()
            ->assertJsonPath('payment_status', 'paid')
            ->assertJsonPath('order_status', 'completed')
            ->assertJsonPath('confirmed', true);
        $receipt = $order->receipt_number;
        $this->assertNotNull($receipt);

        $this->postJson($hook, ['encrypted_order_id' => $encrypted, 'bank' => 'aba'])->assertNoContent();
        $this->assertSame($receipt, $order->refresh()->receipt_number);
        $this->assertSame('paid', $attempt->refresh()->status);
    }

    public function test_baray_rejects_mismatched_intent_and_does_not_complete_an_order(): void
    {
        config(['commerce.baray' => [
            'api_key' => 'bry_dev_fixture_key',
            'sk' => base64_encode(str_repeat('k', 32)),
            'iv' => base64_encode(str_repeat('v', 16)),
        ]]);
        [$buyer, $order] = $this->order();
        $method = $this->method('baray');
        Http::fake(['https://api.baray.io/pay' => Http::response([
            '_id' => 'itn-fixture-incorrect', 'order_id' => 'different',
            'currency' => 'USD', 'amount' => '25.00', 'target' => 'DEVELOPMENT',
        ])]);

        $this->actingAs($buyer)
            ->post(route('account.orders.payment.start', $order->number), ['method' => $method->id])
            ->assertConflict();
        $this->assertSame('awaiting_payment', $order->refresh()->status);
        $this->assertNull($order->receipt_number);
        $this->assertSame('starting', $order->payments()->sole()->status);
    }

    private function product(User $seller, User $admin): array
    {
        $definition = LicenseDefinition::query()->create([
            'name' => 'Regular License', 'code' => 'MANUAL_PAYMENT_TEST_'.$seller->id, 'version' => '1.0',
            'tier' => 'regular', 'permissions' => 'Use on an approved domain.',
            'restrictions' => 'Redistribution is prohibited.', 'status' => 'active', 'created_by' => $admin->id,
        ]);
        $coverPath = 'test/'.$seller->id.'/cover.png';
        Storage::disk('local')->put($coverPath, 'Approved cover fixture');
        $cover = MediaAsset::query()->create([
            'user_id' => $seller->id, 'original_name' => 'cover.png', 'storage_path' => $coverPath,
            'mime_type' => 'image/png', 'bytes' => 22, 'width' => 10, 'height' => 10, 'rights_basis' => 'owned',
            'alt_text' => 'Product cover', 'rights_holder' => $seller->name, 'review_status' => 'approved',
        ]);
        $product = MarketplaceListing::query()->create([
            'user_id' => $seller->id, 'type' => 'product', 'title' => 'Licensed PHP Toolkit',
            'description' => 'Original files for a product release.', 'currency' => 'USD',
            'regular_price' => '39.00', 'cover_media_id' => $cover->id, 'license_definition_id' => $definition->id, 'status' => 'published',
        ]);
        $content = 'Isolated approved release artifact.';
        $sha256 = hash('sha256', $content);
        $path = 'marketplace/originals/'.$seller->id.'/'.$product->id.'/'.$sha256.'.zip';
        Storage::disk('local')->put($path, $content);
        $release = ProductFileVersion::query()->create([
            'marketplace_listing_id' => $product->id, 'uploaded_by' => $seller->id, 'version' => '1.0',
            'release_notes' => 'Verified release archive.', 'original_filename' => 'approved.zip',
            'storage_path' => $path, 'file_type' => 'zip', 'byte_size' => strlen($content),
            'sha256' => $sha256, 'status' => 'approved', 'reviewed_at' => now(), 'reviewed_by' => $admin->id,
            'rights_attested_at' => now(), 'rights_evidence' => 'Original work owned by seller.',
            'rights_review_status' => 'verified', 'rights_reviewed_at' => now(), 'rights_reviewed_by' => $admin->id,
            'rights_review_note' => 'Original redistribution rights independently reviewed.',
            'malware_scan_status' => 'clean', 'malware_scanned_sha256' => $sha256,
            'malware_scan_engine' => 'ClamAV isolated fixture', 'malware_scanned_at' => now(),
        ]);

        return [$product, $release];
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
