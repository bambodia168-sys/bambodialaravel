<?php

namespace Tests\Feature;

use App\Models\FreelancerResume;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\ServiceInquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendLayoutSamplesSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_layout_previews_are_viewable_but_block_real_inquiries_and_applications(): void
    {
        $creator=User::query()->create([
            'name'=>'Preview Creator','email'=>'qa-creator@example.test',
            'password'=>'StrongPassword123!','role'=>'freelancer',
        ]);
        $client=User::query()->create([
            'name'=>'Preview Client','email'=>'qa-client@example.test',
            'password'=>'StrongPassword123!','role'=>'customer',
        ]);
        $freelancer=User::query()->create([
            'name'=>'Preview Applicant','email'=>'qa-applicant@example.test',
            'password'=>'StrongPassword123!','role'=>'freelancer',
        ]);
        FreelancerResume::query()->create([
            'user_id'=>$freelancer->id,'headline'=>'Professional Developer',
            'summary'=>str_repeat('I can create original responsive design components. ',2),
            'experience'=>str_repeat('My experience includes frontend and backend delivery. ',2),
            'skills'=>'Laravel and frontend design','is_visible'=>true,
        ]);
        $listing=MarketplaceListing::query()->create([
            'user_id'=>$creator->id,'type'=>'service',
            'title'=>'[LAYOUT TEST] Responsive UX Review',
            'description'=>str_repeat('This is a fictional layout preview, not a real offer. ',2),
            'price'=>'60.00','status'=>'published',
        ]);
        $job=MarketplaceJob::query()->create([
            'client_id'=>$client->id,
            'title'=>'[LAYOUT TEST] Responsive Website Design',
            'description'=>str_repeat('This fictional job is only a preview for layout checking. ',2),
            'category'=>'design','budget'=>'300.00','status'=>'published',
        ]);
        $this->actingAs($client)->get($listing->publicUrl())->assertOk()
            ->assertSee('LAYOUT TEST ONLY')->assertDontSee('id="request-service"',false);
        $this->post('/inquiries/for/'.$listing->id,[
            'subject'=>'A real inquiry about the test service',
            'body'=>str_repeat('I would like more information about this project. ',2),
        ])->assertStatus(409);
        $this->assertDatabaseCount('service_inquiries',0);

        $this->actingAs($freelancer)->get($job->publicUrl())->assertOk()
            ->assertSee('fictional job; applications are disabled')
            ->assertDontSee('Submit proposal');
        $this->post('/jobs/'.$job->id.'/apply',[
            'cover_message'=>str_repeat('I can help with this responsive site project. ',2),
        ])->assertStatus(409);
        $this->assertDatabaseCount('job_proposals',0);
    }
}
