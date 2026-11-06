<?php

namespace Tests\Feature;

use App\Models\AccountNotification;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowDepthTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::query()->create([
            'name' => 'Workflow '.$role,
            'email' => uniqid('workflow-', true).'@example.test',
            'role' => $role,
            'password' => 'SecureTestAccount123!',
        ]);
    }

    private function listing(User $creator, string $title, string $type = 'product', string $status = 'draft'): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id' => $creator->id,
            'title' => $title,
            'type' => $type,
            'status' => $status,
            'description' => str_repeat('Original licensed digital deliverables and professional support. ', 2),
        ]);
    }

    public function test_moderation_filters_and_decision_audit_include_only_real_records(): void
    {
        $reviewer = $this->member('admin');
        $creator = $this->member('freelancer');
        $pendingProduct = $this->listing($creator, 'Private pending product', 'product', 'pending');
        $this->listing($creator, 'Private pending service', 'service', 'pending');
        $this->listing($creator, 'Already approved service', 'service', 'published');
        AuditEvent::record('listing.review.rejected', $reviewer);

        $response = $this->actingAs($reviewer)->get('/dashboard/review-queue?type=product&q=product');
        $response->assertOk()->assertSee($pendingProduct->title)
            ->assertDontSee('Private pending service')
            ->assertDontSee('Already approved service')
            ->assertSee('Recent moderation audit')
            ->assertSee('Rejected listing')
            ->assertViewHas('listings', fn ($page) => $page->total() === 1)
            ->assertViewHas('counts', fn ($counts) => (int)$counts->get('product') === 1
                && (int)$counts->get('service') === 1);
        $this->get('/dashboard/review-queue?type=unapproved')->assertNotFound();
        $this->get('/dashboard/review-queue?q='.str_repeat('a', 121))->assertNotFound();
        $this->actingAs($this->member('staff'))->get('/dashboard/review-queue')->assertForbidden();
    }

    public function test_support_search_preserves_queue_status_and_restricts_permission(): void
    {
        $agent = $this->member('staff');
        $customer = $this->member('customer');
        SupportTicket::query()->create([
            'user_id' => $customer->id,
            'subject' => 'Help with an original digital order',
            'status' => 'open',
        ]);
        SupportTicket::query()->create([
            'user_id' => $customer->id,
            'subject' => 'Resolved unrelated request',
            'status' => 'resolved',
        ]);
        $this->actingAs($agent)->get('/dashboard/support-center?status=open&q=digital')
            ->assertOk()->assertSee('Help with an original digital order')
            ->assertDontSee('Resolved unrelated request')
            ->assertSee('Support ticket status')
            ->assertViewHas('tickets', fn ($page) => $page->total() === 1);
        $this->get('/dashboard/support-center?status=unknown')->assertNotFound();
        $this->actingAs($customer)->get('/dashboard/support-center')->assertForbidden();
    }

    public function test_creator_product_filter_is_owned_searchable_and_release_status_is_private(): void
    {
        $creator = $this->member('freelancer');
        $other = $this->member('freelancer');
        $draft = $this->listing($creator, 'My original theme draft', 'product', 'draft');
        $this->listing($creator, 'My approved template', 'product', 'published');
        $foreign = $this->listing($other, 'Other creator private theme', 'product', 'draft');

        $this->actingAs($creator)->get('/studio/products?status=draft&q=theme')
            ->assertOk()->assertSee($draft->title)
            ->assertDontSee('My approved template')
            ->assertDontSee($foreign->title)
            ->assertSee('Your products by status')
            ->assertViewHas('products', fn ($page) => $page->total() === 1)
            ->assertViewHas('counts', fn ($counts) => (int)$counts->get('draft') === 1);
        $this->get('/studio/products?status=invalid')->assertNotFound();
        $this->get('/studio/products?q='.str_repeat('a',121))->assertNotFound();
        $this->get('/studio/products/'.$draft->id.'/releases?status=pending')
            ->assertOk()->assertSee('Your file version statuses');
        $this->get('/studio/products/'.$draft->id.'/releases?status=unknown')->assertNotFound();
        $this->get('/studio/products/'.$foreign->id.'/releases')->assertNotFound();
        $this->actingAs($this->member('customer'))->get('/studio/products')->assertForbidden();
    }

    public function test_account_read_state_and_event_filters_do_not_disclose_other_accounts(): void
    {
        $owner = $this->member('customer');
        $other = $this->member('customer');
        AccountNotification::deliver($owner->id, 'job.reviewed', 'My reviewed application', 'Status recorded.', '/my-jobs');
        AccountNotification::deliver($owner->id, 'contract.offered', 'My new contract', 'Please review.', '/contracts');
        AccountNotification::deliver($other->id, 'job.reviewed', 'Other user private activity', 'Private.', '/my-jobs');

        $this->actingAs($owner)->get('/account/notifications?filter=unread&event=job.reviewed')
            ->assertOk()->assertSee('My reviewed application')
            ->assertDontSee('My new contract')->assertDontSee('Other user private activity')
            ->assertViewHas('notifications', fn ($page) => $page->total() === 1);
        $this->get('/account/notifications?event=invalid')->assertNotFound();
        $this->get('/account/notifications?filter=unsupported')->assertNotFound();
        $this->patch('/account/notifications/read-all')->assertRedirect();
        $this->get('/account/notifications?filter=read&event=contract.offered')
            ->assertOk()->assertSee('My new contract')->assertDontSee('My reviewed application');
        $this->assertSame(1, AccountNotification::query()->where('user_id',$other->id)->whereNull('read_at')->count());
    }

    public function test_private_contract_status_filters_keep_other_participants_and_drafts_hidden(): void
    {
        $client = $this->member('customer');
        $otherClient = $this->member('customer');
        $freelancer = $this->member('freelancer');
        $create = function(User $c, string $title, string $status) use ($freelancer): void {
            $job = MarketplaceJob::query()->create([
                'client_id' => $c->id, 'title' => 'Private client contract brief',
                'description' => str_repeat('Original engagement with milestones. ', 3),
                'category' => 'development', 'status' => 'published',
            ]);
            $proposal = JobProposal::query()->create([
                'marketplace_job_id' => $job->id, 'freelancer_id' => $freelancer->id,
                'cover_message' => str_repeat('Professional project delivery. ', 3),
                'status' => 'accepted',
            ]);
            FreelanceContract::query()->create([
                'job_proposal_id' => $proposal->id,
                'client_id' => $c->id, 'freelancer_id' => $freelancer->id,
                'title' => $title, 'scope' => 'Private scope of work',
                'terms' => 'Private milestone agreement',
                'agreed_budget' => '100.00', 'status' => $status,
            ]);
        };
        $create($client, 'My offered contract', 'offered');
        $create($otherClient, 'Another customer active contract', 'active');
        $create($otherClient, 'Hidden unoffered contract draft', 'draft');

        $this->actingAs($freelancer)->get('/contracts?status=offered')->assertOk()
            ->assertSee('My offered contract')
            ->assertDontSee('Another customer active contract')
            ->assertDontSee('Hidden unoffered contract draft');
        $this->get('/contracts?status=invalid')->assertNotFound();
        $this->actingAs($client)->get('/contracts?status=all')->assertOk()
            ->assertSee('My offered contract')->assertDontSee('Another customer active contract');
    }

    public function test_developer_activity_and_unused_token_diagnostics_are_owner_only(): void
    {
        $owner = $this->member('super_admin');
        $other = $this->member('super_admin');
        ApiToken::issue($owner, 'Unused read-only integration', ['account.read'], 30);
        [$used] = ApiToken::issue($owner, 'Used read-only integration', ['listings.read'], 30);
        $used->update(['last_used_at'=>now()]);
        ApiToken::issue($other, 'Other private integration', ['account.read'], 30);
        AuditEvent::record('developer.token.created', $owner);
        AuditEvent::record('developer.token.revoked', $other);

        $this->actingAs($owner)->get('/developer')->assertOk()
            ->assertSee('Credential activity history')
            ->assertSee('Credential diagnostics:')
            ->assertSee('Unused read-only integration')
            ->assertDontSee('Other private integration')
            ->assertViewHas('tokenStats', fn (array $stats) => $stats['unused'] === 1)
            ->assertViewHas('recentActivity', fn ($events) => $events->count() === 1
                && $events->first()->event === 'developer.token.created');
        $this->actingAs($this->member('admin'))->get('/developer')->assertForbidden();
    }
}
