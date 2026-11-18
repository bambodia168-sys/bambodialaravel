<?php

namespace Tests\Feature;

use App\Models\CommercePaymentMethod;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MediaAsset;
use App\Models\ProductFileVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_a_paid_product_is_matched_and_refunded_exactly_once(): void
    {
        $admin = $this->member('super_admin');
        $seller = $this->member('freelancer');
        $buyer = $this->member('customer');
        [$product] = $this->product($seller, $admin);
        $order = $this->placeAndSubmit($buyer, $product, $this->method());
        $this->approve($order, $admin);
        $grant = $order->entitlement()->sole();
        $number = $order->number;
        $settlement = route('dashboard.commerce-operations.store', $number);
        $refund = route('account.orders.refund.store', $number);
        $download = route('account.downloads.file', $grant);
        $reference = $order->paidAttempt()->sole()->settlement_reference;

        $this->actingAs($buyer)->get($download)->assertOk();
        $this->get(route('account.orders.show', $number))->assertOk()->assertSee('Refund details');
        $this->actingAs($this->member('admin'))->post($settlement, ['action' => 'reconcile'])->assertForbidden();
        $this->actingAs($this->member('customer'))->post($refund, [
            'action' => 'request_refund', 'note' => 'This transaction belongs to another buyer.',
        ])->assertNotFound();
        $this->actingAs($admin)->get(route('dashboard.commerce-operations.show', [$number, 'action' => 'reconcile']))
            ->assertOk()->assertSee('External statement line reference')->assertSee($reference);
        $match = [
            'action' => 'reconcile', 'reference' => 'STATEMENT-019', 'amount' => '39.00',
            'seller_payable' => '33.75', 'payment_reference' => $reference,
            'note' => 'Matched independently to the simulated bank statement.', 'confirmed' => '1',
        ];
        $this->post($settlement, [...$match, 'payment_reference' => 'wrong-payment'])->assertUnprocessable();
        $this->post($settlement, [...$match, 'seller_payable' => '99.00'])->assertUnprocessable();
        $this->assertDatabaseCount('commerce_order_operations', 0);
        $this->post($settlement, $match)->assertRedirect();
        $this->post($settlement, $match)->assertConflict();
        $this->get(route('dashboard.commerce-operations'))->assertOk()->assertSee('Manage settlement');

        $request = ['action' => 'request_refund', 'note' => 'Please review this purchase.'];
        $this->actingAs($buyer)->post($refund, $request)->assertRedirect();
        $this->post($refund, $request)->assertConflict();
        $this->get(route('account.orders.refund', $number))->assertOk()->assertSee('Requested')
            ->assertDontSee($reference)->assertDontSee('STATEMENT-019');
        $this->get($download)->assertOk();
        $this->actingAs($seller)->get(route('studio.commerce-operations'))->assertOk()
            ->assertSee($number)->assertSee('On hold')->assertDontSee('Manage settlement');
        $this->actingAs($this->member('freelancer'))->get(route('studio.commerce-operations'))
            ->assertOk()->assertDontSee($number);

        $full = [
            'action' => 'record_refund', 'reference' => 'REFUND-019', 'amount' => '39.00',
            'note' => 'Full refund confirmed on the simulated provider statement.', 'confirmed' => '1',
        ];
        $this->actingAs($admin)->post($settlement, [...$full, 'amount' => '38.99'])->assertUnprocessable();
        $this->assertSame('completed', $order->refresh()->status);
        $this->post($settlement, $full)->assertRedirect();
        $this->post($settlement, $full)->assertConflict();
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertSame('33.75', $order->operations()->sole()->seller_payable);
        $this->assertSame('revoked', $grant->refresh()->status);
        $this->assertSame('revoked', $grant->license->status);
        $this->assertDatabaseCount('commerce_operation_events', 3);
        $this->assertDatabaseHas('account_notifications', [
            'user_id' => $buyer->id, 'event' => 'commerce.refund.updated',
        ]);
        $this->actingAs($buyer)->get($download)->assertForbidden();
        $this->get(route('account.orders.receipt', $number))->assertOk()->assertSee('Refunded in full');
        $this->actingAs($seller)->get(route('studio.commerce-operations'))->assertOk()
            ->assertSee($number)->assertSee('Cancelled after refund')->assertDontSee('33.75');
        $csv = $this->get(route('studio.commerce-operations.statement'))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Cancelled after refund",0.00', $csv);
    }

    public function test_finance_switch_declined_refunds_and_statement_matching_after_a_refund(): void
    {
        $admin = $this->member('super_admin');
        $buyer = $this->member('customer');
        [$product] = $this->product($this->member('freelancer'), $admin);
        $order = $this->placeAndSubmit($buyer, $product, $this->method());
        $this->approve($order, $admin);
        $settlement = route('dashboard.commerce-operations.store', $order->number);
        $refund = route('account.orders.refund.store', $order->number);

        config(['commerce.operations_enabled' => false]);
        $this->actingAs($buyer)->post($refund, ['action' => 'request_refund', 'note' => 'Please review this purchase.'])
            ->assertNotFound();
        $this->get(route('account.orders.show', $order->number))->assertOk()->assertDontSee('Refund details');
        $this->actingAs($admin)->get(route('dashboard.commerce-operations'))->assertOk();
        config(['commerce.operations_enabled' => true, 'commerce.payments_enabled' => false]);

        $this->actingAs($buyer)->post($refund, ['action' => 'request_refund', 'note' => 'Please review this purchase.'])
            ->assertRedirect();
        $this->actingAs($admin)->post($settlement, ['action' => 'reject_refund', 'note' => 'Request evidence requires correction.'])
            ->assertRedirect();
        $grant = $order->entitlement()->sole();
        $this->assertTrue($grant->isActive());
        $this->assertSame('declined', $order->operations()->sole()->refund_status);
        $this->post($settlement, [
            'action' => 'record_refund', 'reference' => 'REFUND-EARLY', 'amount' => '39.00',
            'note' => 'Full refund confirmed after receiving corrected evidence.', 'confirmed' => '1',
        ])->assertRedirect();
        $this->post($settlement, [
            'action' => 'reconcile', 'reference' => 'STATEMENT-LATE', 'amount' => '39.00',
            'seller_payable' => '0.00', 'payment_reference' => $order->paidAttempt()->sole()->settlement_reference,
            'note' => 'Recorded late matching for the original collected payment.', 'confirmed' => '1',
        ])->assertRedirect();
        $this->assertSame('refunded', $order->refresh()->status);
        $this->assertNotNull($order->operations()->sole()->reconciled_at);
        $this->assertSame('revoked', $grant->fresh()->status);
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
            'name' => 'Regular License', 'code' => 'PAID_TEST', 'version' => '1.0',
            'tier' => 'regular', 'permissions' => 'Use on an approved domain.',
            'restrictions' => 'Redistribution is prohibited.', 'status' => 'active', 'created_by' => $admin->id,
        ]);
        Storage::disk('local')->put('test/cover.png', 'Approved cover fixture');
        $cover = MediaAsset::query()->create([
            'user_id' => $seller->id, 'original_name' => 'cover.png', 'storage_path' => 'test/cover.png',
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
