<?php

namespace Tests\Feature;

use App\Models\ContractMilestone;
use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MilestoneSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ContractArtifactsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $freelancer;
    private User $other;
    private User $admin;
    private FreelanceContract $contract;
    private ContractMilestone $milestone;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->client=$this->account('customer');
        $this->freelancer=$this->account('freelancer');
        $this->other=$this->account('customer');
        $this->admin=$this->account('admin');
        $job=MarketplaceJob::query()->create([
            'client_id'=>$this->client->id,'title'=>'Original Digital Service Job',
            'description'=>str_repeat('Create an original digital product and deliver professional results. ',2),
            'category'=>'development','status'=>'published',
        ]);
        $proposal=JobProposal::query()->create([
            'marketplace_job_id'=>$job->id,'freelancer_id'=>$this->freelancer->id,
            'cover_message'=>str_repeat('I can deliver original work under this agreement. ',2),
            'status'=>'shortlisted',
        ]);
        $this->contract=FreelanceContract::query()->create([
            'client_id'=>$this->client->id,
            'freelancer_id'=>$this->freelancer->id,'job_proposal_id'=>$proposal->id,
            'title'=>'Delivery of Secure Code and Documentation',
            'scope'=>str_repeat('Original work will be delivered with client acceptance checks. ',2),
            'terms'=>str_repeat('Both parties will inspect actual deliverables before completion. ',2),
            'currency'=>'USD','agreed_budget'=>'100.00','status'=>'active',
        ]);
        $this->milestone=ContractMilestone::query()->create([
            'freelance_contract_id'=>$this->contract->id,
            'sequence'=>1,
            'title'=>'Functional project release',
            'description'=>str_repeat('Original project deliverable and source documentation. ',2),
            'amount'=>'100.00','status'=>'planned',
        ]);
    }

    private function account(string $role): User
    {
        return User::query()->create([
            'name'=>'Evidence '.$role,
            'email'=>uniqid('evidence'.$role,true).'@example.test',
            'password'=>'AReallyStrongPassword123!',
            'role'=>$role,
        ]);
    }

    private function pdf(string $payload="%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('client-deliverable.pdf',$payload);
    }

    private function upload(UploadedFile $file): MilestoneSubmission
    {
        $this->actingAs($this->freelancer)->post('/contract-milestones/'.$this->milestone->id.'/deliver',[
            'delivery_notes'=>str_repeat('The secure source artifact is attached with all expected deliverables. ',2),
            'artifact'=>$file,
        ])->assertRedirect();

        return MilestoneSubmission::query()->firstOrFail();
    }

    public function test_uploaded_milestone_file_is_private_integrity_checked_and_downloadable_by_both_parties(): void
    {
        $submission=$this->upload($this->pdf());
        $this->assertSame('pdf',$submission->file_type);
        $this->assertNotNull($submission->sha256);
        $this->assertStringStartsWith('marketplace/contract-deliverables/'.$this->contract->id.'/', $submission->storage_path);
        Storage::disk('local')->assertExists($submission->storage_path);

        $download=route('contracts.artifacts.download',$submission);
        $this->actingAs($this->other)->get($download)->assertNotFound();
        $this->actingAs($this->admin)->get($download)->assertNotFound();

        $name='bambodia-deliverable-'.$this->contract->public_id.'-1-'.$submission->id.'.pdf';
        $this->actingAs($this->client)->get($download)->assertOk()->assertDownload($name)
            ->assertHeader('X-Content-Type-Options','nosniff');
        $this->actingAs($this->freelancer)->get($download)->assertOk()->assertDownload($name);
        $this->assertDatabaseHas('audit_events',[
            'event'=>'contract.file.downloaded','actor_id'=>$this->client->id,
        ]);

        $this->actingAs($this->client)->post('/contract-milestones/'.$this->milestone->id.'/review',[
            'decision'=>'accepted','feedback'=>str_repeat('The original submitted artifact passed our review. ',2),
        ])->assertRedirect();
        $this->assertSame('accepted',$this->milestone->fresh()->status);
    }

    public function test_upload_validation_rejects_non_supported_files(): void
    {
        $this->actingAs($this->freelancer)->post('/contract-milestones/'.$this->milestone->id.'/deliver',[
            'delivery_notes'=>str_repeat('An attempt to submit a disguised executable file. ',2),
            'artifact'=>UploadedFile::fake()->createWithContent('dangerous.php','<?php echo 1;'),
        ])->assertSessionHasErrors('artifact');
        $this->assertDatabaseCount('milestone_submissions',0);

        $this->post('/contract-milestones/'.$this->milestone->id.'/deliver',[
            'delivery_notes'=>str_repeat('A badly formatted document should also be rejected. ',2),
            'artifact'=>$this->pdf('invalid'),
        ])->assertSessionHasErrors('artifact');
        $this->assertDatabaseCount('milestone_submissions',0);
    }

    public function test_corrupted_artifact_blocks_customer_download_and_acceptance(): void
    {
        $submission=$this->upload($this->pdf());
        Storage::disk('local')->put($submission->storage_path,'tampered bytes');
        $this->actingAs($this->client)->get(route('contracts.artifacts.download',$submission))->assertStatus(409);
        $this->post('/contract-milestones/'.$this->milestone->id.'/review',[
            'decision'=>'accepted','feedback'=>str_repeat('A missing file cannot be approved. ',2),
        ])->assertStatus(409);
        $this->assertSame('submitted',$this->milestone->fresh()->status);
    }

    public function test_independent_admin_can_inspect_artifact_only_after_a_dispute(): void
    {
        $submission=$this->upload($this->pdf());
        $url=route('contracts.artifacts.download',$submission);
        $this->actingAs($this->admin)->get($url)->assertNotFound();

        $this->actingAs($this->client)->post('/contracts/'.$this->contract->public_id.'/disputes',[
            'category'=>'quality',
            'details'=>str_repeat('The delivered document is disputed and an administrator should review it. ',2),
            'confirm_report'=>'1',
        ])->assertRedirect();

        $this->actingAs($this->admin)->get($url)->assertOk()->assertDownload();
        $this->assertDatabaseHas('audit_events',[
            'event'=>'contract.file.case_inspected','actor_id'=>$this->admin->id,
        ]);
        $this->actingAs($this->other)->get($url)->assertNotFound();
    }
}
