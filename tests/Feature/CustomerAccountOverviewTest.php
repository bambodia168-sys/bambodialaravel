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
            ->assertSee('Action center')->assertDontSee('Manage your account')->assertSee('Recent notifications')
            ->assertSee('Agreements needing attention')->assertSee('Profile details')
            ->assertSee('Edit profile')->assertSee('My Licenses')
            ->assertSee('Own private order title')->assertDontSee('Another private buyer order title')
            ->assertSee('Payment pending')->assertSee('Review payment')->assertSee('Verify your email address')
            ->assertSee('Turn your skills into opportunities.')->assertSee(route('account.security'))
            ->assertDontSee('workspace-quick-actions', false)
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
            ])->assertDontSee('Own private order title')->assertDontSee('Payment pending')
            ->assertDontSee('Turn your skills into opportunities.');
    }

    public function test_commerce_disabled_overview_omits_order_controls_and_creator_remains_separate(): void
    {
        config(['commerce.enabled' => false]);
        $buyer = $this->member();
        $this->actingAs($buyer)->get('/account?='.$buyer->public_id)->assertOk()
            ->assertViewHas('orderSummary', null)
            ->assertSee('Account activity breakdown')->assertSee('Action center')->assertDontSee('Manage your account')
            ->assertDontSee('Order payment status')->assertDontSee('Recent orders')
            ->assertSee('No account actions need attention right now.')->assertDontSee('Verify your email address');

        $creator = $this->member('freelancer');
        $this->actingAs($creator)->get('/creator')->assertOk()
            ->assertSee('creator-studio-analytics.css')->assertSee('Business performance')
            ->assertDontSee('customer-account-overview.css');
    }

    public function test_action_center_only_promotes_unread_replies_to_owned_open_tickets(): void
    {
        config(['commerce.enabled' => false]);
        $buyer = $this->member();
        $other = $this->member();
        $open = \App\Models\SupportTicket::query()->create(['user_id'=>$buyer->id,'subject'=>'Own open support request','status'=>'open']);
        $closed = \App\Models\SupportTicket::query()->create(['user_id'=>$buyer->id,'subject'=>'Closed support request','status'=>'closed']);
        $read = \App\Models\SupportTicket::query()->create(['user_id'=>$buyer->id,'subject'=>'Already read support request','status'=>'pending']);
        $foreign = \App\Models\SupportTicket::query()->create(['user_id'=>$other->id,'subject'=>'Foreign private support request','status'=>'open']);
        $notify = fn ($ticket, $owner, $readAt = null) => \App\Models\AccountNotification::query()->create([
            'user_id'=>$owner->id,'event'=>'support.replied','title'=>'Support team replied',
            'message'=>'Review the ticket when convenient.',
            'target_path'=>'/account/support-tickets/'.$ticket->public_id,'read_at'=>$readAt,
        ]);
        $notice = $notify($open, $buyer);
        $notify($closed, $buyer);
        $notify($read, $buyer, now());
        $notify($foreign, $other);
        $notify($foreign, $buyer); // A stale or incorrect notification must not expose a foreign ticket subject.
        $response = $this->actingAs($buyer)->get('/account?='.$buyer->public_id)->assertOk()
            ->assertSee('New support reply')->assertSee('Own open support request')
            ->assertDontSee('Closed support request')->assertDontSee('Already read support request')
            ->assertDontSee('Foreign private support request');
        $this->assertSame([$notice->id], $response->viewData('supportReplies')->pluck('notice.id')->all());
        $this->patch(route('account.notifications.read', $notice))->assertRedirect($notice->target_path);
        $this->get('/account?='.$buyer->public_id)->assertOk()->assertDontSee('New support reply');
    }

    public function test_creator_invitation_reflects_existing_application(): void
    {
        config(['commerce.enabled' => false]);
        $buyer = $this->member();
        $application = \App\Models\CreatorApplication::query()->create([
            'user_id'=>$buyer->id,'specialization'=>'Web development','experience'=>str_repeat('Experience. ',8),'status'=>'pending',
        ]);
        $this->actingAs($buyer)->get('/account?='.$buyer->public_id)->assertOk()
            ->assertSee('Your creator application is under review.')->assertSee('View application')
            ->assertDontSee('Turn your skills into opportunities.');
        $application->update(['status'=>'rejected']);
        $this->get('/account?='.$buyer->public_id)->assertOk()->assertSee('Ready to try again?')->assertSee('Review application');
    }
}
