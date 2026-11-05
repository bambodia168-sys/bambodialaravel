<?php

namespace Tests\Feature;

use App\Models\AccountNotification;
use App\Models\ContractConsent;
use App\Models\ContractDispute;
use App\Models\ContractMessage;
use App\Models\ContractMilestone;
use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MilestoneReview;
use App\Models\MilestoneSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreelanceContractsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;
    private User $freelancer;
    private User $stranger;
    private User $admin;
    private MarketplaceJob $job;
    private JobProposal $proposal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client=$this->user('customer');
        $this->freelancer=$this->user('freelancer');
        $this->stranger=$this->user('customer');
        $this->admin=$this->user('admin');
        $this->job=MarketplaceJob::query()->create([
            'client_id'=>$this->client->id,
            'title'=>'International Laravel Marketplace Development',
            'description'=>str_repeat('Build original Laravel marketplace features with acceptance testing. ',2),
            'category'=>'development',
            'budget'=>'1000.00',
            'status'=>'published',
        ]);
        $this->proposal=JobProposal::query()->create([
            'marketplace_job_id'=>$this->job->id,
            'freelancer_id'=>$this->freelancer->id,
            'cover_message'=>str_repeat('I can deliver and verify the requested scope. ',2),
            'proposed_budget'=>'1000.00',
            'status'=>'shortlisted',
        ]);
    }

    private function user(string $role): User
    {
        return User::query()->create([
            'name'=>'Contract '.$role,
            'email'=>uniqid('contract'.$role,true).'@example.test',
            'password'=>'AComplexAccountPassword123!',
            'role'=>$role,
        ]);
    }

    private function fields(): array
    {
        return [
            'proposal_id'=>$this->proposal->id,
            'title'=>'Custom International Laravel Platform Development',
            'scope'=>str_repeat('Create an original multi-language application with verifiable milestones and documented quality checks. ',2),
            'terms'=>str_repeat('Milestone deliverables must be reviewed and explicitly accepted by the client. No escrow or payments are handled by the platform. ',2),
            'agreed_budget'=>'1000.00',
        ];
    }

    private function draft(): FreelanceContract
    {
        $this->actingAs($this->client)->post('/contracts',$this->fields())->assertRedirect();
        return FreelanceContract::query()->firstOrFail();
    }

    private function add(FreelanceContract $contract,string $name,string $amount): ContractMilestone
    {
        $this->actingAs($this->client)->post('/contracts/'.$contract->public_id.'/milestones',[
            'title'=>$name,
            'description'=>str_repeat('A complete original deliverable with measurable acceptance criteria. ',2),
            'amount'=>$amount,
        ])->assertRedirect();
        return ContractMilestone::query()->where('freelance_contract_id',$contract->id)->latest('id')->firstOrFail();
    }

    private function offered(): array
    {
        $contract=$this->draft();
        $first=$this->add($contract,'Architecture deliverable','400.00');
        $second=$this->add($contract,'User interface and QA deliverable','600.00');
        $this->actingAs($this->client)->post('/contracts/'.$contract->public_id.'/offer',[
            'confirm_offer'=>'1',
        ])->assertRedirect();
        $this->assertSame('offered',$contract->fresh()->status);
        return [$contract,$first,$second];
    }

    private function active(): array
    {
        [$contract,$first,$second]=$this->offered();
        $this->actingAs($this->freelancer)->post('/contracts/'.$contract->public_id.'/respond',[
            'decision'=>'accepted','confirm_terms'=>'1',
        ])->assertRedirect();
        $this->assertSame('active',$contract->fresh()->status);
        return [$contract,$first,$second];
    }

    private function deliver(ContractMilestone $milestone): MilestoneSubmission
    {
        $this->actingAs($this->freelancer)->post('/contract-milestones/'.$milestone->id.'/deliver',[
            'delivery_notes'=>str_repeat('The agreed deliverables have been completed and independently checked. ',2),
            'evidence_url'=>'https://example.test/evidence/'.$milestone->sequence,
        ])->assertRedirect();
        return MilestoneSubmission::query()->where('contract_milestone_id',$milestone->id)->latest('id')->firstOrFail();
    }

    private function review(ContractMilestone $milestone,string $decision): MilestoneReview
    {
        $this->actingAs($this->client)->post('/contract-milestones/'.$milestone->id.'/review',[
            'decision'=>$decision,
            'feedback'=>str_repeat('Review completed against the original acceptance criteria. ',2),
        ])->assertRedirect();
        return MilestoneReview::query()->latest('id')->firstOrFail();
    }

    public function test_client_can_create_private_draft_with_nine_digit_contract_id(): void
    {
        $contract=$this->draft();
        $this->assertMatchesRegularExpression('/^[0-9]{9}$/',$contract->public_id);
        $this->assertSame('draft',$contract->status);
        $this->actingAs($this->client)->get('/contracts/'.$contract->public_id)
            ->assertOk()->assertSee($contract->title)->assertSee('Agreement scope and terms');
        $this->actingAs($this->freelancer)->get('/contracts/'.$contract->public_id)->assertNotFound();
        $this->get('/contracts')->assertDontSee($contract->title);
        $this->actingAs($this->stranger)->get('/contracts/'.$contract->public_id)->assertNotFound();
        $this->actingAs($this->admin)->get('/contracts/'.$contract->public_id)->assertNotFound();
        $this->get('/dashboard/contract-manager')->assertOk()->assertSee($contract->public_id);
        $this->assertDatabaseHas('audit_events',['event'=>'contract.draft.created','actor_id'=>$this->client->id]);
    }

    public function test_only_shortlisted_application_owner_can_create_one_agreement(): void
    {
        $this->actingAs($this->stranger)->post('/contracts',$this->fields())->assertNotFound();
        $this->actingAs($this->freelancer)->post('/contracts',$this->fields())->assertForbidden();
        $this->proposal->update(['status'=>'submitted']);
        $this->actingAs($this->client)->post('/contracts',$this->fields())->assertStatus(409);
        $this->proposal->update(['status'=>'shortlisted']);
        $this->draft();
        $this->actingAs($this->client)->post('/contracts',$this->fields())->assertStatus(409);
        $this->assertDatabaseCount('freelance_contracts',1);
    }

    public function test_milestone_total_must_match_budget_and_offer_is_immutable(): void
    {
        $contract=$this->draft();
        $first=$this->add($contract,'First stage deliverables','350.00');
        $this->actingAs($this->client)->post('/contracts/'.$contract->public_id.'/offer',[
            'confirm_offer'=>'1',
        ])->assertSessionHasErrors('confirm_offer');
        $this->add($contract,'Remaining project deliverables','650.00');
        $this->post('/contracts/'.$contract->public_id.'/offer',['confirm_offer'=>'1'])->assertRedirect();

        $digest=$contract->fresh()->terms_sha256;
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$digest);
        $this->assertSame(1,ContractConsent::query()->where('freelance_contract_id',$contract->id)->count());
        $this->assertSame($digest,$contract->fresh()->termsDigest());
        $this->post('/contracts/'.$contract->public_id.'/milestones',[
            'title'=>'Malicious new scope','description'=>str_repeat('Extra work added without acceptance. ',2),
            'amount'=>'1.00',
        ])->assertStatus(409);
        $this->delete('/contract-milestones/'.$first->id)->assertStatus(409);

        $this->actingAs($this->freelancer)->post('/contracts/'.$contract->public_id.'/respond',[
            'decision'=>'accepted',
        ])->assertSessionHasErrors('confirm_terms');
        $this->post('/contracts/'.$contract->public_id.'/respond',[
            'decision'=>'accepted','confirm_terms'=>'1',
        ])->assertRedirect();
        $this->assertSame($digest,ContractConsent::query()->where('action','accepted')->firstOrFail()->terms_sha256);
        $this->assertDatabaseHas('account_notifications',['user_id'=>$this->client->id,'event'=>'contract.accepted']);
        $this->assertDatabaseHas('account_notifications',['user_id'=>$this->freelancer->id,'event'=>'contract.offered']);
    }

    public function test_decline_and_unaccepted_withdrawal_are_final(): void
    {
        [$contract]=$this->offered();
        $this->actingAs($this->freelancer)->post('/contracts/'.$contract->public_id.'/respond',[
            'decision'=>'declined',
        ])->assertRedirect();
        $this->assertSame('declined',$contract->fresh()->status);
        $this->post('/contracts/'.$contract->public_id.'/respond',[
            'decision'=>'accepted','confirm_terms'=>'1',
        ])->assertStatus(409);

        $this->actingAs($this->client)->post('/contracts/'.$contract->public_id.'/cancel')->assertStatus(409);
    }

    public function test_milestone_delivery_revision_and_completion_require_both_parties(): void
    {
        [$contract,$first,$second]=$this->active();

        $this->actingAs($this->freelancer)->post('/contract-milestones/'.$second->id.'/deliver',[
            'delivery_notes'=>str_repeat('A later stage cannot start before prior acceptance. ',2),
        ])->assertStatus(409);

        $submission=$this->deliver($first);
        $this->assertSame('submitted',$first->fresh()->status);
        $this->actingAs($this->stranger)->post('/contract-milestones/'.$first->id.'/review',[
            'decision'=>'accepted','feedback'=>str_repeat('I am not the owner. ',2),
        ])->assertNotFound();
        $this->review($first,'revision_requested');
        $this->assertSame('revision_requested',$first->fresh()->status);
        $this->assertDatabaseHas('milestone_reviews',['milestone_submission_id'=>$submission->id,'decision'=>'revision_requested']);

        $this->deliver($first);
        $this->review($first,'accepted');
        $this->assertSame('accepted',$first->fresh()->status);
        $this->deliver($second);
        $this->review($second,'accepted');

        $this->actingAs($this->client)->post('/contracts/'.$contract->public_id.'/complete',[
            'confirm_completion'=>'1',
        ])->assertStatus(409);
        $this->actingAs($this->freelancer)->post('/contracts/'.$contract->public_id.'/acknowledge-completion',[
            'confirm_completion'=>'1',
        ])->assertRedirect();
        $this->actingAs($this->client)->post('/contracts/'.$contract->public_id.'/complete',[
            'confirm_completion'=>'1',
        ])->assertRedirect();

        $this->assertSame('completed',$contract->fresh()->status);
        $this->assertDatabaseCount('milestone_submissions',3);
        $this->assertDatabaseCount('milestone_reviews',3);
        $this->assertDatabaseHas('audit_events',['event'=>'contract.completed','actor_id'=>$this->client->id]);
        $this->assertDatabaseHas('account_notifications',['event'=>'contract.completed','user_id'=>$this->freelancer->id]);
    }

    public function test_private_conversations_are_visible_to_participants_only(): void
    {
        [$contract]=$this->active();

        $this->actingAs($this->client)->post('/contracts/'.$contract->public_id.'/messages',[
            'body'=>'This is private scope clarification for the agreement.',
        ])->assertRedirect();
        $this->assertDatabaseCount('contract_messages',1);
        $this->actingAs($this->freelancer)->get('/contracts/'.$contract->public_id)
            ->assertOk()->assertSee('private scope clarification');
        $this->actingAs($this->admin)->get('/contracts/'.$contract->public_id)
            ->assertNotFound();
        $this->actingAs($this->stranger)->post('/contracts/'.$contract->public_id.'/messages',[
            'body'=>'I should not see this confidential discussion.',
        ])->assertNotFound();
        $this->assertDatabaseHas('account_notifications',['event'=>'contract.message.received','user_id'=>$this->freelancer->id]);
    }

    public function test_dispute_pauses_deliveries_and_independent_administrator_can_resolve(): void
    {
        [$contract,$first]=$this->active();
        $this->actingAs($this->client)->post('/contracts/'.$contract->public_id.'/disputes',[
            'category'=>'timeline','details'=>str_repeat('The agreed timeline was not respected; please inspect the milestone history. ',2),
            'confirm_report'=>'1',
        ])->assertRedirect();
        $this->assertSame('disputed',$contract->fresh()->status);
        $dispute=ContractDispute::firstOrFail();
        $this->assertSame('open',$dispute->status);

        $this->actingAs($this->freelancer)->post('/contract-milestones/'.$first->id.'/deliver',[
            'delivery_notes'=>str_repeat('A paused case must stop acceptance actions. ',2),
        ])->assertStatus(409);

        $this->actingAs($this->admin)->get('/dashboard/contract-disputes')->assertOk()
            ->assertSee($contract->public_id);
        $this->get('/dashboard/contract-disputes/'.$dispute->id)
            ->assertOk()->assertSee('agreed timeline was not respected');

        $this->patch('/dashboard/contract-disputes/'.$dispute->id,[
            'decision'=>'under_review',
        ])->assertRedirect();
        $this->patch('/dashboard/contract-disputes/'.$dispute->id,[
            'decision'=>'resolved','disposition'=>'resume',
            'resolution_note'=>str_repeat('After reviewing the reported evidence, the workflow can resume. ',2),
        ])->assertRedirect();
        $this->assertSame('resolved',$dispute->fresh()->status);
        $this->assertSame('active',$contract->fresh()->status);
        $this->assertDatabaseHas('audit_events',['event'=>'contract.dispute.inspected','actor_id'=>$this->admin->id]);
        $this->assertDatabaseHas('audit_events',['event'=>'contract.dispute.resolved','actor_id'=>$this->admin->id]);
    }

    public function test_administrator_cannot_unilaterally_change_private_contract_terms(): void
    {
        [$contract]=$this->active();
        $this->actingAs($this->admin)->get('/dashboard/contract-manager')->assertOk();
        $this->get('/contracts/'.$contract->public_id)->assertNotFound();
        $this->post('/contracts/'.$contract->public_id.'/offer',['confirm_offer'=>'1'])->assertNotFound();

        $this->actingAs($this->stranger)->post('/contracts/'.$contract->public_id.'/disputes',[
            'category'=>'other','details'=>str_repeat('An unrelated account cannot report a case. ',2),
            'confirm_report'=>'1',
        ])->assertNotFound();
        $this->assertDatabaseCount('contract_disputes',0);
    }
}
