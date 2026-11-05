<?php

namespace Tests\Feature;

use App\Models\Policy;
use App\Models\PolicyAcknowledgement;
use App\Models\PolicyPublication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $role): User
    {
        return User::query()->create([
            'name'=>'Compliance '.$role,
            'email'=>uniqid($role,true).'@example.test',
            'password'=>'StrongAccountPassword123!',
            'role'=>$role,
        ]);
    }

    private function draftPolicy(): Policy
    {
        return Policy::query()->create([
            'title'=>'Marketplace Service Terms',
            'body'=>str_repeat('These service terms govern access to licensed marketplace functions. ',2),
            'category'=>'terms','jurisdiction'=>'Cambodia','status'=>'draft',
        ]);
    }

    private function publish(User $admin,Policy $policy,string $body): PolicyPublication
    {
        $this->actingAs($admin)->patch('/dashboard/policy-center/'.$policy->id,[
            'title'=>$policy->title,'body'=>$body,
            'category'=>'terms','jurisdiction'=>'Cambodia',
            'status'=>'published','confirm_legal_review'=>'1',
        ])->assertRedirect();
        return PolicyPublication::query()->where('policy_id',$policy->id)
            ->orderByDesc('revision')->firstOrFail();
    }

    public function test_publication_creates_immutable_revision_snapshot_and_voluntary_receipt(): void
    {
        $admin=$this->person('admin');
        $customer=$this->person('customer');
        $policy=$this->draftPolicy();
        $body=str_repeat('Customers may use the services subject to the documented terms. ',2);
        $publication=$this->publish($admin,$policy,$body);

        $this->assertSame(1,$publication->revision);
        $this->assertSame(trim($body),$publication->body_snapshot);
        $this->assertSame(PolicyPublication::digestFor($policy->fresh()),$publication->sha256);
        $this->assertDatabaseHas('audit_events',['event'=>'policy.published','actor_id'=>$admin->id]);

        $this->actingAs($customer)->get($policy->publicUrl())
            ->assertOk()->assertSee('Published revision')->assertSee($publication->sha256);

        $this->post('/policies/acknowledgements/'.$publication->id,[
            'confirm_read'=>'1',
        ])->assertRedirect(route('account.policy-receipts'));
        $this->assertDatabaseHas('policy_acknowledgements',[
            'user_id'=>$customer->id,'policy_publication_id'=>$publication->id,
            'sha256'=>$publication->sha256,
        ]);
        $this->get('/account/policy-receipts')->assertOk()->assertSee('Marketplace Service Terms');
        $this->post('/policies/acknowledgements/'.$publication->id,['confirm_read'=>'1'])
            ->assertStatus(409);
        $this->assertDatabaseCount('policy_acknowledgements',1);
    }

    public function test_republication_does_not_overwrite_old_provenance_and_requires_new_receipt(): void
    {
        $admin=$this->person('admin');
        $user=$this->person('subscriber');
        $policy=$this->draftPolicy();
        $first=$this->publish($admin,$policy,str_repeat('First written version of the marketplace terms. ',2));
        $this->actingAs($user)->post('/policies/acknowledgements/'.$first->id,['confirm_read'=>'1'])
            ->assertRedirect();

        $second=$this->publish($admin,$policy,str_repeat('Second independent written version with additional rights. ',2));
        $this->assertSame(2,$second->revision);
        $this->assertNotSame($first->sha256,$second->sha256);
        $this->assertSame(2,PolicyPublication::query()->where('policy_id',$policy->id)->count());

        $this->actingAs($user)->post('/policies/acknowledgements/'.$first->id,['confirm_read'=>'1'])
            ->assertStatus(409);
        $this->post('/policies/acknowledgements/'.$second->id,['confirm_read'=>'1'])
            ->assertRedirect();
        $this->assertDatabaseCount('policy_acknowledgements',2);
        $this->assertSame(trim(str_repeat('First written version of the marketplace terms. ',2)),
            $first->fresh()->body_snapshot);
    }

    public function test_draft_policy_cannot_be_acknowledged_and_legal_review_confirmation_is_required(): void
    {
        $admin=$this->person('admin');
        $customer=$this->person('customer');
        $policy=$this->draftPolicy();

        $this->actingAs($admin)->patch('/dashboard/policy-center/'.$policy->id,[
            'title'=>$policy->title,
            'body'=>$policy->body,'category'=>'terms','status'=>'published',
        ])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('policy_publications',0);
        $this->actingAs($customer)->get($policy->publicUrl())->assertNotFound();
        $this->get('/account/policy-receipts')->assertOk()->assertDontSee('Marketplace Service Terms');
    }

    public function test_only_super_admin_can_read_the_policy_receipt_register(): void
    {
        $admin=$this->person('admin');
        $superAdmin=$this->person('super_admin');
        $policy=$this->draftPolicy();
        $this->publish($admin,$policy,str_repeat('A published official service policy. ',3));
        $this->actingAs($admin)->get('/dashboard/compliance-register')->assertForbidden();
        $this->actingAs($superAdmin)->get('/dashboard/compliance-register')
            ->assertOk()->assertSee('Compliance Register')->assertSee('Marketplace Service Terms');
    }

    public function test_another_users_receipt_cannot_be_changed_by_anyone_else(): void
    {
        $admin=$this->person('admin');
        $customer=$this->person('customer');
        $other=$this->person('customer');
        $policy=$this->draftPolicy();
        $publication=$this->publish($admin,$policy,str_repeat('Published conditions and professional responsibility. ',2));

        $this->actingAs($customer)->post('/policies/acknowledgements/'.$publication->id,['confirm_read'=>'1'])
            ->assertRedirect();
        $this->actingAs($other)->get('/account/policy-receipts')
            ->assertOk()->assertDontSee($policy->title);
        $this->actingAs($other)->post('/policies/acknowledgements/'.$publication->id,[
            'confirm_read'=>'1',
        ])->assertRedirect();
        $this->assertDatabaseCount('policy_acknowledgements',2);
        $this->assertDatabaseHas('audit_events',['event'=>'policy.read.acknowledged','actor_id'=>$other->id]);
    }
}
