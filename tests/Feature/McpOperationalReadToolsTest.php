<?php

namespace Tests\Feature;

use App\Models\AccountNotification;
use App\Models\EmailDelivery;
use App\Models\MarketplaceListing;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\Mcp\OperationalReadTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class McpOperationalReadToolsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role): User
    {
        $suffix = User::query()->count() + 1;
        return User::query()->create([
            'name'=>'MCP test '.$suffix, 'username'=>'mcp_test_'.$suffix,
            'email'=>'mcp_test_'.$suffix.'@example.test', 'password'=>'TestPassword123!',
            'role'=>$role, 'locale'=>'en',
        ]);
    }

    public function test_six_tools_are_read_only_and_return_aggregate_data(): void
    {
        $admin = $this->actor('super_admin');
        $customer = $this->actor('customer');
        $service = new OperationalReadTools();
        config(['commerce.enabled'=>true]);

        $definitions = $service->definitions();
        $this->assertCount(6, $definitions);
        $this->assertSame(array_keys(OperationalReadTools::TOOLS), array_column($definitions, 'name'));
        foreach ($definitions as $definition) {
            $this->assertTrue($definition['annotations']['readOnlyHint']);
            $this->assertFalse($definition['inputSchema']['additionalProperties']);
        }

        SupportTicket::query()->create(['user_id'=>$customer->id,'subject'=>'Secret ticket subject','status'=>'open']);
        MarketplaceListing::query()->create(['user_id'=>$customer->id,'type'=>'product','title'=>'Test pending product','description'=>'Test fixture','currency'=>'USD','status'=>'pending']);
        AccountNotification::query()->create(['user_id'=>$admin->id,'event'=>'marketplace.review.requested','title'=>'Private title','message'=>'Private body','target_path'=>'/dashboard/review-queue']);
        AccountNotification::query()->create(['user_id'=>$customer->id,'event'=>'marketplace.submission.received','title'=>'Other private title','message'=>'Other private body','target_path'=>'/account']);
        EmailDelivery::query()->create(['event'=>'marketplace.review.requested','user_id'=>$admin->id,'deduplication_key'=>'mcp-test-delivery','status'=>'queued','context'=>['test'=>'secret delivery context']]);

        $results = [];
        foreach (array_keys(OperationalReadTools::TOOLS) as $name) {
            $results[$name] = $service->call($name, [], $admin, true);
        }
        $this->assertSame(2, $results['bambodia_accounts_summary']['registered']);
        $this->assertSame(0, $results['bambodia_orders_summary']['orders']);
        $this->assertSame(1, $results['bambodia_review_queue']['listings_by_type']['product']);
        $this->assertSame(1, $results['bambodia_support_summary']['current_unresolved']);
        $this->assertSame(1, $results['bambodia_notifications_summary']['received']);
        $this->assertSame(0, $results['bambodia_notifications_summary']['submission_events']['marketplace.submission.received']);
        $this->assertSame(1, $results['bambodia_email_delivery_summary']['current_queued']);
        $json = json_encode($results, JSON_THROW_ON_ERROR);
        foreach ([$admin->email,$customer->email,'Secret ticket subject','Private body','secret delivery context'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertDatabaseCount('account_notifications', 2);
        $this->assertDatabaseHas('email_deliveries',['deduplication_key'=>'mcp-test-delivery','status'=>'queued']);
    }

    public function test_all_tools_reject_missing_scope_non_super_admin_and_inactive_owner(): void
    {
        $admin = $this->actor('super_admin');
        $staff = $this->actor('staff');
        $inactive = $this->actor('super_admin');
        $inactive->forceFill(['account_status'=>'deactivated']);
        $service = new OperationalReadTools();
        foreach (array_keys(OperationalReadTools::TOOLS) as $name) {
            foreach ([[$admin,false],[$staff,true],[$inactive,true]] as [$owner,$read]) {
                try {
                    $service->call($name, [], $owner, $read);
                    $this->fail('Unexpected authorization for '.$name);
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('access', $e->errors());
                }
            }
        }
    }

    public function test_disabled_commerce_and_invalid_input_are_rejected(): void
    {
        $admin = $this->actor('super_admin');
        $service = new OperationalReadTools();
        config(['commerce.enabled'=>false]);
        foreach ([
            ['bambodia_orders_summary',[],'access'],
            ['bambodia_accounts_summary',['days'=>91],'days'],
            ['bambodia_notifications_summary',['user_id'=>123],'arguments'],
            ['bambodia_review_queue',['days'=>7],'arguments'],
        ] as [$name,$args,$field]) {
            try {
                $service->call($name,$args,$admin,true);
                $this->fail('Unexpected acceptance for '.$name);
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field,$e->errors());
            }
        }
    }
}
