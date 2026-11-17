<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_and_creator_tool_opens_an_authorized_management_page(): void
    {
        config(['commerce.enabled' => true]);

        foreach (['dashboard' => 'super_admin', 'studio' => 'freelancer'] as $area => $role) {
            $route = $area.'.business-tools';
            $page = $this->actingAs($this->user($role))->get(route($route.'.index'))->assertOk()
                ->assertSee('Business Tools')->assertSee('Export orders CSV');
            $entries = array_merge(...array_values($page->viewData('tools')));
            $this->assertGreaterThan(5, count($entries));

            foreach ($entries as $key => $entry) {
                $target = route(explode(':', $key, 2)[0], $entry[2] ?? []);
                $this->get(route($route.'.open', ['tool' => $key]))->assertRedirect($target);
                $this->get($target)->assertOk();
            }

            $this->get(route($route.'.open', ['tool' => 'https://example.test']))->assertNotFound();
        }
    }

    public function test_customer_and_unassigned_staff_cannot_view_or_export_platform_orders(): void
    {
        config(['commerce.enabled' => true]);

        $url = route('dashboard.business-tools.index');
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->user('customer'))->get($url)->assertForbidden();
        $this->get(route('studio.business-tools.index'))->assertForbidden();

        $staff = $this->user('staff');
        $this->actingAs($staff)->get($url)->assertOk()
            ->assertSee('Support Center')->assertDontSee('Export orders CSV')->assertDontSee('Launch checks');
        $this->get(route('dashboard.business-tools.export'))->assertForbidden();
        $this->get(route('dashboard.business-tools.open', ['tool' => 'dashboard.users']))->assertNotFound();
        $this->get(route('dashboard.business-tools.open', ['tool' => 'dashboard.orders.index']))->assertNotFound();

        DB::table('user_permission_overrides')->insert([
            'user_id' => $staff->id, 'permission' => 'commerce_orders', 'allowed' => true,
        ]);
        $this->get($url)->assertOk()->assertSee('Export orders CSV');
        $this->get(route('dashboard.business-tools.export'))->assertDownload('orders-last-30-days.csv');

        config(['commerce.enabled' => false]);
        $this->get($url)->assertOk()->assertDontSee('Export orders CSV');
        $this->get(route('dashboard.business-tools.export'))->assertForbidden();
    }

    public function test_creator_report_and_export_filter_by_seller_date_status_and_currency(): void
    {
        config(['commerce.enabled' => true]);
        $this->travelTo(now()->startOfDay()->addHours(12));
        $buyer = $this->user('customer');
        $seller = $this->user('freelancer');
        $otherSeller = $this->user('freelancer');

        $this->order($buyer, $seller, '=1+1', 'USD', '25.50');
        $this->order($buyer, $seller, 'Euro order', 'EUR', '15.00');
        $this->order($buyer, $seller, 'Pending order', 'USD', '50.00', 'awaiting_payment');
        $this->order($buyer, $seller, 'Older order', 'USD', '99.00')->forceFill(['created_at' => now()->subDays(7)])->save();
        $this->order($buyer, $otherSeller, 'Another seller order', 'USD', '11.25');

        $page = $this->actingAs($seller)->get(route('localized.en.studio.business-tools.index', ['days' => 7]))->assertOk();
        $this->assertEquals(['awaiting_payment' => 1, 'completed' => 2], $page->viewData('counts')->all());
        $this->assertEquals(['EUR' => 15, 'USD' => 25.50], $page->viewData('totals')->all());

        $export = $this->get(route('localized.en.studio.business-tools.export', ['days' => 7]))
            ->assertDownload('orders-last-7-days.csv');
        $this->assertStringContainsString('no-store', $export->headers->get('Cache-Control'));
        $csv = $export->streamedContent();
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString('Pending order', $csv);
        $this->assertStringNotContainsString('Older order', $csv);
        $this->assertStringNotContainsString('Another seller order', $csv);
        $this->assertStringNotContainsString($buyer->email, $csv);

        $adminReport = $this->actingAs($this->user('super_admin'))
            ->get(route('dashboard.business-tools.index', ['days' => 7]))->assertOk();
        $this->assertEquals(['EUR' => 15, 'USD' => 36.75], $adminReport->viewData('totals')->all());
        $this->getJson(route('dashboard.business-tools.export', ['days' => 365]))->assertUnprocessable();
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role), 'email' => Str::uuid().'@example.test',
            'password' => 'local-test-password', 'role' => $role,
        ]);
    }

    private function order(User $buyer, User $seller, string $title, string $currency, string $total, string $status = 'completed'): MarketplaceOrder
    {
        $listing = MarketplaceListing::query()->create([
            'user_id' => $seller->id, 'type' => 'service', 'title' => $title,
            'description' => 'An order report fixture.', 'status' => 'published', 'currency' => $currency, 'price' => $total,
        ]);

        return MarketplaceOrder::query()->create([
            'number' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
            'buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'marketplace_listing_id' => $listing->id,
            'listing_title' => $title, 'buyer_name' => $buyer->name, 'seller_name' => $seller->name,
            'total' => $total, 'currency' => $currency, 'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
        ]);
    }
}
