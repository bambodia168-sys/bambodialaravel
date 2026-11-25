<?php

namespace Tests\Feature;

use App\Models\CommercePaymentMethod;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\ServiceInquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProjectTrackingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' '.Str::random(5),
            'email' => Str::uuid().'@example.test',
            'password' => 'LongExamplePassw0rd!',
            'role' => $role,
        ]);
    }

    private function verifiedOrder(string $type = 'service'): array
    {
        $buyer = $this->user('customer');
        $seller = $this->user('freelancer');
        $listing = MarketplaceListing::query()->create([
            'user_id' => $seller->id, 'type' => $type,
            'title' => 'Private '.$type.' tracking fixture',
            'description' => 'Legitimate verified buyer and creator fixture.',
            'status' => 'published', 'price' => '85.00', 'currency' => 'USD',
        ]);
        $method = CommercePaymentMethod::query()->firstOrCreate(['name' => 'Tracking test transfer'],
            ['available' => true]);
        $order = MarketplaceOrder::query()->create([
            'number' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
            'buyer_id' => $buyer->id, 'seller_id' => $seller->id,
            'marketplace_listing_id' => $listing->id,
            'listing_title' => $listing->title,
            'buyer_name' => $buyer->name, 'seller_name' => $seller->name,
            'total' => '85.00', 'currency' => 'USD',
            'status' => 'completed', 'completed_at' => now(),
        ]);
        $order->payments()->create([
            'number' => (string) Str::uuid(),
            'commerce_payment_method_id' => $method->id,
            'method_name' => $method->name, 'provider' => 'bank_transfer',
            'status' => 'paid', 'live_mode' => true,
            'settlement_reference' => 'TRACKING-'.$order->id,
        ]);
        $inquiry = $order->serviceInquiry()->create([
            'marketplace_listing_id' => $listing->id, 'client_id' => $buyer->id,
            'creator_id' => $seller->id, 'subject' => $listing->title,
            'status' => 'open', 'currency' => 'USD',
        ]);

        return [$buyer, $seller, $order, $inquiry];
    }

    public function test_creator_and_customer_have_scoped_tracking_overviews_and_private_details(): void
    {
        config(['commerce.enabled' => true]);
        [$buyer, $creator, $order] = $this->verifiedOrder('project');
        $outsider = $this->user('customer');

        $this->get(route('tracking.orders.show', $order->reference()))
            ->assertRedirect(route('login'));
        $this->actingAs($creator)->get('/studio/tracking')->assertOk()
            ->assertSee('Project tracking')->assertSee($order->listing_title)
            ->assertSee($order->reference());
        $this->actingAs($buyer)->get('/account/tracking')->assertOk()
            ->assertSee('Project tracking')->assertSee($order->listing_title);
        $this->get(route('tracking.orders.show', $order->reference()))
            ->assertOk()->assertSee('Delivery progress')->assertSee('Project checklist')
            ->assertSee('Customer &amp; creator messages', false)
            ->assertSee('Schedule requests')->assertSee('Payment verified')
            ->assertDontSee('Escrow Status: Funds Secured');
        $this->actingAs($outsider)->get(route('tracking.orders.show', $order->reference()))
            ->assertNotFound();
        $this->get('/account/tracking')->assertOk()->assertDontSee($order->listing_title);
        $this->get('/studio/tracking')->assertForbidden();
        $this->actingAs($this->user('staff'))->get('/account/tracking')->assertOk()
            ->assertDontSee($order->listing_title);
    }

    public function test_a_creator_can_manage_tasks_but_customer_cannot_change_them(): void
    {
        config(['commerce.enabled' => true]);
        [$buyer, $seller, $order, $inquiry] = $this->verifiedOrder('freelance');
        $this->actingAs($buyer)->post(route('tracking.tasks.store', $order->reference()), [
            'title' => 'Customer cannot silently modify task',
        ])->assertForbidden();
        $this->assertDatabaseCount('order_tracking_tasks', 0);

        $this->actingAs($seller)->post(route('tracking.tasks.store', $order->reference()), [
            'title' => 'Prepare working visual prototypes',
            'due_on' => now()->addDays(6)->toDateString(),
        ])->assertRedirect(route('tracking.orders.show', $order->reference()));
        $task = DB::table('order_tracking_tasks')->where('marketplace_order_id', $order->id)->first();
        $this->assertSame('planned', $task->status);
        $this->actingAs($buyer)->patch(route('tracking.tasks.update', [$order->reference(), $task->id]), [
            'status' => 'completed',
        ])->assertForbidden();
        $this->actingAs($seller)->patch(route('tracking.tasks.update', [$order->reference(), $task->id]), [
            'status' => 'completed',
        ])->assertRedirect();
        $this->assertDatabaseHas('order_tracking_tasks', ['id' => $task->id, 'status' => 'completed']);
        $this->get(route('tracking.orders.show', $order->reference()))
            ->assertOk()->assertSee('1/1')->assertSee('Prepare working visual prototypes');
        $this->assertDatabaseHas('account_notifications', [
            'user_id' => $buyer->id, 'event' => 'order.tracking.task_updated',
        ]);
        $inquiry->update(['delivery_status' => 'accepted']);
        $this->post(route('tracking.tasks.store', $order->reference()), [
            'title' => 'Cannot add after acceptance',
        ])->assertConflict();
        $this->patch(route('tracking.tasks.update', [$order->reference(), $task->id]), [
            'status' => 'planned',
        ])->assertConflict();
    }

    public function test_schedule_changes_require_other_participant_approval_and_cannot_be_self_approved(): void
    {
        config(['commerce.enabled' => true]);
        [$buyer, $seller, $order] = $this->verifiedOrder();
        $date = now()->addDays(15)->toDateString();
        $this->actingAs($seller)->post(route('tracking.extensions.store', $order->reference()), [
            'proposed_due_on' => $date,
            'reason' => 'Additional testing is necessary to validate the final deliverables.',
        ])->assertRedirect();
        $record = DB::table('order_tracking_extension_requests')->sole();
        $url = route('tracking.extensions.decide', [$order->reference(), $record->id]);
        $this->patch($url, ['decision' => 'approved'])->assertForbidden();
        $this->post(route('tracking.extensions.store', $order->reference()), [
            'proposed_due_on' => $date,
            'reason' => 'A duplicate schedule proposal should be rejected safely.',
        ])->assertConflict();
        $this->actingAs($buyer)->patch($url, ['decision' => 'approved'])->assertRedirect();
        $this->assertDatabaseHas('order_tracking_extension_requests', [
            'id' => $record->id, 'status' => 'approved', 'responded_by' => $buyer->id,
        ]);
        $this->get(route('tracking.orders.show', $order->reference()))
            ->assertOk()->assertSee('Agreed tracker date')
            ->assertSee(now()->addDays(15)->format('d M Y'));
        $this->patch($url, ['decision' => 'declined'])->assertConflict();
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $buyer->id, 'event' => 'order.tracking.schedule_approved',
        ]);
    }

    public function test_tracking_reuses_customer_requirements_creator_delivery_and_buyer_review(): void
    {
        config(['commerce.enabled' => true]);
        [$buyer, $creator, $order, $inquiry] = $this->verifiedOrder('service');
        $page = route('tracking.orders.show', $order->reference());
        $this->actingAs($buyer)->get($page)->assertOk()->assertSee('Send requirements');
        $this->post(route('inquiries.delivery.requirements', $inquiry), [
            'requirements' => 'Complete the staging deployment and explain the handover steps.',
            'tracking' => '1',
        ])->assertRedirect($page);
        $this->actingAs($creator)->get($page)->assertOk()->assertSee('Submit delivery');
        $this->post(route('inquiries.delivery.submit', $inquiry), [
            'notes' => 'The deliverables are ready for customer acceptance and staging review.',
            'tracking' => '1',
        ])->assertRedirect($page);
        $delivery = $inquiry->deliveries()->sole();
        $this->actingAs($buyer)->patch(route('inquiries.delivery.decide', $inquiry), [
            'delivery' => $delivery->id, 'decision' => 'accept', 'tracking' => '1',
        ])->assertRedirect($page);
        $this->get($page)->assertOk()->assertSee('100%')->assertSee('Delivery accepted');
        $this->post(route('inquiries.reply', $inquiry), [
            'body' => 'Thanks, your verified project was delivered successfully.',
        ])->assertRedirect();
    }

    public function test_unpaid_refunded_product_and_mismatched_order_links_never_open_tracking(): void
    {
        config(['commerce.enabled' => true]);
        [$buyer, $creator, $order, $inquiry] = $this->verifiedOrder('project');
        $this->actingAs($buyer);
        $inquiry->update(['client_id' => $this->user('customer')->id]);
        $this->get(route('tracking.orders.show', $order->reference()))->assertNotFound();
        $inquiry->update(['client_id' => $buyer->id]);
        $order->update(['status' => 'refunded']);
        $this->get(route('tracking.orders.show', $order->reference()))->assertNotFound();
        $order->update(['status' => 'awaiting_payment']);
        $this->get(route('tracking.orders.show', $order->reference()))->assertNotFound();
        $order->update(['status' => 'completed']);
        $order->payments()->update(['status' => 'pending']);
        $this->get(route('tracking.orders.show', $order->reference()))->assertNotFound();

        $productListing = MarketplaceListing::query()->create([
            'user_id' => $creator->id, 'type' => 'product',
            'title' => 'Product not eligible for tracking',
            'description' => 'Digital download only.', 'status' => 'published', 'price' => '25.00',
        ]);
        $order->update(['marketplace_listing_id' => $productListing->id]);
        $this->get(route('tracking.orders.show', $order->reference()))->assertNotFound();
    }
    public function test_milestone_presets_are_type_specific_editable_and_not_offered_to_customers(): void
    {
        config(['commerce.enabled' => true]);

        $examples = [
            'project' => 'Create wireframes or initial design concepts',
            'service' => 'Complete initial assessment and recommendations',
            'freelance' => 'Prepare the first draft or working prototype',
        ];

        foreach ($examples as $type => $presetTitle) {
            [$buyer, $creator, $order] = $this->verifiedOrder($type);
            $url = route('tracking.orders.show', $order->reference());

            $this->actingAs($creator)->get($url)->assertOk()
                ->assertSee('MILESTONE LIBRARY')
                ->assertSee('Browse milestone presets')
                ->assertSee('Custom task')
                ->assertSee('data-task-preset-select', false)
                ->assertSee('data-task-title', false)
                ->assertSee($presetTitle);

            $this->post(route('tracking.tasks.store', $order->reference()), [
                'title' => $presetTitle,
                'due_on' => now()->addDays(7)->toDateString(),
            ])->assertRedirect($url);

            $customTitle = 'Custom '.$type.' milestone for client approval';
            $this->post(route('tracking.tasks.store', $order->reference()), [
                'title' => $customTitle,
            ])->assertRedirect($url);

            $this->assertDatabaseHas('order_tracking_tasks', [
                'marketplace_order_id' => $order->id, 'title' => $presetTitle,
            ]);
            $this->assertDatabaseHas('order_tracking_tasks', [
                'marketplace_order_id' => $order->id, 'title' => $customTitle,
            ]);

            $this->actingAs($buyer)->get($url)->assertOk()
                ->assertSee($presetTitle)->assertSee($customTitle)
                ->assertDontSee('data-task-preset-select', false)
                ->assertDontSee('data-task-custom', false);

            $this->post(route('tracking.tasks.store', $order->reference()), [
                'title' => 'Customer cannot assign a preset milestone',
            ])->assertForbidden();
        }
    }

    public function test_preset_catalog_contains_professional_titles_with_existing_limits(): void
    {
        $catalog = \App\Support\TrackingTaskPresets::groupsFor('service');
        $titles = collect($catalog)->flatten()->all();

        $this->assertContains('Review service requirements and current setup', $titles);
        $this->assertContains('Submit final deliverables for approval', $titles);
        $this->assertNotContains('Create wireframes or initial design concepts', $titles);
        $this->assertSame(3, count(\App\Support\TrackingTaskPresets::recommendedFor('service')));

        foreach (['project', 'service', 'freelance'] as $type) {
            $groups = \App\Support\TrackingTaskPresets::groupsFor($type);
            $titles = collect($groups)->flatten()->all();
            $this->assertCount(count(array_unique($titles)), $titles);
            foreach ($titles as $title) {
                $this->assertGreaterThanOrEqual(5, mb_strlen($title));
                $this->assertLessThanOrEqual(180, mb_strlen($title));
            }
        }
    }

}
