<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CreatorStudioAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::query()->create([
            'name' => 'Analytics '.ucfirst($role),
            'email' => Str::uuid().'@example.test',
            'password' => 'TestCreatorPassword123!',
            'role' => $role,
        ]);
    }

    public function test_creator_dashboard_keeps_existing_workspace_and_displays_real_data_sections(): void
    {
        $creator = $this->member('freelancer');
        $this->actingAs($creator)->get('/creator')->assertOk()
            ->assertSee('Creator Studio')->assertSee('Approved listings')
            ->assertSee('Business performance')->assertSee('Verified sales vs. goal')
            ->assertSee('Billable vs. non-billable hours')
            ->assertSee('Contract health &amp; milestones', false)
            ->assertSee('Activity heatmap')->assertSee('Open client conversations')
            ->assertSee('Product Manager &amp; Releases', false)
            ->assertSee('creator-studio-analytics.js')->assertSee('creator-studio-analytics.css')
            ->assertDontSee('Apex Retail')->assertDontSee('Lumina Studio');
    }

    public function test_anonymous_and_noncreator_accounts_cannot_access_or_modify_private_analytics(): void
    {
        $url = route('studio.analytics.data', ['period' => 'month', 'type' => 'all']);
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->member('customer'))->get($url)->assertForbidden();
        $this->post(route('studio.analytics.time.store'), [
            'logged_on' => now()->toDateString(), 'hours' => 1,
            'work_type' => 'service', 'is_billable' => 1,
        ])->assertForbidden();
        $this->assertDatabaseCount('creator_studio_time_entries', 0);
    }

    public function test_creator_saves_preferences_and_records_hours_and_expenses_with_real_filters(): void
    {
        $owner = $this->member('freelancer');
        $this->actingAs($owner)->post(route('studio.analytics.preferences'), [
            'monthly_goal_usd' => '3000.00', 'weekly_capacity_hours' => '35',
        ])->assertRedirect();
        $this->post(route('studio.analytics.time.store'), [
            'logged_on' => now()->toDateString(), 'hours' => 2.5,
            'work_type' => 'service', 'is_billable' => 1, 'description' => 'Customer work',
        ])->assertRedirect();
        $this->post(route('studio.analytics.time.store'), [
            'logged_on' => now()->toDateString(), 'hours' => 1,
            'work_type' => 'service', 'is_billable' => 0,
        ])->assertRedirect();
        $this->post(route('studio.analytics.expense.store'), [
            'spent_on' => now()->toDateString(), 'amount_usd' => 13.25,
            'work_type' => 'service', 'category' => 'software',
        ])->assertRedirect();
        $report = $this->getJson(route('studio.analytics.data', [
            'period' => 'month', 'type' => 'all',
        ]))->assertOk();
        $this->assertStringContainsString('private', $report->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $report->headers->get('Cache-Control'));
        $this->assertEqualsWithDelta(2.5, $report->json('billable_hours'), .001);
        $this->assertEqualsWithDelta(1, $report->json('non_billable_hours'), .001);
        $this->assertEqualsWithDelta(13.25, $report->json('total_expenses'), .001);
        $this->assertEqualsWithDelta(35, $report->json('weekly_capacity_hours'), .001);
        $this->assertEqualsWithDelta(3000, $report->json('goal'), .001);
        $this->assertSame(84, count($report->json('heatmap')));
        $this->getJson(route('studio.analytics.data', [
            'period' => 'quarter', 'type' => 'service',
        ]))->assertOk()->assertJsonPath('goal', null)
            ->assertJsonPath('period', 'quarter')->assertJsonPath('type', 'service');
        $this->getJson(route('studio.analytics.data', [
            'period' => 'invalid', 'type' => 'all',
        ]))->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->post(route('studio.analytics.time.store'), [
            'logged_on' => now()->addDay()->toDateString(), 'hours' => 25,
            'work_type' => 'service', 'is_billable' => 1,
        ])->assertSessionHasErrors(['logged_on', 'hours']);
    }

    public function test_a_creator_cannot_read_or_delete_another_creators_private_records(): void
    {
        $owner = $this->member('freelancer');
        $other = $this->member('freelancer');
        $id = DB::table('creator_studio_time_entries')->insertGetId([
            'user_id' => $owner->id, 'logged_on' => now()->toDateString(),
            'hours' => 7, 'is_billable' => 1, 'work_type' => 'service',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $expenseId = DB::table('creator_studio_expenses')->insertGetId([
            'user_id' => $owner->id, 'spent_on' => now()->toDateString(),
            'amount_usd' => 999, 'work_type' => 'project', 'category' => 'equipment',
            'description' => 'Owner-only expense', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($other);
        $response = $this->getJson(route('studio.analytics.data', [
            'period' => 'month', 'type' => 'all',
        ]))->assertOk();
        $this->assertEqualsWithDelta(0, $response->json('billable_hours'), .001);
        $this->assertEqualsWithDelta(0, $response->json('total_expenses'), .001);
        $this->assertSame([], $response->json('recent_time'));
        $this->assertSame([], $response->json('recent_expenses'));
        $this->delete(route('studio.analytics.time.destroy', $id))->assertNotFound();
        $this->delete(route('studio.analytics.expense.destroy', $expenseId))->assertNotFound();
        $this->assertDatabaseHas('creator_studio_time_entries', ['id' => $id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('creator_studio_expenses', ['id' => $expenseId, 'user_id' => $owner->id]);
        $this->actingAs($owner)->delete(route('studio.analytics.time.destroy', $id))->assertRedirect();
        $this->delete(route('studio.analytics.expense.destroy', $expenseId))->assertRedirect();
        $this->assertDatabaseMissing('creator_studio_time_entries', ['id' => $id]);
        $this->assertDatabaseMissing('creator_studio_expenses', ['id' => $expenseId]);
    }

    public function test_revenue_only_uses_verified_completed_sales_owned_by_creator(): void
    {
        config(['commerce.enabled' => true]);
        $seller = $this->member('freelancer');
        $other = $this->member('freelancer');
        $buyer = $this->member('customer');
        $listing = MarketplaceListing::query()->create([
            'user_id' => $seller->id, 'title' => 'Owner real sales fixture',
            'description' => 'Testing verified sale reporting.', 'type' => 'service',
            'status' => 'published', 'currency' => 'USD',
        ]);
        $another = MarketplaceListing::query()->create([
            'user_id' => $other->id, 'title' => 'Another seller fixture',
            'description' => 'Must not be included.', 'type' => 'service',
            'status' => 'published', 'currency' => 'USD',
        ]);
        $this->order($buyer, $seller, $listing, '35.00', 'completed', true);
        $this->order($buyer, $seller, $listing, '10.00', 'completed', true);
        $this->order($buyer, $seller, $listing, '99.00', 'completed', false);
        $this->order($buyer, $seller, $listing, '80.00', 'refunded', true);
        $this->order($buyer, $other, $another, '500.00', 'completed', true);
        $this->actingAs($seller);
        $report = $this->getJson(route('studio.analytics.data', [
            'period' => 'month', 'type' => 'service',
        ]))->assertOk();
        $this->assertEqualsWithDelta(45, $report->json('total_revenue'), .001);
        $this->assertEqualsWithDelta(45, $report->json('repeat_sales'), .001);
        $this->assertEqualsWithDelta(0, $report->json('single_sales'), .001);
        $this->assertSame(4, array_sum($report->json('orders')));
        $this->getJson(route('studio.analytics.data', [
            'period' => 'month', 'type' => 'product',
        ]))->assertOk()->assertJsonPath('total_revenue', 0);
    }

    private function order(User $buyer, User $seller, MarketplaceListing $listing,
        string $amount, string $status, bool $verified): MarketplaceOrder
    {
        $order = MarketplaceOrder::query()->create([
            'number' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
            'buyer_id' => $buyer->id, 'seller_id' => $seller->id,
            'marketplace_listing_id' => $listing->id, 'listing_title' => $listing->title,
            'buyer_name' => $buyer->name, 'seller_name' => $seller->name,
            'total' => $amount, 'currency' => 'USD', 'status' => $status, 'completed_at' => now(),
        ]);
        if ($verified) {
            $method = DB::table('commerce_payment_methods')->where('provider', 'bank_transfer')->first();
            $order->payments()->create([
                'number' => (string) Str::uuid(), 'commerce_payment_method_id' => $method->id,
                'provider' => 'bank_transfer', 'method_name' => $method->name, 'live_mode' => true,
                'status' => 'paid', 'settlement_reference' => 'CREATOR-STUDIO-'.$order->id,
            ]);
        }
        return $order;
    }
}
