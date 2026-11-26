<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CustomerAccountOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'customer'): User
    {
        return User::query()->create([
            'name' => 'Overview '.ucfirst($role),
            'email' => Str::uuid().'@example.test',
            'password' => 'TestAccountPassword123!',
            'role' => $role,
            'locale' => 'en',
        ]);
    }

    public function test_account_overview_uses_own_buyer_records_and_keeps_private_actions(): void
    {
        config(['commerce.enabled' => true]);
        $buyer = $this->member();
        $other = $this->member();
        $seller = $this->member('freelancer');
        $listing = MarketplaceListing::query()->create([
            'user_id' => $seller->id, 'title' => 'Customer overview fixture',
            'description' => 'An account overview fixture.', 'type' => 'service',
            'status' => 'published', 'currency' => 'USD',
        ]);

        $makeOrder = function (User $owner, string $title, string $status) use ($seller, $listing): MarketplaceOrder {
            return MarketplaceOrder::query()->create([
                'number' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
                'buyer_id' => $owner->id, 'seller_id' => $seller->id,
                'marketplace_listing_id' => $listing->id, 'listing_title' => $title,
                'buyer_name' => $owner->name, 'seller_name' => $seller->name,
                'total' => '12.00', 'currency' => 'USD', 'status' => $status,
            ]);
        };
        $own = $makeOrder($buyer, 'Own private order title', 'awaiting_payment');
        $makeOrder($other, 'Another private buyer order title', 'completed');

        $response = $this->actingAs($buyer)->get('/account?='.$buyer->public_id)
            ->assertOk()->assertViewIs('account.index')
            ->assertSee('customer-account-overview.css')
            ->assertSee('Marketplace activity')->assertSee('Order payment status')
            ->assertSee('Manage your account')->assertSee('Recent notifications')
            ->assertSee('Agreements needing attention')->assertSee('Profile details')
            ->assertSee('Edit profile')->assertSee('My Licenses')
            ->assertSee('Own private order title')->assertDontSee('Another private buyer order title')
            ->assertSee(route('account.orders.show', $own->reference()))
            ->assertSee(route('account.update'))
            ->assertDontSee('creator-studio-analytics.js');

        $this->assertSame([
            'awaiting_payment' => 1, 'completed' => 0, 'refunded' => 0, 'cancelled' => 0,
        ], $response->viewData('orderSummary'));
        $this->assertSame([$own->id], $response->viewData('recentOrders')->modelKeys());

        // Supplying another account's public ID must not switch the authenticated owner.
        $this->get('/account?='.$other->public_id)->assertRedirect('/account?='.$buyer->public_id);

        // A creator's sales must not be counted as personal purchases.
        $this->actingAs($seller)->get('/account?='.$seller->public_id)->assertOk()
            ->assertViewHas('orderSummary', [
                'awaiting_payment' => 0, 'completed' => 0, 'refunded' => 0, 'cancelled' => 0,
            ])->assertDontSee('Own private order title');
    }

    public function test_commerce_disabled_overview_omits_order_controls_and_creator_remains_separate(): void
    {
        config(['commerce.enabled' => false]);
        $buyer = $this->member();
        $this->actingAs($buyer)->get('/account?='.$buyer->public_id)->assertOk()
            ->assertViewHas('orderSummary', null)
            ->assertSee('Account activity breakdown')->assertSee('Manage your account')
            ->assertDontSee('Order payment status')->assertDontSee('Recent orders');

        $creator = $this->member('freelancer');
        $this->actingAs($creator)->get('/creator')->assertOk()
            ->assertSee('creator-studio-analytics.css')->assertSee('Business performance')
            ->assertDontSee('customer-account-overview.css');
    }
}
