<?php

namespace Tests\Feature;

use App\Http\Controllers\CommerceCartController;
use App\Models\CommercePaymentMethod;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MediaAsset;
use App\Models\Policy;
use App\Models\PolicyPublication;
use App\Models\ProductFileVersion;
use App\Models\User;
use App\Notifications\CheckoutVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class PaidCommerceOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.enabled' => true, 'commerce.payments_enabled' => true, 'commerce.operations_enabled' => true]);
        Storage::fake('local');
    }

    public function test_guest_can_verify_the_rendered_email_purchase_and_download_the_approved_release(): void
    {
        config(['mail.default' => 'array']);
        $admin = $this->member('super_admin');
        $seller = $this->member('freelancer');
        [$product, $release] = $this->product($seller, $admin);
        $method = $this->method();
        $policy = Policy::query()->create([
            'title' => 'Checkout Terms', 'category' => 'terms', 'status' => 'published',
            'jurisdiction' => 'Cambodia', 'body' => 'Isolated checkout acceptance terms.',
        ]);
        $terms = PolicyPublication::query()->create([
            'policy_id' => $policy->id, 'revision' => 1, 'title' => $policy->title,
            'category' => 'terms', 'jurisdiction' => 'Cambodia', 'body_snapshot' => $policy->body,
            'sha256' => PolicyPublication::digestFor($policy), 'published_by' => $admin->id, 'created_at' => now(),
        ]);

        $this->post(route('cart.add', $product->public_id), ['tier' => 'regular'])->assertRedirect(route('cart'));
        $this->get(route('checkout', ['account' => 'register']))->assertOk();
        $this->post(route('register.store'), [
            'name' => 'Paid Checkout Buyer', 'email' => 'paid-checkout@example.test',
            'password' => 'PaidCheckoutTesting123!', 'password_confirmation' => 'PaidCheckoutTesting123!',
            'terms_accepted' => '1', 'terms_publication_id' => $terms->id,
        ])->assertRedirect(route('checkout.verification'));
        $buyer = User::query()->where('email', 'paid-checkout@example.test')->sole();
        $this->post(route('checkout.place', $product->public_id))->assertRedirect(route('checkout.verification'));
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->sole()->getOriginalMessage();
        $this->assertSame($buyer->email, $email->getTo()[0]->getAddress());
        $this->assertMatchesRegularExpression('/href="([^"]+checkout\/verify-email\/[^"]+)"/', $email->getHtmlBody());
        preg_match('/href="([^"]+checkout\/verify-email\/[^"]+)"/', $email->getHtmlBody(), $link);
        $this->get(html_entity_decode($link[1]))->assertRedirect(route('cart'));
        $this->assertTrue($buyer->fresh()->hasVerifiedEmail());

        $order = $this->placeAndSubmit($buyer->fresh(), $product, $method);
        $this->assertSame($release->id, $order->fulfillment_version_id);
        $this->assertDatabaseCount('product_licenses', 0);
        $this->approve($order, $admin);
        $this->assertSame('completed', $order->refresh()->status);
        $grant = $order->entitlement()->sole();
        $license = $grant->license()->sole();
        $this->assertSame('verified_purchase', $license->source);
        $this->assertSame('regular', $license->tier);
        $this->assertSame($buyer->id, $license->user_id);

        $this->actingAs($buyer->fresh())->get(route('account.orders.show', $order->number))->assertOk()
            ->assertSee('Digital delivery')->assertSee('Available')->assertSee('My licenses');
        $download = route('account.downloads.file', $grant);
        $this->get($download)->assertDownload('bambodia-'.$product->public_id.'-1.0.zip');
        $this->actingAs($this->member('customer'))->get($download)->assertNotFound();
        $this->actingAs($admin)->post(route('dashboard.orders.retry-delivery', $order->number))->assertRedirect();
        $this->assertDatabaseCount('product_licenses', 1);
        $this->assertDatabaseCount('product_entitlements', 1);
    }

    public function test_refunds_reconciliation_payout_recovery_and_seller_scoping(): void
    {
        $admin = $this->member('super_admin');
        $seller = $this->member('freelancer');
        $buyer = $this->member('customer');
        [$product] = $this->product($seller, $admin);
        $order = $this->placeAndSubmit($buyer, $product, $this->method());
        $this->approve($order, $admin);
        $order->refresh();
        $grant = $order->entitlement()->sole();
        $number = $order->number;
        $settlement = route('dashboard.commerce-operations.store', $number);
        $refund = route('account.orders.refund.store', $number);
        $reference = $order->paidAttempt()->sole()->settlement_reference;

        $this->actingAs($this->member('admin'))->post($settlement, ['action' => 'reconcile'])->assertForbidden();
        $this->actingAs($this->member('customer'))->post($refund, [
            'action' => 'request_refund', 'note' => 'This transaction is not mine.',
        ])->assertNotFound();
        $this->actingAs($admin)->get(route('dashboard.commerce-operations.show', [$number, 'action' => 'reconcile']))
            ->assertOk()->assertSee('External statement line reference')->assertSee($reference);
        $match = [
            'action' => 'reconcile', 'reference' => 'STATEMENT-019', 'amount' => '39.00',
            'seller_payable' => '33.75', 'payment_reference' => $reference,
            'note' => 'Matched independently to the bank statement.', 'confirmed' => '1',
        ];
        $this->post($settlement, [...$match, 'payment_reference' => 'wrong-payment'])->assertUnprocessable();
        $this->assertDatabaseCount('commerce_order_operations', 0);
        $this->post($settlement, [...$match, 'seller_payable' => '99.00'])->assertUnprocessable();
        $this->post($settlement, $match)->assertRedirect();
        $this->post($settlement, $match)->assertConflict();

        $payout = [
            'action' => 'record_payout', 'reference' => 'PAYOUT-019', 'amount' => '33.75',
            'note' => 'Settlement confirmed by the receiving bank.', 'confirmed' => '1',
        ];
        $this->actingAs($buyer)->post($refund, ['action' => 'request_refund', 'note' => 'Please review this purchase.'])->assertRedirect();
        $this->get(route('account.orders.refund', $number))->assertOk()->assertSee('Requested');
        $this->actingAs($admin)->post($settlement, $payout)->assertConflict();
        $this->post($settlement, ['action' => 'reject_refund', 'note' => 'The cited reason was reviewed.'])->assertRedirect();
        $this->post($settlement, [...$payout, 'amount' => '33.74'])->assertUnprocessable();
        $this->post($settlement, $payout)->assertRedirect();
        $this->post($settlement, $payout)->assertConflict();

        $this->actingAs($seller)->get(route('studio.commerce-operations'))->assertOk()->assertSee($number)->assertSee('Paid out');
        $this->get(route('studio.orders.show', $number))->assertOk()->assertSee('PAYOUT-019');
        $this->actingAs($this->member('freelancer'))->get(route('studio.commerce-operations'))
            ->assertOk()->assertDontSee($number);

        $this->actingAs($admin)->post($settlement, [
            'action' => 'record_refund', 'reference' => 'REFUND-019', 'amount' => '39.00',
            'note' => 'Full refund confirmed on provider statement.', 'confirmed' => '1',
        ])->assertRedirect();
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertTrue($order->operations->recoveryDue());
        $this->get(route('dashboard.commerce-operations', ['queue' => 'recovery_due']))->assertOk()->assertSee($number);
        $this->get(route('dashboard.commerce-operations', ['queue' => 'paid_out']))->assertOk()->assertDontSee($number);
        $this->assertSame('revoked', $grant->refresh()->status);
        $this->assertSame('revoked', $grant->license->status);
        $this->actingAs($buyer)->get(route('account.downloads.file', $grant))->assertForbidden();
        $this->get(route('account.orders.receipt', $number))->assertOk()->assertSee('Refunded');
        $this->actingAs($admin)->post($settlement, [
            'action' => 'record_recovery', 'reference' => 'RECOVERY-019', 'amount' => '33.75',
            'note' => 'Seller repaid the recorded payout in full.', 'confirmed' => '1',
        ])->assertRedirect();
        $this->assertFalse($order->operations()->sole()->recoveryDue());
        $this->get(route('dashboard.commerce-operations', ['queue' => 'recovered']))->assertOk()->assertSee($number);
        $this->assertDatabaseCount('commerce_operation_events', 6);

        $newOrder = $this->placeAndSubmit($buyer, $product, $this->method());
        $this->approve($newOrder, $admin);
        $this->assertTrue($newOrder->entitlement()->sole()->isActive());
        $this->assertFalse($grant->refresh()->isActive());
        $this->assertDatabaseCount('product_entitlements', 2);
    }

    public function test_an_unavailable_release_blocks_buying_and_a_verified_payment_can_retry_delivery(): void
    {
        $admin = $this->member('super_admin');
        $buyer = $this->member('customer');
        [$product, $release] = $this->product($this->member('freelancer'), $admin);
        $release->update(['status' => 'pending']);
        $this->actingAs($buyer)->postJson(route('cart.add', $product->public_id), ['tier' => 'regular'])->assertUnprocessable();
        $release->update(['status' => 'approved']);
        $order = $this->placeAndSubmit($buyer, $product, $this->method());
        $release->update(['status' => 'pending']);
        $this->approve($order, $admin);
        $this->assertSame('completed', $order->refresh()->status);
        $this->assertDatabaseCount('product_entitlements', 0);
        $this->get(route('dashboard.orders.show', $order->number))->assertOk()->assertSee('Retry delivery');
        $release->update(['status' => 'approved']);
        $this->post(route('dashboard.orders.retry-delivery', $order->number))->assertRedirect();
        $this->assertDatabaseCount('product_entitlements', 1);
    }

    public function test_settlement_filters_and_exports_use_verified_seller_scoped_orders(): void
    {
        $admin = $this->member('super_admin');
        $seller = $this->member('freelancer');
        $otherSeller = $this->member('freelancer');
        $buyer = $this->member('customer');
        [$product] = $this->product($seller, $admin);
        [$otherProduct] = $this->product($otherSeller, $admin);
        $method = $this->method();

        $matched = $this->placeAndSubmit($buyer, $product, $method);
        $this->approve($matched, $admin);
        $matched->update(['listing_title' => ' =SUM(1,2)', 'completed_at' => '2026-11-17 23:59:59']);
        $matched->operations()->create([
            'commerce_payment_id' => $matched->paidAttempt()->sole()->id,
            'statement_reference' => 'PRIVATE-STATEMENT-01', 'seller_payable' => '31.25',
            'reconciled_at' => now(), 'refund_note' => 'Private review details',
        ]);

        $unmatched = $this->placeAndSubmit($buyer, $product, $method);
        $this->approve($unmatched, $admin);
        $unmatched->update(['completed_at' => '2026-11-17 00:00:00']);
        $outside = $this->placeAndSubmit($buyer, $product, $method);
        $this->approve($outside, $admin);
        $outside->update(['completed_at' => '2026-11-18 00:00:00']);
        $unpaid = $this->placeAndSubmit($this->member('customer'), $product, $method);
        $foreign = $this->placeAndSubmit($this->member('customer'), $otherProduct, $method);
        $this->approve($foreign, $admin);
        $foreign->update(['completed_at' => '2026-11-17 11:00:00']);

        $index = 'studio.commerce-operations';
        $criteria = ['from' => '2026-11-17', 'to' => '2026-11-17'];
        $this->actingAs($seller)->get(route($index, $criteria))->assertOk()
            ->assertSee($matched->number)->assertSee($unmatched->number)->assertSee('Download CSV')
            ->assertDontSee($foreign->number)->assertDontSee($outside->number)->assertDontSee($unpaid->number);
        $this->get(route($index, $criteria + ['queue' => 'unmatched']))->assertOk()
            ->assertSee($unmatched->number)->assertDontSee($matched->number);
        $this->getJson(route($index, ['from' => '2026-11-18', 'to' => '2026-11-17']))->assertUnprocessable()
            ->assertJsonValidationErrors('to');
        $this->getJson(route($index.'.statement', ['queue' => 'not-a-status']))->assertUnprocessable()
            ->assertJsonValidationErrors('queue');

        $csv = $this->get(route($index.'.statement', $criteria + ['queue' => 'payout_pending', 'page' => 2]))
            ->assertOk()->assertDownload('bambodia-sales-settlements.csv');
        $rows = $csv->streamedContent();
        $this->assertStringContainsString('no-store', $csv->headers->get('Cache-Control'));
        $this->assertStringContainsString($matched->number.',"\' =SUM(1,2)"', $rows);
        $this->assertStringContainsString('"Payout pending",31.25', $rows);
        foreach ([$unmatched->number, $foreign->number, $outside->number, $unpaid->number,
            'PRIVATE-STATEMENT-01', 'Private review details', $buyer->email] as $private) {
            $this->assertStringNotContainsString($private, $rows);
        }
        $this->assertStringNotContainsString($foreign->number, $this->get(route($index.'.statement', [
            'order' => $foreign->number,
        ]))->streamedContent());
        $this->assertStringNotContainsString($unpaid->number, $this->get(route($index.'.statement', [
            'order' => $unpaid->number,
        ]))->streamedContent());

        $adminCsv = route('dashboard.commerce-operations.statement', $criteria);
        $this->actingAs($seller)->get($adminCsv)->assertForbidden();
        $this->actingAs($this->member('admin'))->get($adminCsv)->assertForbidden();
        $this->actingAs($admin)->get(route('dashboard.commerce-operations', $criteria))->assertOk()
            ->assertSee($foreign->number);
        $adminRows = $this->get($adminCsv)->assertOk()->assertDownload('bambodia-commerce-settlements.csv')->streamedContent();
        $this->assertStringContainsString($foreign->number, $adminRows);
        $this->assertStringContainsString($matched->number, $adminRows);
        $this->assertStringNotContainsString($outside->number, $adminRows);
        $matched->paidAttempt()->sole()->update(['live_mode' => false]);
        $this->app->instance('env', 'production');
        try {
            $liveRows = $this->get($adminCsv)->streamedContent();
            $this->assertStringNotContainsString($matched->number, $liveRows);
            $this->assertStringContainsString($foreign->number, $liveRows);
        } finally {
            $this->app->instance('env', 'testing');
        }

        config(['commerce.operations_enabled' => false]);
        $this->get($adminCsv)->assertNotFound();
        $this->actingAs($seller)->get(route($index.'.statement'))->assertNotFound();
    }

    public function test_checkout_email_respects_the_effective_mail_transport_in_production(): void
    {
        $buyer = $this->member('customer');
        $buyer->forceFill(['email_verified_at' => null])->save();
        config(['mail.default' => 'log']);
        Notification::fake();
        $this->app->instance('env', 'production');
        try {
            CommerceCartController::verificationEmail($buyer);
            Notification::assertNothingSent();

            $mail = Mockery::mock(\Illuminate\Mail\MailManager::class);
            $mail->shouldReceive('getDefaultDriver')->once()->andReturn('dashboard-smtp');
            $this->app->instance('mail.manager', $mail);
            CommerceCartController::verificationEmail($buyer);
            Notification::assertSentTo($buyer, CheckoutVerification::class);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    private function member(string $role): User
    {
        $user = User::query()->create([
            'name' => ucfirst($role), 'email' => Str::uuid().'@example.test',
            'password' => 'PaidCheckoutTesting123!', 'role' => $role,
        ]);
        $user->markEmailAsVerified();

        return $user;
    }

    private function product(User $seller, User $admin): array
    {
        $definition = LicenseDefinition::query()->create([
            'name' => 'Regular License', 'code' => 'PAID_TEST_'.$seller->id, 'version' => '1.0',
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

    private function method(): CommercePaymentMethod
    {
        $method = CommercePaymentMethod::query()->where('provider', 'bank_transfer')->sole();
        $method->update(['available' => true, 'instructions' => 'Use the test bank account.']);

        return $method;
    }

    private function placeAndSubmit(User $buyer, MarketplaceListing $product, CommercePaymentMethod $method): MarketplaceOrder
    {
        $this->actingAs($buyer)->post(route('cart.add', $product->public_id), ['tier' => 'regular'])->assertRedirect();
        $checkout = $this->get(route('checkout'))->assertOk()->assertSee('USD 39.00')->assertSee('Continue to payment');
        $item = $checkout->viewData('lines')[$product->public_id];
        $this->post(route('checkout.place', $product->public_id), [
            'tier' => $item['tier'], 'request_key' => $item['request_key'], 'quote' => $item['current']['signature'],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $order = MarketplaceOrder::query()->latest('id')->firstOrFail();
        $this->post(route('account.orders.payment.submit', $order->number), [
            'method' => $method->id, 'reference' => 'BANK-'.$order->id,
            'proof' => UploadedFile::fake()->image('proof.png'),
        ])->assertRedirect();

        return $order;
    }

    private function approve(MarketplaceOrder $order, User $admin): void
    {
        $this->actingAs($admin)->patch(route('dashboard.orders.payment.review', [$order->number, $order->payments()->sole()->number]), [
            'decision' => 'approve', 'verified_reference' => 'BANK-'.$order->id,
            'verified_amount' => '39.00', 'note' => 'Confirmed exact amount on isolated bank statement.',
        ])->assertRedirect();
    }
}
