<?php

namespace Tests\Feature;

use App\Models\MarketplaceJob;
use App\Models\SavedJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedJobDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role='freelancer'): User
    {
        return User::query()->create([
            'name'=>'Job Watchlist '.ucfirst($role).' '.uniqid(),
            'email'=>uniqid('watch'.$role,true).'@example.test',
            'password'=>'ASecretAccountPassword123!',
            'role'=>$role,
        ]);
    }

    private function job(User $client,string $title,string $status='published',?string $budget=null,string $category='development'): MarketplaceJob
    {
        return MarketplaceJob::query()->create([
            'client_id'=>$client->id,
            'title'=>$title,
            'description'=>str_repeat('A real professional job brief with original deliverables and clear project expectations. ',2),
            'category'=>$category,
            'budget'=>$budget,
            'currency'=>'USD',
            'status'=>$status,
        ]);
    }

    public function test_saving_jobs_requires_authentication_and_approved_publication(): void
    {
        $client=$this->account('customer');
        $freelancer=$this->account();
        $job=$this->job($client,'Professional Laravel Website Development');
        $draft=$this->job($client,'Confidential Pending Project','pending');

        $this->post('/account/saved-jobs/'.$job->id)->assertRedirect('/login');

        $this->actingAs($freelancer)->post('/account/saved-jobs/'.$draft->id)->assertNotFound();
        $this->post('/account/saved-jobs/'.$job->id)->assertRedirect();
        $this->post('/account/saved-jobs/'.$job->id)->assertRedirect();
        $this->assertDatabaseCount('saved_jobs',1);

        $this->get('/account/saved-jobs')->assertOk()->assertSee($job->title);
        $this->get($job->publicUrl())->assertOk()->assertSee('Saved — remove from list');
        $this->get('/jobs')->assertOk()->assertSee($job->title)->assertSee('Saved');
        $this->assertDatabaseHas('audit_events',['actor_id'=>$freelancer->id,'event'=>'job.saved']);
    }

    public function test_account_ownership_is_enforced_on_saved_jobs_and_closing_hides_private_job(): void
    {
        $client=$this->account('customer');
        $owner=$this->account('freelancer');
        $stranger=$this->account('freelancer');
        $job=$this->job($client,'Approved Data Analytics Freelance Assignment');
        $url='/account/saved-jobs/'.$job->id;

        $this->actingAs($owner)->post($url)->assertRedirect();
        $this->actingAs($stranger)->get('/account/saved-jobs')
            ->assertOk()->assertDontSee($job->title);
        $this->delete($url)->assertRedirect();
        $this->assertDatabaseCount('saved_jobs',1);

        $job->update(['status'=>'closed','closed_at'=>now()]);
        $this->actingAs($owner)->get('/account/saved-jobs')
            ->assertOk()->assertSee('Opportunity no longer available')
            ->assertDontSee($job->title);
        $this->get($job->publicUrl())->assertNotFound();
        $this->post($url)->assertNotFound();
        $this->delete($url)->assertRedirect();
        $this->assertDatabaseCount('saved_jobs',0);
        $this->assertDatabaseHas('audit_events',['actor_id'=>$owner->id,'event'=>'job.unsaved']);
    }

    public function test_actual_category_and_budget_sorting_on_public_job_board(): void
    {
        $client=$this->account('customer');
        $low=$this->job($client,'Affordable Website QA Review','published','70.00','development');
        $high=$this->job($client,'Enterprise Web Rebuild','published','2400.00','development');
        $this->job($client,'Special Design Proposal','published','350.00','design');
        $this->job($client,'Private Client Brief','pending','25.00','development');
        $this->job($client,'Unspecific Budget Job','published',null,'development');

        $this->get('/jobs?category=development&sort=budget_low')->assertOk()
            ->assertSee($low->title)->assertSee($high->title)
            ->assertDontSee('Special Design Proposal')
            ->assertDontSee('Private Client Brief')
            ->assertViewHas('jobs',fn($page)=>$page->first()?->id===$low->id);
        $this->get('/jobs?category=development&sort=budget_high')->assertOk()
            ->assertViewHas('jobs',fn($page)=>$page->first()?->id===$high->id);
        $this->get('/jobs?category=invalid')->assertNotFound();
        $this->get('/jobs?sort=malicious')->assertNotFound();
    }

    public function test_saved_job_collection_is_paginated_at_ten(): void
    {
        $client=$this->account('customer');
        $freelancer=$this->account();
        for($i=0;$i<12;$i++){
            $job=$this->job($client,'Approved Freelance Opportunity '.$i);
            SavedJob::query()->create([
                'user_id'=>$freelancer->id,
                'marketplace_job_id'=>$job->id,
            ]);
        }
        $this->actingAs($freelancer)->get('/account/saved-jobs')
            ->assertOk()->assertViewHas('saved',fn($page)=>$page->count()===10 && $page->total()===12);
        $this->get('/account?='.$freelancer->public_id)
            ->assertOk()->assertSee('Saved Jobs');
    }

    public function test_homepage_lists_current_approved_jobs_without_confidential_drafts(): void
    {
        $client=$this->account('customer');
        $freelancer=$this->account();
        $job=$this->job($client,'Approved New Freelance Project');
        $this->job($client,'Confidential Not Yet Approved Job','pending');

        $this->actingAs($freelancer)->post('/account/saved-jobs/'.$job->id)->assertRedirect();
        $this->get('/')->assertOk()->assertSee('Approved New Freelance Project')
            ->assertDontSee('Confidential Not Yet Approved Job')
            ->assertSee('Saved');
    }
}
