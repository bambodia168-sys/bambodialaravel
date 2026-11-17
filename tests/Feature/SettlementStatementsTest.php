<?php

namespace Tests\Feature;

use App\Models\CommercePaymentMethod;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SettlementStatementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_order_reports_work_before_the_settlement_ledger_is_installed(): void
    {
        config(['commerce.enabled' => true, 'commerce.payments_enabled' => false]);
        $seller = $this->member('freelancer');
        $otherSeller = $this->member('freelancer');
        $buyer = $this->member('customer');
        $admin = $this->member('super_admin');
        $matchedDate = '2026-11-17 23:59:59';
        $own = $this->order($buyer, $seller, ' =SUM(1,2)', $matchedDate);
        $other = $this->order($buyer, $otherSeller, 'Other seller', $matchedDate);
        $old = $this->order($buyer, $seller, 'Previous day', '2026-11-16 23:59:59');
        $unpaid = $this->order($buyer, $seller, 'Unverified payment', $matchedDate, paid: false);
        $sandbox = $this->order($buyer, $seller, 'Sandbox payment', $matchedDate, live: false);

        $day = ['from' => '2026-11-17', 'to' => '2026-11-17'];
        $sellerRoute = 'studio.commerce-operations';
        $adminRoute = 'dashboard.commerce-operations';
        $this->get(route($adminRoute))->assertRedirect(route('login'));
        $this->actingAs($buyer)->get(route($sellerRoute.'.statement'))->assertForbidden();
        $this->actingAs($this->member('admin'))
            ->get(route($adminRoute.'.statement'))->assertForbidden();
        $this->actingAs($seller)->get(route($adminRoute))->assertForbidden();

        $this->app->instance('env', 'production');
        try {
            $this->get(route($sellerRoute, $day + ['queue' => 'unmatched']))->assertOk()
                ->assertSee('Sales Settlements')->assertSee('Download CSV')->assertSee($own->number)
                ->assertDontSee($other->number)->assertDontSee($old->number)
                ->assertDontSee($sandbox->number)->assertDontSee($unpaid->number);
            $this->get(route($sellerRoute, ['queue' => 'recovery_due']))
                ->assertOk()->assertSee('No verified orders match your filters.');

            $csv = $this->get(route($sellerRoute.'.statement', $day + ['queue' => 'unmatched']))
                ->assertOk()->assertDownload('bambodia-sales-settlements.csv');
            $this->assertStringContainsString('no-store', $csv->headers->get('Cache-Control'));
            $rows = $csv->streamedContent();
            $this->assertStringContainsString($own->number.',"\' =SUM(1,2)"', $rows);
            foreach ([$other->number, $old->number, $sandbox->number, $unpaid->number, $buyer->email, 'PRIVATE-PROOF'] as $excluded) {
                $this->assertStringNotContainsString($excluded, $rows);
            }
            $this->actingAs($admin)->get(route($adminRoute, $day))->assertOk()
                ->assertSee('Commerce Operations')->assertSee($own->number)->assertSee($other->number);
            $this->assertStringContainsString($other->number, $this->get(route($adminRoute.'.statement', $day))
                ->assertDownload('bambodia-commerce-settlements.csv')->streamedContent());
        } finally {
            $this->app->instance('env', 'testing');
        }

        $this->getJson(route($adminRoute.'.statement', ['from' => '2026-11-18', 'to' => '2026-11-17']))
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        config(['commerce.enabled' => false]);
        $this->get(route($adminRoute.'.statement'))->assertNotFound();
    }

    private function member(string $role): User
    {
        return User::query()->create([
            'role' => $role, 'name' => ucfirst($role),
            'email' => Str::uuid().'@example.test', 'password' => 'SettlementTesting123!',
        ]);
    }

    private function order(User $buyer, User $seller, string $title, string $completed, bool $paid = true, bool $live = true): MarketplaceOrder
    {
        $listing = MarketplaceListing::query()->create([
            'user_id' => $seller->id, 'title' => 'Settlement fixture '.Str::uuid(),
            'type' => 'product', 'description' => 'Digital product fixture for isolated order reporting.',
            'status' => 'published', 'currency' => 'USD',
        ]);
        $order = MarketplaceOrder::query()->create([
            'number' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
            'buyer_id' => $buyer->id, 'seller_id' => $seller->id, 'marketplace_listing_id' => $listing->id,
            'buyer_name' => $buyer->name, 'seller_name' => $seller->name, 'listing_title' => $title,
            'total' => '39.00', 'currency' => 'USD', 'status' => 'completed', 'completed_at' => $completed,
        ]);
        $method = CommercePaymentMethod::query()->where('provider', 'bank_transfer')->firstOrFail();
        $order->payments()->create([
            'number' => (string) Str::uuid(), 'commerce_payment_method_id' => $method->id,
            'provider' => $method->provider, 'method_name' => $method->name, 'live_mode' => $live,
            'status' => $paid ? 'paid' : 'manual_review', 'settlement_reference' => $paid ? 'STATEMENT-'.$order->id : null,
            'proof_path' => 'PRIVATE-PROOF',
        ]);

        return $order;
    }
}
