<?php

namespace Tests\Feature;

use App\Models\FreelancerResume;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JobsResumeTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        return User::query()->create([
            'name' => 'Marketplace '.$role,
            'email' => uniqid($role, true).'@example.test',
            'password' => 'StrongPassword123!',
            'role' => $role,
        ]);
    }

    private function jobDetails(): array
    {
        return [
            'title'=>'Laravel migration and responsive marketplace build',
            'description'=>str_repeat('Build a properly tested Laravel application with clear scope. ', 3),
            'category'=>'development',
            'budget'=>'1200.00',
        ];
    }

    private function createResume(User $user): FreelancerResume
    {
        return FreelancerResume::query()->create([
            'user_id'=>$user->id,
            'headline'=>'Senior Laravel Developer and Digital Product Creator',
            'summary'=>str_repeat('I create secure Laravel applications for international businesses. ', 2),
            'experience'=>str_repeat('Built real customer systems and delivered original licensed code. ', 2),
            'skills'=>'PHP, Laravel, JavaScript, SQL, Tailwind and accessibility',
            'is_visible'=>true,
        ]);
    }

    public function test_clients_post_jobs_for_review_then_public_board_shows_only_approved(): void
    {
        $customer=$this->account('customer');
        $admin=$this->account('admin');

        $this->actingAs($customer)->post('/my-jobs',$this->jobDetails())->assertRedirect();
        $job=MarketplaceJob::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^[0-9]{9}$/',$job->public_id);
        $this->assertSame('pending',$job->status);

        $this->get('/jobs')->assertOk()->assertDontSee($job->title);
        $this->get($job->publicUrl())->assertNotFound();
        $this->actingAs($admin)->get('/dashboard/job-manager')->assertOk()->assertSee($job->title);
        $this->patch('/dashboard/job-manager/'.$job->id,['decision'=>'published'])->assertRedirect();
        $this->get('/jobs')->assertOk()->assertSee($job->title);
        $this->get($job->publicUrl())->assertOk()->assertSee($job->title);
        $this->assertDatabaseHas('audit_events',['event'=>'job.review.published','actor_id'=>$admin->id]);
    }

    public function test_freelancer_requires_published_resume_before_applying_and_cannot_duplicate(): void
    {
        $client=$this->account('customer');
        $freelancer=$this->account('freelancer');
        $job=MarketplaceJob::query()->create([...$this->jobDetails(),'client_id'=>$client->id,'status'=>'published']);

        $this->actingAs($freelancer)->post('/jobs/'.$job->id.'/apply',[
            'cover_message'=>str_repeat('I can deliver the requested scope with documented tests. ', 2),
            'proposed_budget'=>'1000.00',
        ])->assertForbidden();

        $this->post('/resume-builder',[])->assertStatus(405);
        $this->patch('/resume-builder',[
            'headline'=>'Professional Laravel Consultant',
            'summary'=>str_repeat('I design maintainable web applications for clients. ',2),
            'experience'=>str_repeat('I have built customer-facing systems and tested delivery workflows. ',2),
            'skills'=>'Laravel, PHP, Tailwind, JavaScript',
            'is_visible'=>'1',
        ])->assertRedirect();
        $this->assertTrue(FreelancerResume::query()->where('user_id',$freelancer->id)->firstOrFail()->is_visible);

        $this->post('/jobs/'.$job->id.'/apply',[
            'cover_message'=>str_repeat('I can deliver the requested scope with documented tests. ', 2),
            'proposed_budget'=>'1000.00',
        ])->assertRedirect();

        $this->post('/jobs/'.$job->id.'/apply',[
            'cover_message'=>str_repeat('A second proposal should be rejected after initial submission. ', 2),
        ])->assertStatus(409);

        $this->get('/my-proposals')->assertOk()->assertSee($job->title);
        $this->assertDatabaseCount('job_proposals',1);
        $this->assertDatabaseHas('audit_events',['event'=>'job.proposal.submitted','actor_id'=>$freelancer->id]);
    }

    public function test_only_job_owner_sees_private_proposals_and_can_review(): void
    {
        $owner=$this->account('customer');
        $stranger=$this->account('customer');
        $freelancer=$this->account('freelancer');
        $this->createResume($freelancer);
        $job=MarketplaceJob::query()->create([...$this->jobDetails(),'client_id'=>$owner->id,'status'=>'published']);
        $proposal=JobProposal::query()->create([
            'marketplace_job_id'=>$job->id,
            'freelancer_id'=>$freelancer->id,
            'cover_message'=>str_repeat('I can support and complete this project. ',2),
            'status'=>'submitted',
        ]);

        $this->actingAs($stranger)->get('/my-jobs/'.$job->id.'/proposals')->assertNotFound();
        $this->patch('/my-jobs/proposals/'.$proposal->id,['decision'=>'shortlisted'])->assertNotFound();
        $this->actingAs($owner)->get('/my-jobs/'.$job->id.'/proposals')
            ->assertOk()->assertSee('Senior Laravel Developer');
        $this->patch('/my-jobs/proposals/'.$proposal->id,['decision'=>'shortlisted'])->assertRedirect();
        $this->assertSame('shortlisted',$proposal->fresh()->status);

        $this->actingAs($freelancer)->get('/my-proposals')->assertSee('Shortlisted');
        $this->patch('/my-jobs/proposals/'.$proposal->id,['decision'=>'declined'])->assertNotFound();
    }

    public function test_job_close_prevents_new_proposals_and_staff_cannot_publish(): void
    {
        $owner=$this->account('subscriber');
        $staff=$this->account('staff');
        $freelancer=$this->account('freelancer');
        $this->createResume($freelancer);
        $job=MarketplaceJob::query()->create([...$this->jobDetails(),'client_id'=>$owner->id,'status'=>'published']);

        $this->actingAs($staff)->patch('/dashboard/job-manager/'.$job->id,['decision'=>'rejected'])->assertForbidden();
        $this->actingAs($owner)->patch('/my-jobs/'.$job->id.'/close')->assertRedirect();
        $this->assertSame('closed',$job->fresh()->status);
        $this->actingAs($freelancer)->post('/jobs/'.$job->id.'/apply',[
            'cover_message'=>str_repeat('This is a complete personal application proposal. ',2),
        ])->assertStatus(409);
    }

    public function test_client_cannot_apply_freelancer_cannot_post_job(): void
    {
        $customer=$this->account('customer');
        $freelancer=$this->account('freelancer');
        $job=MarketplaceJob::query()->create([...$this->jobDetails(),'client_id'=>$customer->id,'status'=>'published']);
        $this->actingAs($customer)->post('/jobs/'.$job->id.'/apply',[
            'cover_message'=>str_repeat('A customer should not submit a freelancer application. ',2),
        ])->assertForbidden();
        $this->actingAs($freelancer)->post('/my-jobs',$this->jobDetails())->assertForbidden();
        $this->get('/dashboard/job-manager')->assertForbidden();
    }
}
