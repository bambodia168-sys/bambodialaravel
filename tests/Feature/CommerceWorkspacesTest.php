<?php

namespace Tests\Feature;

use App\Models\CommercePaymentMethod;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->get(route('localized.en.account.orders.receipt', $order->number))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertSee('Original listing')->assertSee('No payment required');
        $this->patch(route('account.orders.cancel', $order->number))->assertConflict();
        $this->actingAs($seller)->get(route('studio.orders.receipt', $order->number))->assertOk();
        $this->get(route('account.orders.receipt', $order->number))->assertNotFound();
        $this->actingAs($this->user('freelancer'))
            ->get(route('studio.orders.receipt', $order->number))->assertNotFound();
        $staff = $this->user('staff');
        $this->actingAs($staff)->get(route('dashboard.orders.show', $order->number))->assertForbidden();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $staff->id, 'permission' => 'commerce_orders', 'allowed' => true,
        ]);
        $this->get(route('dashboard.orders.show', $order->number))->assertOk();
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
