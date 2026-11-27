<?php

namespace Tests\Feature;

use App\Http\Controllers\Dashboard\DashboardController;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleDashboardPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role): User
    {
        return User::query()->create([
            'name' => 'Dashboard '.$role,
            'username' => 'dashboard_'.$role,
            'email' => 'dashboard_'.$role.'@example.test',
            'password' => 'TestPassword123!',
            'role' => $role,
            'locale' => 'en',
        ]);
    }

    private function permission(User $user, string $permission, bool $allowed): void
    {
        DB::table('user_permission_overrides')->updateOrInsert(
            ['user_id'=>$user->id,'permission'=>$permission],
            ['allowed'=>$allowed,'created_at'=>now(),'updated_at'=>now()]
        );
    }

    private function dashboard(User $user): array
    {
        $this->actingAs($user);
        request()->setUserResolver(fn () => $user);
        return app(DashboardController::class)->index()->getData();
    }

    public function test_staff_without_grants_cannot_receive_management_records(): void
    {
        config(['commerce.enabled'=>true]);
        $staff = $this->actor('staff');
        $owner = $this->actor('customer');
        SupportTicket::query()->create(['user_id'=>$owner->id,'subject'=>'Private support fixture','status'=>'open']);
        foreach (['support_center','marketplace_transfer','commerce_orders','badge_awards'] as $permission) {
            $this->permission($staff,$permission,false);
        }
        $data = $this->dashboard($staff);
        $this->assertTrue($data['isStaff']);
        $this->assertFalse($data['canSupport']);
        $this->assertFalse($data['canReview']);
        $this->assertSame([], $data['stats']);
        $this->assertSame([], $data['queues']);
        $this->assertSame([], $data['reports']);
        $this->assertCount(0,$data['recentSupport']);
        $this->assertCount(0,$data['recent']);
        $this->assertSame(['dashboard.support-tickets.index','account.security'],array_column($data['shortcuts'],1));

        $this->permission($staff,'support_center',true);
        $data = $this->dashboard($staff);
        $this->assertTrue($data['canSupport']);
        $this->assertSame(['Support ticket status'],array_column($data['reports'],'title'));
        $this->assertSame('Private support fixture',$data['recentSupport']->first()->subject);
        $this->assertSame([], $data['stats']);
        $this->assertFalse($data['canReview']);

        $this->permission($staff,'marketplace_transfer',true);
        $this->permission($staff,'commerce_orders',true);
        $data = $this->dashboard($staff);
        $this->assertContains('Marketplace inventory',array_column($data['reports'],'title'));
        $this->assertContains('Marketplace orders',array_column($data['reports'],'title'));
        $this->assertSame([], $data['stats']);
        $this->assertFalse($data['canReview']);
        $this->assertNotContains('dashboard.users',array_column($data['shortcuts'],1));
    }

    public function test_admin_review_and_support_overrides_are_respected(): void
    {
        config(['commerce.enabled'=>true]);
        $admin = $this->actor('admin');
        $this->permission($admin,'support_center',false);
        $this->permission($admin,'marketplace_review',false);
        $this->permission($admin,'commerce_orders',false);
        $data = $this->dashboard($admin);
        $this->assertFalse($data['canSupport']);
        $this->assertFalse($data['canReview']);
        $this->assertCount(0,$data['recent']);
        $this->assertCount(0,$data['recentSupport']);
        $this->assertNotContains('dashboard.reviews',array_column($data['queues'],'route'));
        $this->assertNotContains('dashboard.support',array_column($data['queues'],'route'));
        $this->assertNotContains('Marketplace orders',array_column($data['reports'],'title'));
        $this->assertNotContains('dashboard.users',array_column($data['shortcuts'],1));
        $this->assertNotContains('security.index',array_column($data['shortcuts'],1));
        $this->assertContains('Contract lifecycle',array_column($data['reports'],'title'));
    }

    public function test_super_admin_reports_respect_commerce_feature_flag(): void
    {
        $admin = $this->actor('super_admin');
        config(['commerce.enabled'=>false]);
        $data = $this->dashboard($admin);
        $this->assertTrue($data['canReview']);
        $this->assertTrue($data['canSupport']);
        $this->assertContains('dashboard.users',array_column($data['shortcuts'],1));
        $this->assertNotContains('Marketplace orders',array_column($data['reports'],'title'));
        $this->assertNotContains('dashboard.payment-methods.index',array_column($data['shortcuts'],1));
        config(['commerce.enabled'=>true]);
        $data = $this->dashboard($admin);
        $this->assertContains('Marketplace orders',array_column($data['reports'],'title'));
        $this->assertContains('dashboard.payment-methods.index',array_column($data['shortcuts'],1));
    }

    public function test_trend_reports_use_only_authorized_real_records(): void
    {
        config(['commerce.enabled'=>false]);
        $owner = $this->actor('customer');
        $other = $this->actor('subscriber');
        foreach ([$owner,$other] as $recipient) {
            \App\Models\AccountNotification::query()->create([
                'user_id'=>$recipient->id,'event'=>'support.replied','title'=>'Fixture update',
                'message'=>'Fixture message','target_path'=>'/account',
            ]);
        }
        $this->dashboardActor($owner);
        $charts = (new \App\View\Components\RoleDashboardTrends('account'))->render()->getData()['series'];
        $this->assertSame(['Account updates'],array_column($charts,'title'));
        $this->assertCount(12,$charts[0]['weeks']);
        $this->assertSame(1,array_sum(array_column($charts[0]['weeks'],'count')));
        $staff = $this->actor('staff');
        $this->permission($staff,'support_center',false);
        $this->permission($staff,'marketplace_transfer',false);
        $this->dashboardActor($staff);
        $this->assertSame([], (new \App\View\Components\RoleDashboardTrends('admin'))->render()->getData()['series']);
        $this->permission($staff,'support_center',true);
        SupportTicket::query()->create(['user_id'=>$owner->id,'subject'=>'Trend fixture','status'=>'open']);
        $charts = (new \App\View\Components\RoleDashboardTrends('admin'))->render()->getData()['series'];
        $this->assertSame(['Support ticket activity'],array_column($charts,'title'));
        $this->assertSame(1,array_sum(array_column($charts[0]['weeks'],'count')));
    }

    public function test_admin_calendar_and_type_filters_change_real_reports_without_hiding_backlog(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-20 12:00:00'));
        config(['commerce.enabled'=>false]);
        $admin = $this->actor('super_admin');
        $owner = $this->actor('freelancer');
        foreach ([
            ['product','Current product','2026-11-01 00:00:00'],
            ['product','Quarter product','2026-10-01 00:00:00'],
            ['product','Year product','2026-01-01 00:00:00'],
            ['product','Old product','2025-12-31 23:59:59'],
            ['project','Current project','2026-11-10 08:00:00'],
            ['freelance','Current freelance','2026-11-10 08:00:00'],
            ['service','Current service','2026-11-10 08:00:00'],
        ] as [$type,$title,$date]) {
            $listing = \App\Models\MarketplaceListing::query()->create([
                'user_id'=>$owner->id,'type'=>$type,'title'=>$title,
                'description'=>'Isolated dashboard filtering fixture','status'=>'pending','currency'=>'USD',
            ]);
            $listing->forceFill(['created_at'=>$date])->save();
        }
        $this->actingAs($admin);
        foreach (['month'=>1,'quarter'=>2,'year'=>3] as $period=>$expected) {
            $response = $this->get('/dashboard?type=product&period='.$period)->assertOk()
                ->assertSee('data-admin-filter-type="product"', false);
            $data = $response->original->getData();
            $this->assertSame('product',$data['filters']->type);
            $this->assertSame($period,$data['filters']->period);
            $this->assertSame($expected,$data['periodPending']);
            $inventory = collect($data['reports'])->firstWhere('title','Marketplace inventory');
            $this->assertSame(['Digital products'],array_column($inventory['rows'],'label'));
            $this->assertSame([$expected],array_column($inventory['rows'],'count'));
            $trend = collect($data['adminTrends'])->firstWhere('title','Marketplace submissions');
            $this->assertSame($expected,array_sum(array_column($trend['buckets'],'count')));
            $this->assertSame(7,collect($data['queues'])->firstWhere('route','dashboard.reviews')['count']);
            $this->assertCount($expected,$data['recent']);
        }
        $all = $this->get('/dashboard')->assertOk()->original->getData();
        $this->assertSame('all',$all['filters']->type);
        $this->assertSame('month',$all['filters']->period);
        $this->assertSame(4,$all['periodPending']);
        foreach (['project','freelance','service'] as $type) {
            $data = $this->get('/dashboard?type='.$type.'&period=month')->assertOk()->original->getData();
            $this->assertSame(1,$data['periodPending']);
            $this->assertSame($type,$data['recent']->sole()->type);
        }
        $fallback = $this->get('/dashboard?type=invalid&period=invalid')->assertOk()->original->getData();
        $this->assertSame('all',$fallback['filters']->type);
        $this->assertSame('month',$fallback['filters']->period);
        $this->travelBack();
    }

    public function test_new_filters_are_not_exposed_to_staff_or_other_workspaces(): void
    {
        config(['commerce.enabled'=>false]);
        $staff = $this->actor('staff');
        $this->actingAs($staff)->get('/dashboard?type=product&period=year')->assertOk()
            ->assertDontSee('data-admin-performance-filters',false)
            ->assertDontSee('admin-dashboard-filters.css',false);
        $customer = $this->actor('customer');
        $this->actingAs($customer)->get('/account?='.$customer->public_id)->assertOk()
            ->assertDontSee('data-admin-performance-filters',false)
            ->assertDontSee('admin-dashboard-filters.css',false);
        $this->get('/dashboard?type=product&period=year')->assertForbidden();
    }

    private function dashboardActor(User $user): void
    {
        $this->actingAs($user);
        request()->setUserResolver(fn () => $user);
    }

}
