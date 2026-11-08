<?php

namespace Tests\Feature;

use App\Models\MarketplaceJob;
use App\Models\SavedJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageJobListTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(User $client,string $title,?string $budget='550.00',string $status='published'): MarketplaceJob
    {
        return MarketplaceJob::query()->create([
            'client_id'=>$client->id,
            'title'=>$title,
            'description'=>str_repeat('A clear original client brief with deliverables and approval details. ',3),
            'category'=>'development',
            'budget'=>$budget,
            'currency'=>'USD',
            'status'=>$status,
        ]);
    }

    public function test_homepage_uses_premium_rows_only_for_five_latest_published_jobs(): void
    {
        $client=User::query()->create([
            'name'=>'Cambodia Digital Studio','email'=>'client-job-rows@example.test',
            'password'=>'TestingOnlySafePassword123!','role'=>'customer',
        ]);
        $oldest=$this->makeJob($client,'Oldest approved professional project');
        foreach(range(1,5) as $n){
            $this->makeJob($client,'Recently approved professional project '.$n);
        }
        $this->makeJob($client,'Confidential draft job','99.00','pending');
        $this->makeJob($client,'Closed professional project','50.00','closed');
        $response=$this->get('/')->assertOk()
            ->assertSee('id="bambodia-jobs"',false)
            ->assertSee('Latest approved jobs')
            ->assertSee('Cambodia Digital Studio')
            ->assertSee('USD · Project budget')
            ->assertSee('View job')
            ->assertDontSee($oldest->title)
            ->assertDontSee('Confidential draft job')
            ->assertDontSee('Closed professional project')
            ->assertDontSee('Quick Apply');
        $this->assertSame(5,substr_count($response->getContent(),'class="bambodia-job-row"'));
    }

    public function test_directory_uses_same_component_and_handles_preview_and_unspecified_budgets(): void
    {
        $client=User::query()->create([
            'name'=>'Independent Hiring Partner','email'=>'client-job-directory@example.test',
            'password'=>'TestingOnlySafePassword123!','role'=>'customer',
        ]);
        $normal=$this->makeJob($client,'Backend PHP Engineer',null);
        $preview=$this->makeJob($client,'[LAYOUT TEST] Design User Experience','240.00');
        $response=$this->get('/jobs')->assertOk()
            ->assertSee('Approved job search results')
            ->assertSee('On request')
            ->assertSee('Budget not specified')
            ->assertSee('Preview only')
            ->assertSee('View preview')
            ->assertSee('View job')
            ->assertSee(e($normal->publicUrl()),false)
            ->assertSee(e($preview->publicUrl()),false);
        $this->assertSame(2,substr_count($response->getContent(),'class="bambodia-job-row"'));
    }

    public function test_signed_in_save_state_preserved_in_both_job_lists(): void
    {
        $client=User::query()->create([
            'name'=>'Client Company','email'=>'client-job-save@example.test',
            'password'=>'TestingOnlySafePassword123!','role'=>'customer',
        ]);
        $freelancer=User::query()->create([
            'name'=>'Freelance Worker','email'=>'worker-job-save@example.test',
            'password'=>'TestingOnlySafePassword123!','role'=>'freelancer',
        ]);
        $job=$this->makeJob($client,'Approved Full Stack Development');
        SavedJob::query()->create(['user_id'=>$freelancer->id,'marketplace_job_id'=>$job->id]);
        $this->actingAs($freelancer);
        foreach(['/','/jobs'] as $path){
            $this->get($path)->assertOk()
                ->assertSee('Remove Approved Full Stack Development from saved jobs')
                ->assertSee('Saved')
                ->assertSee('method="post"',false);
        }
    }
}
