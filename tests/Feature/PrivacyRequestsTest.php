<?php

namespace Tests\Feature;

use App\Models\PrivacyRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyRequestsTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::query()->create([
            'name'=>'Privacy '.$role,
            'email'=>uniqid('privacy'.$role,true).'@example.test',
            'password'=>'StrongAccountPassword123!',
            'role'=>$role,
        ]);
    }

    private function submitted(User $user): PrivacyRequest
    {
        $this->actingAs($user)->post('/privacy-requests',[
            'category'=>'access',
            'details'=>str_repeat('Please tell me which account details Bambodia currently holds. ',2),
            'confirm_submission'=>'1',
        ])->assertRedirect();
        return PrivacyRequest::query()->where('user_id',$user->id)->latest('id')->firstOrFail();
    }

    public function test_user_can_submit_private_nine_digit_privacy_request(): void
    {
        $customer=$this->member('customer');
        $other=$this->member('customer');
        $record=$this->submitted($customer);
        $this->assertMatchesRegularExpression('/^[0-9]{9}$/',$record->public_id);
        $this->assertSame('submitted',$record->status);
        $this->get('/privacy-requests')->assertOk()->assertSee($record->public_id);
        $this->get('/privacy-requests/'.$record->public_id)->assertOk()
            ->assertSee('Please tell me which account details');
        $this->actingAs($other)->get('/privacy-requests/'.$record->public_id)->assertNotFound();
        $this->get('/privacy-requests')->assertDontSee($record->public_id);
        $this->assertDatabaseHas('audit_events',['event'=>'privacy.request.submitted','actor_id'=>$customer->id]);
    }

    public function test_only_super_admin_can_respond_and_own_request_conflict_is_denied(): void
    {
        $customer=$this->member('customer');
        $admin=$this->member('admin');
        $superAdmin=$this->member('super_admin');
        $record=$this->submitted($customer);

        $this->actingAs($admin)->get('/dashboard/privacy-requests')->assertForbidden();
        $this->actingAs($customer)->get('/dashboard/privacy-requests')->assertForbidden();
        $this->actingAs($superAdmin)->get('/dashboard/privacy-requests')
            ->assertOk()->assertSee($record->public_id);
        $this->get('/dashboard/privacy-requests/'.$record->public_id)
            ->assertOk()->assertSee('Please tell me which account details');

        $this->patch('/dashboard/privacy-requests/'.$record->public_id,[
            'decision'=>'responded',
            'response_note'=>str_repeat('The request has been independently reviewed and requires operator follow-up. ',2),
        ])->assertStatus(409);

        $this->patch('/dashboard/privacy-requests/'.$record->public_id,[
            'decision'=>'in_review',
        ])->assertRedirect();
        $this->patch('/dashboard/privacy-requests/'.$record->public_id,[
            'decision'=>'responded',
            'response_note'=>str_repeat('We reviewed your request and will provide a separate verified response where required. ',2),
        ])->assertRedirect();
        $this->assertSame('responded',$record->fresh()->status);
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$customer->id,
            'event'=>'privacy.request.responded',
        ]);

        $this->actingAs($customer)->get('/privacy-requests/'.$record->public_id)
            ->assertOk()->assertSee('separate verified response');
        $this->patch('/privacy-requests/'.$record->public_id.'/close',[
            'confirm_close'=>'1',
        ])->assertRedirect();
        $this->assertSame('closed',$record->fresh()->status);
        $this->assertDatabaseHas('audit_events',['event'=>'privacy.request.closed','actor_id'=>$customer->id]);

        $self=$this->submitted($superAdmin);
        $this->actingAs($superAdmin)->get('/dashboard/privacy-requests/'.$self->public_id)
            ->assertForbidden();
        $this->patch('/dashboard/privacy-requests/'.$self->public_id,['decision'=>'in_review'])
            ->assertForbidden();
    }

    public function test_user_cannot_close_unanswered_request_or_impersonate_another_owner(): void
    {
        $owner=$this->member('subscriber');
        $stranger=$this->member('customer');
        $record=$this->submitted($owner);
        $this->post('/privacy-requests',['category'=>'erasure','details'=>'Too short'])
            ->assertSessionHasErrors(['details','confirm_submission']);
        $this->patch('/privacy-requests/'.$record->public_id.'/close',[
            'confirm_close'=>'1',
        ])->assertStatus(409);
        $this->actingAs($stranger)->patch('/privacy-requests/'.$record->public_id.'/close',[
            'confirm_close'=>'1',
        ])->assertNotFound();
        $this->assertSame('submitted',$record->fresh()->status);
    }

    public function test_privacy_request_table_paginates_ten(): void
    {
        $owner=$this->member('subscriber');
        for($i=0;$i<12;$i++){
            $this->submitted($owner);
        }
        $this->actingAs($owner)->get('/privacy-requests')
            ->assertOk()->assertViewHas('requests',fn($page)=>$page->count()===10 && $page->total()===12);
        $this->actingAs($this->member('super_admin'))->get('/dashboard/privacy-requests')
            ->assertOk()->assertViewHas('requests',fn($page)=>$page->count()===10 && $page->total()===12);
    }
}
