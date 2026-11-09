<?php

namespace Tests\Feature;

use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\CreatorBadgeAwards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class UserManagerCrudTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role='customer',?string $email=null): User
    {
        return User::query()->create([
            'name'=>'Account '.$role,
            'email'=>$email??uniqid('users-',true).'@example.test',
            'password'=>'TestPasswordVerySecure123!',
            'role'=>$role,
        ]);
    }

    private function payload(User $member,array $changes=[]): array
    {
        return array_merge([
            'name'=>$member->name,
            'username'=>'bamboo_testing',
            'email'=>$member->email,
            'role'=>$member->role,
            'locale'=>'en',
            'date_of_birth'=>'1992-04-12',
            'nationality'=>'Cambodian',
            'bio'=>'Private biography with employment experience.',
            'account_status'=>'active',
            'permissions'=>[
                'badge_awards'=>'1',
                'support_center'=>'1',
                'marketplace_review'=>'1',
            ],
        ],$changes);
    }

    private function listing(User $owner,string $type='project'): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id'=>$owner->id,'type'=>$type,
            'title'=>'Transferable independent '.$type.' listing',
            'description'=>str_repeat('A full description of the independent resource. ',3),
            'status'=>'published','currency'=>'USD',
        ]);
    }

    private function job(User $owner): MarketplaceJob
    {
        return MarketplaceJob::query()->create([
            'client_id'=>$owner->id,'title'=>'Existing job for an independent client',
            'description'=>str_repeat('This is a complete job description. ',4),
            'category'=>'development','status'=>'published','currency'=>'USD',
        ]);
    }

    public function test_only_superadmin_can_access_user_manager_create_edit_and_transfer(): void
    {
        $customer=$this->account();
        $admin=$this->account('admin');
        $this->actingAs($admin)->get('/dashboard/user-manager')->assertForbidden();
        $this->get('/dashboard/user-manager/create')->assertForbidden();
        $this->get('/dashboard/user-manager/'.$customer->id.'/edit')->assertForbidden();
        $this->get('/dashboard/user-manager/'.$customer->id.'/transfer?type=job&id=1')->assertForbidden();
    }

    public function test_create_edit_and_show_use_real_dedicated_pages(): void
    {
        $owner=$this->account('super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $this->actingAs($owner)->get('/dashboard/user-manager/create')->assertOk()
            ->assertSee('Create new user');
        $this->post('/dashboard/user-manager',[
            'name'=>'Added Professional',
            'username'=>'added_pro',
            'email'=>'added.pro@example.test',
            'password'=>'LongSecureAdminPassword12!',
            'password_confirmation'=>'LongSecureAdminPassword12!',
            'role'=>'freelancer','locale'=>'en',
            'date_of_birth'=>'1993-10-15',
            'nationality'=>'Cambodian',
            'bio'=>'Private profile note',
            'permissions'=>['badge_awards'=>0,'support_center'=>0,'marketplace_review'=>0],
        ])->assertRedirect();
        $member=User::query()->where('email','added.pro@example.test')->firstOrFail();
        $this->assertSame('added_pro',$member->username);
        $this->assertSame('active',$member->account_status);
        $this->assertNull($member->email_verified_at);
        $this->assertSame('Cambodian',$member->userProfile->nationality);
        $this->assertDatabaseHas('user_permission_overrides',[
            'user_id'=>$member->id,'permission'=>'badge_awards','allowed'=>0,
        ]);
        $this->get(route('dashboard.users.show',$member))->assertOk()
            ->assertSee('Account overview')->assertSee('Ownership transfer history');
        $this->get(route('dashboard.users.edit',$member))->assertOk()
            ->assertSee('Edit '.$member->name)->assertSee('Date of birth');
        $this->patch(route('dashboard.users.update',$member),$this->payload($member,[
            'name'=>'Updated Professional','username'=>'updated_pro',
            'email'=>'updated.pro@example.test','role'=>'customer',
            'account_status'=>'suspended',
        ]))->assertRedirect();
        $this->assertSame('Updated Professional',$member->fresh()->name);
        $this->assertSame('customer',$member->fresh()->role);
        $this->assertSame('suspended',$member->fresh()->account_status);
        $this->assertDatabaseHas('audit_events',['event'=>'user.role.changed','actor_id'=>$owner->id]);
    }

    public function test_archive_and_restore_preserve_the_users_identity_and_assets(): void
    {
        $owner=$this->account('super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $member=$this->account('freelancer');
        $item=$this->listing($member,'project');
        $this->actingAs($owner)->delete(route('dashboard.users.archive',$member),[
            'confirmation'=>'ARCHIVE','reason'=>'Administrative retention testing',
        ])->assertRedirect();
        $this->assertSame('archived',$member->fresh()->account_status);
        $this->assertDatabaseHas('marketplace_listings',['id'=>$item->id,'user_id'=>$member->id]);
        $this->post(route('dashboard.users.restore',$member))->assertRedirect();
        $this->assertSame('active',$member->fresh()->account_status);
    }

    public function test_suspended_user_cannot_access_authenticated_account_page(): void
    {
        $member=$this->account('customer');
        $member->update(['account_status'=>'suspended']);
        $this->actingAs($member)->get('/account')->assertRedirect(route('login'));
    }

    public function test_platform_owner_cannot_be_demoted_archived_or_renamed_by_email(): void
    {
        $owner=$this->account('super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $this->actingAs($owner)->patch(route('dashboard.users.update',$owner),
            $this->payload($owner,['role'=>'customer']))->assertStatus(422);
        $this->delete(route('dashboard.users.archive',$owner),[
            'confirmation'=>'ARCHIVE','reason'=>'This must never be permitted',
        ])->assertStatus(422);
        $this->assertSame('super_admin',$owner->fresh()->role);
        $this->assertSame('active',$owner->fresh()->account_status);
    }

    public function test_project_service_freelance_product_and_job_can_transfer_when_unencumbered(): void
    {
        $admin=$this->account('super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $old=$this->account('freelancer');
        $new=$this->account('freelancer');
        $this->actingAs($admin);
        foreach(['project','service','freelance','product'] as $type){
            $item=$this->listing($old,$type);
            $this->post(route('dashboard.users.transfer.store',$old),[
                'type'=>$type,'id'=>$item->id,'recipient'=>$new->public_id,
                'reason'=>'Verified transfer requested by the owner','rights_confirmed'=>1,
                'confirmation'=>'TRANSFER',
            ])->assertRedirect();
            $this->assertSame($new->id,$item->fresh()->user_id);
        }
        $client1=$this->account('customer');
        $client2=$this->account('subscriber');
        $job=$this->job($client1);
        $this->post(route('dashboard.users.transfer.store',$client1),[
            'type'=>'job','id'=>$job->id,'recipient'=>$client2->public_id,
            'reason'=>'Verified transfer requested by the client','rights_confirmed'=>1,
            'confirmation'=>'TRANSFER',
        ])->assertRedirect();
        $this->assertSame($client2->id,$job->fresh()->client_id);
        $this->assertDatabaseCount('user_ownership_transfers',5);
    }

    public function test_draft_product_with_exclusively_owned_cover_image_transfers_both(): void
    {
        Storage::fake('local');
        $admin=$this->account('super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $old=$this->account('freelancer');
        $new=$this->account('freelancer');
        $asset=MediaAsset::query()->create([
            'user_id'=>$old->id,
            'original_name'=>'product-cover.png','storage_path'=>'test/cover.png',
            'storage_disk'=>'local','mime_type'=>'image/png','bytes'=>120,
            'width'=>100,'height'=>100,'alt_text'=>'Product artwork',
            'rights_basis'=>'owned','rights_holder'=>$old->name,
            'review_status'=>'approved',
        ]);
        Storage::disk('local')->put('test/cover.png','fake media');
        $product=$this->listing($old,'product');
        $product->update(['status'=>'draft','cover_media_id'=>$asset->id]);
        $this->actingAs($admin)->post(route('dashboard.users.transfer.store',$old),[
            'type'=>'product','id'=>$product->id,'recipient'=>$new->public_id,
            'reason'=>'Copyright ownership reassignment verified by administrators',
            'rights_confirmed'=>1,'confirmation'=>'TRANSFER',
        ])->assertRedirect();
        $this->assertSame($new->id,$product->fresh()->user_id);
        $this->assertSame($new->id,$asset->fresh()->user_id);
        $this->assertDatabaseHas('user_ownership_transfers',[
            'resource_type'=>'product','resource_id'=>$product->id,
        ]);
    }

    public function test_transfer_rejects_jobs_with_proposals_and_does_not_partially_apply(): void
    {
        $admin=$this->account('super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $client1=$this->account('customer');
        $client2=$this->account('subscriber');
        $freelancer=$this->account('freelancer');
        $job=$this->job($client1);
        JobProposal::query()->create([
            'marketplace_job_id'=>$job->id,
            'freelancer_id'=>$freelancer->id,
            'cover_message'=>'An independent proposal for the job',
            'status'=>'submitted',
        ]);
        $this->actingAs($admin)->post(route('dashboard.users.transfer.store',$client1),[
            'type'=>'job','id'=>$job->id,'recipient'=>$client2->public_id,
            'reason'=>'Administrative reassignment request','rights_confirmed'=>1,
            'confirmation'=>'TRANSFER',
        ])->assertStatus(409);
        $this->assertSame($client1->id,$job->fresh()->client_id);
        $this->assertDatabaseCount('user_ownership_transfers',0);
    }

    public function test_permission_override_restricts_badge_grants_without_escalating_staff_role(): void
    {
        $owner=$this->account('super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $staff=$this->account('staff');
        $creator=$this->account('freelancer');
        $this->actingAs($owner)->patch(route('dashboard.users.update',$staff),
            $this->payload($staff,['permissions'=>[
                'badge_awards'=>'0','support_center'=>'1','marketplace_review'=>'1'
            ]]))->assertRedirect();
        $this->actingAs($staff)->post(route('dashboard.badges.grant',$creator),[
            'badge_id'=>DB::table('creator_badges')->where('slug','skill-certified')->value('id'),
            'evidence_note'=>'Verified skills assessment',
        ])->assertForbidden();
    }
}
