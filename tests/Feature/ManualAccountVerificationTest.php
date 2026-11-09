<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\CreatorBadgeAwards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ManualAccountVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role='freelancer',?string $email=null): User
    {
        return User::query()->create([
            'name'=>'Verified Review '.$role,
            'email'=>$email??uniqid('manualreview-',true).'@example.test',
            'password'=>'LongTestPassword123!',
            'role'=>$role,
        ]);
    }

    private function verifyData(): array
    {
        return [
            'method'=>'contact_confirmation',
            'note'=>'Completed a direct account ownership review in support case 12345.',
            'confirmation'=>'VERIFY',
        ];
    }

    public function test_admin_can_manually_verify_account_without_confirming_email_or_granting_identity_badge(): void
    {
        $admin=$this->account('admin');
        $freelancer=$this->account();
        $this->actingAs($admin)
            ->get(route('dashboard.account-verifications.index'))->assertOk()
            ->assertSee('Account Verification');
        $this->get(route('dashboard.account-verifications.show',$freelancer))
            ->assertOk()->assertSee('Email confirmation')->assertSee('Not confirmed');
        $this->post(route('dashboard.account-verifications.verify',$freelancer),$this->verifyData())
            ->assertRedirect();

        $freelancer->refresh();
        $this->assertSame('verified',$freelancer->account_review_status);
        $this->assertNotNull($freelancer->account_verified_at);
        $this->assertSame($admin->id,$freelancer->account_verified_by);
        $this->assertNull($freelancer->email_verified_at);
        $this->assertDatabaseHas('user_account_review_events',[
            'user_id'=>$freelancer->id,'reviewer_id'=>$admin->id,
            'decision'=>'verified','method'=>'contact_confirmation',
        ]);
        $this->assertFalse(\App\Support\CreatorBadgeAwards::earned($freelancer)->contains('slug','identity-verified'));
    }

    public function test_admin_can_revoke_and_history_remains_immutable(): void
    {
        $admin=$this->account('admin');
        $member=$this->account('customer');
        $this->actingAs($admin)->post(route('dashboard.account-verifications.verify',$member),$this->verifyData())
            ->assertRedirect();
        $this->post(route('dashboard.account-verifications.revoke',$member),[
            'note'=>'Previous account review needs renewal following further investigation.',
            'confirmation'=>'REVOKE',
        ])->assertRedirect();
        $this->assertSame('revoked',$member->fresh()->account_review_status);
        $this->assertNull($member->fresh()->account_verified_at);
        $this->assertDatabaseCount('user_account_review_events',2);
        $this->assertDatabaseHas('user_account_review_events',['decision'=>'revoked','user_id'=>$member->id]);
        $this->post(route('dashboard.account-verifications.revoke',$member),[
            'note'=>'Another inappropriate duplicate revocation attempt.',
            'confirmation'=>'REVOKE',
        ])->assertStatus(409);
        $this->assertDatabaseCount('user_account_review_events',2);
    }

    public function test_admin_cannot_verify_admin_superadmin_self_or_inactive_user(): void
    {
        $admin=$this->account('admin');
        $anotherAdmin=$this->account('admin');
        $super=$this->account('super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $member=$this->account('freelancer');
        $this->actingAs($admin)
            ->post(route('dashboard.account-verifications.verify',$admin),$this->verifyData())->assertForbidden();
        $this->post(route('dashboard.account-verifications.verify',$anotherAdmin),$this->verifyData())->assertForbidden();
        $this->post(route('dashboard.account-verifications.verify',$super),$this->verifyData())->assertForbidden();
        $member->update(['account_status'=>'suspended']);
        $this->post(route('dashboard.account-verifications.verify',$member),$this->verifyData())->assertStatus(409);
        $this->assertDatabaseCount('user_account_review_events',0);
    }

    public function test_staff_and_customer_cannot_access_manual_review_queue_or_actions(): void
    {
        $target=$this->account();
        foreach(['staff','customer'] as $role){
            $actor=$this->account($role);
            $this->actingAs($actor)->get(route('dashboard.account-verifications.index'))->assertForbidden();
            $this->get(route('dashboard.account-verifications.show',$target))->assertForbidden();
            $this->post(route('dashboard.account-verifications.verify',$target),$this->verifyData())->assertForbidden();
        }
        $this->assertSame('unreviewed',$target->fresh()->account_review_status);
    }

    public function test_superadmin_profile_edits_invalidate_review_but_keep_history(): void
    {
        $admin=$this->account('admin');
        $super=$this->account('super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $freelancer=$this->account();
        $this->actingAs($admin)->post(route('dashboard.account-verifications.verify',$freelancer),$this->verifyData())
            ->assertRedirect();
        $this->actingAs($super)->patch(route('dashboard.users.update',$freelancer),[
            'name'=>'Updated Independent Freelancer',
            'email'=>$freelancer->email,'username'=>'updated_independent',
            'role'=>'freelancer','account_status'=>'active','locale'=>'en',
            'date_of_birth'=>'1994-02-03','nationality'=>'Cambodian',
            'permissions'=>['badge_awards'=>'1','support_center'=>'1','marketplace_review'=>'1'],
        ])->assertRedirect();
        $freelancer->refresh();
        $this->assertSame('unreviewed',$freelancer->account_review_status);
        $this->assertNull($freelancer->account_verified_at);
        $this->assertSame(['verified','invalidated'],
            DB::table('user_account_review_events')->where('user_id',$freelancer->id)->orderBy('id')
                ->pluck('decision')->all());
    }

    public function test_manually_verified_status_does_not_become_email_verified_after_creation(): void
    {
        $super=$this->account('super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $member=$this->account('customer');
        $this->actingAs($super)->post(route('dashboard.account-verifications.verify',$member),$this->verifyData())
            ->assertRedirect();
        $this->assertNull($member->fresh()->email_verified_at);
        $this->get(route('dashboard.users.show',$member))
            ->assertOk()->assertSee('Account review')->assertSee('Email verification')->assertSee('Not confirmed');
    }
}
