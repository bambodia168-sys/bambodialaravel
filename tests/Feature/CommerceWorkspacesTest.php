<?php

namespace Tests\Feature;

use App\Models\CommercePaymentMethod;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MediaAsset;
use App\Models\Policy;
use App\Models\User;
use App\Support\OrderReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CommerceWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspaces_default_to_hidden_but_all_account_roles_can_view_their_order_history_when_enabled(): void
    {
        $this->get(route('account.orders.index'))->assertRedirect(route('login'));
        $this->actingAs($this->user('customer'))->get(route('account.orders.index'))->assertNotFound();
        config(['commerce.enabled' => true]);
        foreach (User::ROLES as $role) {
            $this->actingAs($this->user($role))
                ->get(route('account.orders.index'))->assertOk()->assertSee('No orders found.');
        }
    }

    public function test_zero_total_order_creates_one_receipt_and_scopes_access_to_parties_and_authorized_admin(): void
    {
        config(['commerce.enabled' => true]);
        $buyer = $this->user('customer');
        $seller = $this->user('freelancer');
        $listing = $this->listing($seller, '0.00');
        $this->actingAs($buyer)->get($listing->publicUrl())->assertOk()->assertSee('Add to cart');
        $form = $this->orderForm($listing);
        $this->post(route('account.orders.store', $listing->public_id), [...$form, 'total' => '25.00'])->assertRedirect();
        $order = MarketplaceOrder::query()->sole();
        $this->post(route('account.orders.store', $listing->public_id), $form)
            ->assertRedirect(route('account.orders.show', $order->number));
        $this->assertDatabaseCount('marketplace_orders', 1);
        $this->assertNotNull($order->receipt_number);
        $this->assertSame('0.00', $order->total);
        $listing->update(['title' => 'Renamed listing']);
        $buyer->userProfile()->create(['public_location' => 'Customer receipt city']);
        $seller->userProfile()->create(['public_location' => 'Seller receipt city']);
        $terms = Policy::query()->create([
            'title' => 'Terms of Service', 'body' => 'Receipt terms.', 'category' => 'terms',
            'status' => 'published', 'published_at' => now(),
        ]);
        $this->get(route('localized.en.account.orders.receipt', $order->number))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertSee('Original listing')->assertSee('No payment required')
            ->assertSee('Customer receipt city')->assertSee('Seller receipt city')
            ->assertSee('mailto:'.$buyer->email)->assertDontSee('mailto:'.$seller->email)
            ->assertSee(OrderReceipt::TELEGRAM_URL)->assertSee('/policies/'.$terms->public_code.'-'.$terms->slug);
        $this->patch(route('account.orders.cancel', $order->number))->assertConflict();
        $this->actingAs($seller)->get(route('studio.orders.receipt', $order->number))->assertOk()
            ->assertSee('mailto:'.$seller->email)->assertDontSee('mailto:'.$buyer->email);
        $this->get(route('account.orders.receipt', $order->number))->assertNotFound();
        $this->actingAs($this->user('freelancer'))
            ->get(route('studio.orders.receipt', $order->number))->assertNotFound();
        $this->get(route('studio.orders.show', $order->reference()))->assertNotFound();
        $staff = $this->user('staff');
        $this->actingAs($staff)->get(route('dashboard.orders.show', $order->number))->assertForbidden();
        $this->get(route('dashboard.orders.receipt', $order->reference()))->assertForbidden();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $staff->id, 'permission' => 'commerce_orders', 'allowed' => true,
        ]);
        $this->get(route('dashboard.orders.show', $order->number))->assertOk();
        $this->get(route('dashboard.orders.receipt', $order->reference()))->assertOk()
            ->assertSee('mailto:'.$buyer->email)->assertSee('mailto:'.$seller->email);
        $this->get(OrderReceipt::verificationUrl($order))->assertOk()
            ->assertDontSee($buyer->email)->assertDontSee($seller->email);
        $this->get(route('dashboard.payment-methods.index'))->assertForbidden();
    }

    public function test_locked_price_and_method_availability_are_checked_before_creating_an_unpaid_order(): void
    {
        config(['commerce.enabled' => true]);
        $buyer = $this->user('subscriber');
        $seller = $this->user('freelancer');
        $listing = $this->listing($seller, '20.00');
        $method = CommercePaymentMethod::query()->create(['name' => 'Bank transfer']);
        $this->actingAs($buyer);
        $form = $this->orderForm($listing);
        $listing->update(['price' => '25.00']);
        $store = route('account.orders.store', $listing->public_id);
        $this->post($store, $form)->assertConflict();
        $form = $this->orderForm($listing);
        $this->post($store, [...$form, 'payment_method' => $method->id])->assertNotFound();
        $method->update(['available' => true]);
        $this->post($store, [...$form, 'payment_method' => $method->id])->assertRedirect();
        $order = MarketplaceOrder::query()->sole();
        $this->assertSame('25.00', $order->total);
        $this->assertSame('Bank transfer', $order->payment_method);
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertNull($order->receipt_number);
        $this->get(route('account.orders.receipt', $order->number))->assertNotFound();
        $this->get(route('account.orders.show', $order->number))->assertOk()->assertSee('Update payment preference');
        $this->patch(route('account.orders.payment-method', $order->number), ['payment_method' => null])->assertRedirect();
        $this->assertNull($order->refresh()->payment_method);
        $this->patch(route('account.orders.cancel', $order->number))->assertRedirect();
        $this->patch(route('account.orders.cancel', $order->number))->assertRedirect();
        $this->assertSame('cancelled', $order->refresh()->status);
        $this->get(route('account.orders.index', ['status' => 'cancelled', 'q' => 'Original']))
            ->assertOk()->assertSee('Original listing');
    }

    public function test_a_receipt_verifies_publicly_with_its_qr_token_and_reports_a_refund(): void
    {
        config(['commerce.enabled' => true]);
        $buyer = $this->user('customer');
        $seller = $this->user('freelancer');
        $listing = $this->listing($seller, '12.50');
        $method = CommercePaymentMethod::query()->create(['name' => 'Test bank transfer']);
        $order = MarketplaceOrder::query()->create([
            'number' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
            'buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'marketplace_listing_id' => $listing->id,
            'listing_title' => $listing->title, 'buyer_name' => 'Private fixture buyer', 'seller_name' => 'Private fixture seller',
            'currency' => 'USD', 'total' => '12.50', 'status' => 'completed',
            'payment_method' => $method->name, 'completed_at' => now(), 'receipt_number' => (string) Str::uuid(),
        ]);
        $this->assertMatchesRegularExpression('/^[1-9][0-9]{9}$/', $order->reference());
        $legacy = route('account.orders.receipt', $order->number);
        $receipt = route('account.orders.receipt', $order->reference());
        $verification = OrderReceipt::verificationUrl($order);
        $this->get($verification)->assertNotFound();

        $order->payments()->create([
            'number' => (string) Str::uuid(), 'commerce_payment_method_id' => $method->id,
            'method_name' => $method->name, 'provider' => 'bank_transfer', 'status' => 'paid',
            'live_mode' => true, 'settlement_reference' => 'verified-fixture-balance',
        ]);
        $this->actingAs($this->user('super_admin'))->get(route('dashboard.orders.show', $order->reference()))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Official receipt')
            ->assertSee('Completed / Paid')->assertSee('Payment activity')->assertSee('USD 12.50')
            ->assertSee(route('dashboard.orders.receipt', $order->reference()))->assertSee($verification)
            ->assertSee('data:image/svg+xml;base64,', false)->assertDontSee('verified-fixture-balance');
        foreach ([[$seller, 'studio.orders'], [$buyer, 'account.orders']] as [$viewer, $workspace]) {
            $this->actingAs($viewer)->get(route($workspace.'.show', $order->reference()))
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('Official receipt')
                ->assertSee('Completed / Paid')->assertSee('Payment activity')->assertSee($verification)
                ->assertSee(route($workspace.'.receipt', $order->reference()))
                ->assertDontSee('Review transfer')->assertDontSee('verified-fixture-balance');
        }
        $this->actingAs($buyer)->get($legacy)->assertOk()->assertSee($order->reference());
        $this->get($receipt)->assertOk()->assertSee($verification)->assertSee('data:image/svg+xml;base64,', false)
            ->assertSee('Private fixture buyer');
        $this->get(route('account.orders.index', ['q' => $order->reference()]))->assertOk()->assertSee($listing->title);
        $buyer->userProfile()->create(['public_location' => 'Customer fixture city']);
        $seller->userProfile()->create(['public_location' => 'Seller fixture city']);
        \App\Models\SiteSetting::query()->create(['key' => 'identity.receipt_address', 'value' => 'Public office fixture']);

        $this->get($verification)->assertOk()->assertSee('Private fixture buyer')->assertSee('Customer fixture city')
            ->assertSee('Private fixture seller')->assertSee('Seller fixture city');
        $this->actingAs($this->user('customer'))->get($receipt)->assertNotFound();
        $this->get(route('account.orders.show', $order->reference()))->assertNotFound();
        $this->get($verification)->assertOk()->assertDontSee('Private fixture buyer')->assertDontSee('Customer fixture city')
            ->assertDontSee('Seller fixture city');
        auth()->logout();

        $this->get($verification)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertHeader('Cache-Control', 'no-store, private')->assertSee('Receipt verified')
            ->assertSee($order->reference())->assertSee('USD 12.50')
            ->assertSee('Public office fixture')->assertSee('support@bambodia.com')->assertSee('Join the Telegram group')
            ->assertSee('Verify this receipt')->assertSee('Sign in to view order details')
            ->assertSee($listing->publicUrl())
            ->assertDontSee('Private fixture')->assertSee($listing->title)
            ->assertDontSee($order->number)->assertDontSee($order->receipt_number)
            ->assertDontSee('verified-fixture-balance');
        $this->get(route('commerce.receipts.verify', ['reference' => $order->reference(), 'token' => str_repeat('0', 32)]))
            ->assertNotFound();

        $order->update(['status' => 'refunded']);
        $this->get($verification)->assertOk()->assertSee('Refunded')->assertSee('Total refunded')->assertDontSee('Total paid');
        $listing->update(['status' => 'archived']);
        $this->get($verification)->assertOk()->assertSee('Listing unavailable')->assertDontSee($listing->title);
        $order->update(['status' => 'cancelled']);
        $this->get($verification)->assertNotFound();
    }

    public function test_order_reference_backfill_keeps_legacy_uuids_and_unique_references(): void
    {
        $buyer = $this->user('customer');
        $seller = $this->user('freelancer');
        $listing = $this->listing($seller, '0.00');
        $legacy = [];
        for ($i = 0; $i < 3; $i++) {
            $legacy[] = (string) Str::uuid();
            DB::table('marketplace_orders')->insert([
                'number' => end($legacy), 'request_key' => (string) Str::uuid(),
                'buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'marketplace_listing_id' => $listing->id,
                'listing_title' => $listing->title, 'buyer_name' => $buyer->name, 'seller_name' => $seller->name,
                'currency' => 'USD', 'total' => '0.00', 'status' => 'completed',
            ]);
        }

        $migration = require database_path('migrations/2026_11_22_150000_add_marketplace_order_public_reference.php');
        $migration->up();
        $references = MarketplaceOrder::query()->orderBy('id')->pluck('public_reference', 'number');
        $this->assertSame($legacy, $references->keys()->all());
        $this->assertCount(3, $references->unique());
        foreach ($references as $reference) {
            $this->assertMatchesRegularExpression('/^[1-9][0-9]{9}$/', $reference);
        }
        $migration->up();
        $this->assertSame($references->all(), MarketplaceOrder::query()->orderBy('id')->pluck('public_reference', 'number')->all());
    }

    public function test_super_admin_can_register_and_toggle_payment_methods(): void
    {
        config(['commerce.enabled' => true]);
        $page = route('dashboard.payment-methods.index');
        $save = route('dashboard.payment-methods.store');
        $popup = $this->actingAs($this->user('super_admin'))->get($page)->assertOk()
            ->assertSee('data-modal-open="add-payment-method"', false)
            ->assertSee('Add manual payment method');
        $reopen = '/<dialog\b[^>]*id="add-payment-method"[^>]*\bdata-payment-reopen\b/';
        $this->assertDoesNotMatchRegularExpression($reopen, $popup->getContent());
        $this->from($page)->post($save, ['name' => 'Local bank transfer', 'provider' => 'stripe'])
            ->assertRedirect($page)->assertSessionHasErrorsIn('addPaymentMethod', 'provider');
        $this->assertDatabaseMissing('commerce_payment_methods', ['name' => 'Local bank transfer']);
        $popup = $this->withCookie(config('session.cookie'), session()->getId())->get($page)->assertOk()
            ->assertSee('id="add-payment-method-provider-error"', false)->assertSee('value="Local bank transfer"', false);
        $this->assertMatchesRegularExpression($reopen, $popup->getContent());
        $this->post($save, ['name' => 'Local bank transfer', 'provider' => 'bank_transfer', 'available' => true])
            ->assertRedirect($page);
        $method = CommercePaymentMethod::query()->where('name', 'Local bank transfer')->sole();
        $this->assertFalse($method->available);
        $this->assertSame('bank_transfer', $method->provider);
        $this->get($page)->assertOk()->assertSee('Local bank transfer');
        $this->patch(route('dashboard.payment-methods.update', $method), ['available' => true])->assertRedirect();
        $this->assertTrue($method->refresh()->available);
    }

    public function test_product_orders_use_the_selected_license_price_and_reject_preview_listings(): void
    {
        config(['commerce.enabled' => true]);
        $creator = $this->user('freelancer');
        $admin = $this->user('super_admin');
        $definitions = [];
        foreach (['regular', 'extended'] as $tier) {
            $definitions[$tier] = LicenseDefinition::query()->create([
                'code' => strtoupper($tier).'_COMMERCE', 'name' => ucfirst($tier).' product license',
                'tier' => $tier, 'version' => '1.0.0', 'permissions' => 'Sample license permissions.',
                'restrictions' => 'Sample license restrictions.', 'status' => 'active', 'created_by' => $admin->id,
            ]);
        }
        $cover = MediaAsset::query()->create([
            'user_id' => $creator->id, 'original_name' => 'cover.png', 'storage_path' => 'test/cover.png',
            'mime_type' => 'image/png', 'bytes' => 100, 'width' => 10, 'height' => 10, 'rights_basis' => 'owned',
            'alt_text' => 'Product cover', 'rights_holder' => $creator->name, 'review_status' => 'approved',
        ]);
        $product = $this->listing($creator, '29.00');
        $product->update([
            'type' => 'product', 'regular_price' => '29.00', 'extended_price' => '99.00', 'cover_media_id' => $cover->id,
            'license_definition_id' => $definitions['regular']->id, 'extended_license_definition_id' => $definitions['extended']->id,
        ]);
        $this->actingAs($creator)->get(route('account.orders.create', $product->public_id))->assertForbidden();
        $this->actingAs($this->user('customer'));
        $form = $this->orderForm($product, 'extended');
        $this->post(route('account.orders.store', $product->public_id), [...$form, 'tier' => 'regular'])->assertConflict();
        $this->post(route('account.orders.store', $product->public_id), $form)->assertRedirect();
        $this->assertDatabaseHas('marketplace_orders', ['license_tier' => 'extended', 'total' => '99.00']);
        $product->update(['title' => '[LAYOUT TEST] Sample product']);
        $this->get(route('account.orders.create', $product->public_id))->assertNotFound();
    }

    private function orderForm(MarketplaceListing $listing, string $tier = 'standard'): array
    {
        $response = $this->get(route('account.orders.create', $listing->public_id))->assertOk();
        $content = $response->getOriginalContent();

        return [
            'tier' => $tier,
            'request_key' => $content->getData()['requestKey'],
            'quote' => $content->getData()['quotes'][$tier]['signature'],
        ];
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role), 'email' => uniqid($role).'@example.test',
            'password' => 'test-only-password', 'role' => $role,
        ]);
    }

    private function listing(User $seller, string $price): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id' => $seller->id, 'type' => 'service', 'title' => 'Original listing',
            'description' => 'Service description', 'status' => 'published', 'currency' => 'USD', 'price' => $price,
        ]);
    }
}
