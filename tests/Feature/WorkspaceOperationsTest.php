<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\CreatorApplication;
use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\ServiceInquiry;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        return User::query()->create([
            'name' => 'Workspace '.$role,
            'email' => uniqid('workspace-operation-', true).'@example.test',
            'password' => 'StrongWorkspacePassword123!',
            'role' => $role,
        ]);
    }

    private function listing(User $creator, string $type, string $title, string $status): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id' => $creator->id,
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'description' => str_repeat('Original professional marketplace delivery with defined requirements. ', 2),
        ]);
    }

    private function job(User $client, string $status = 'pending'): MarketplaceJob
    {
        return MarketplaceJob::query()->create([
            'client_id' => $client->id,
            'title' => 'Genuine customer project request',
            'description' => str_repeat('An original client brief requiring independent work. ', 3),
            'category' => 'development',
            'status' => $status,
        ]);
    }

    private function contract(User $client, User $freelancer, string $title, string $status): FreelanceContract
    {
        $job = $this->job($client, 'published');
        $proposal = JobProposal::query()->create([
            'marketplace_job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
            'cover_message' => str_repeat('Proposal outlines professional project delivery. ', 2),
            'proposed_budget' => '100.00',
            'status' => 'accepted',
        ]);
        return FreelanceContract::query()->create([
            'job_proposal_id' => $proposal->id,
            'client_id' => $client->id,
            'freelancer_id' => $freelancer->id,
            'title' => $title,
            'scope' => str_repeat('Agreed services and deliverables. ', 3),
            'terms' => str_repeat('Written work terms and review procedures. ', 3),
            'agreed_budget' => '100.00',
            'status' => $status,
        ]);
    }

    public function test_support_staff_only_see_support_work_not_moderation_or_account_totals(): void
    {
        $staff = $this->account('staff');
        $creator = $this->account('freelancer');
        $this->listing($creator, 'service', 'Private moderation submission', 'pending');
        SupportTicket::query()->create([
            'user_id' => $creator->id,
            'subject' => 'Please review customer support response',
            'status' => 'open',
        ]);
        $response = $this->actingAs($staff)->get('/dashboard')->assertOk()
            ->assertSee('Support operations')
            ->assertSee('Unresolved support tickets')
            ->assertSee('Please review customer support response')
            ->assertDontSee('Private moderation submission')
            ->assertDontSee('Registered accounts')
            ->assertDontSee('Pending creator applications');
        $response->assertViewHas('stats', fn (array $stats) => $stats === [])
            ->assertViewHas('queues', fn (array $queues) => count($queues) === 1
                && $queues[0]['route'] === 'dashboard.support'
                && $queues[0]['count'] === 1);
        $this->get('/dashboard/review-queue')->assertForbidden();
        $this->get('/dashboard/user-manager')->assertForbidden();
        $this->get('/developer')->assertForbidden();
    }

    public function test_admin_queues_link_to_real_authorized_review_routes_and_counts(): void
    {
        $admin = $this->account('admin');
        $creator = $this->account('freelancer');
        $client = $this->account('customer');
        $this->listing($creator, 'service', 'Admin actionable pending service', 'pending');
        $this->listing($creator, 'product', 'Admin actionable pending product', 'pending');
        $this->job($client);
        CreatorApplication::query()->create([
            'user_id' => $client->id,
            'specialization' => 'Digital products',
            'experience' => 'Real commercial portfolio and original digital work',
            'status' => 'pending',
        ]);
        $this->actingAs($admin)->get('/dashboard')
            ->assertOk()
            ->assertSee('Queues requiring attention')
            ->assertSee('Admin actionable pending service')
            ->assertSee('Admin actionable pending product')
            ->assertSee('Pending creator applications')
            ->assertSee('Digital products awaiting review')
            ->assertSee('Job posts awaiting review')
            ->assertViewHas('queues', function (array $queues): bool {
                $mapped = collect($queues)->keyBy('route');
                return $mapped['dashboard.creator-applications']['count'] === 1
                    && $mapped['dashboard.reviews']['count'] === 2
                    && $mapped['dashboard.products.index']['count'] === 1
                    && $mapped['dashboard.jobs.index']['count'] === 1;
            });
        $this->get('/dashboard/product-manager?status=pending')->assertOk();
        $this->get('/dashboard/job-manager')->assertOk();
        $this->get('/dashboard/creator-applications')->assertOk();
        $this->get('/dashboard/review-queue')->assertOk();
        $this->get('/dashboard/user-manager')->assertForbidden();
    }

    public function test_creator_overview_contains_only_the_owners_work_and_private_inquiries(): void
    {
        $owner = $this->account('freelancer');
        $another = $this->account('freelancer');
        $client = $this->account('customer');
        $own = $this->listing($owner, 'service', 'Owner service awaiting review', 'pending');
        $this->listing($owner, 'product', 'Owner product being revised', 'draft');
        $this->listing($another, 'service', 'Another creator confidential service', 'pending');
        $foreign = $this->listing($another, 'service', 'Other creator client-only service', 'published');
        ServiceInquiry::query()->create([
            'marketplace_listing_id' => $own->id,
            'client_id' => $client->id,
            'creator_id' => $owner->id,
            'subject' => 'Private inquiry for this creator',
            'status' => 'open',
        ]);
        ServiceInquiry::query()->create([
            'marketplace_listing_id' => $foreign->id,
            'client_id' => $client->id,
            'creator_id' => $another->id,
            'subject' => 'Other creator private inquiry',
            'status' => 'open',
        ]);

        $this->actingAs($owner)->get('/creator')
            ->assertOk()
            ->assertSee('Owner service awaiting review')
            ->assertSee('Owner product being revised')
            ->assertSee('Private inquiry for this creator')
            ->assertDontSee('Another creator confidential service')
            ->assertDontSee('Other creator private inquiry')
            ->assertSee('Drafts and rejections')
            ->assertViewHas('stats', fn (array $stats) =>
                $stats['pending'] === 1 && $stats['products'] === 1
                && $stats['drafts'] === 0 && $stats['product_drafts'] === 1
                && $stats['product_pending'] === 0 && $stats['inquiries'] === 1)
            ->assertViewHas('recentListings', fn ($items) => $items->count() === 2)
            ->assertViewHas('openInquiries', fn ($items) => $items->count() === 1);
        $this->actingAs($client)->get('/creator')->assertRedirect('/creator/apply');
        $this->get('/studio/products')->assertForbidden();
    }

    public function test_account_only_shows_participant_agreements_and_excludes_other_private_drafts(): void
    {
        $client = $this->account('customer');
        $otherClient = $this->account('customer');
        $freelancer = $this->account('freelancer');
        $own = $this->contract($client, $freelancer, 'Own agreement requiring attention', 'offered');
        $this->contract($otherClient, $freelancer, 'Other client private agreement', 'active');
        $this->contract($otherClient, $freelancer, 'Unpublished freelancer private draft', 'draft');

        $this->actingAs($client)->get('/account?='.$client->public_id)
            ->assertOk()->assertSee('Agreements needing attention')
            ->assertSee('Own agreement requiring attention')
            ->assertDontSee('Other client private agreement')
            ->assertDontSee('Unpublished freelancer private draft')
            ->assertSee(route('contracts.show', $own->public_id), false)
            ->assertViewHas('agreements', fn ($items) => $items->count() === 1);

        $this->actingAs($freelancer)->get('/account?='.$freelancer->public_id)
            ->assertOk()->assertSee('Own agreement requiring attention')
            ->assertSee('Other client private agreement')
            ->assertDontSee('Unpublished freelancer private draft')
            ->assertViewHas('agreements', fn ($items) => $items->count() === 2);
        $this->actingAs($otherClient)->get('/contracts/'.$own->public_id)->assertNotFound();
    }

    public function test_developer_token_filters_are_real_owner_scoped_and_do_not_expose_secrets(): void
    {
        $owner = $this->account('super_admin');
        $other = $this->account('super_admin');
        [$active, $activeSecret] = ApiToken::issue($owner, 'Active integration', ['account.read'], 30);
        [$soon] = ApiToken::issue($owner, 'Expiring integration', ['listings.read'], 7);
        [$expired] = ApiToken::issue($owner, 'Expired integration', ['account.read'], 7);
        $expired->update(['expires_at' => now()->subMinute()]);
        [$revoked] = ApiToken::issue($owner, 'Revoked integration', ['account.read'], 30);
        $revoked->update(['revoked_at' => now()]);
        ApiToken::issue($other, 'Another owner private integration', ['account.read'], 30);

        $this->actingAs($owner)->get('/developer')
            ->assertOk()->assertSee('Your API credential health')
            ->assertDontSee($activeSecret)
            ->assertDontSee('Another owner private integration')
            ->assertViewHas('tokenStats', fn (array $stats) =>
                $stats['active'] === 2 && $stats['expiring'] === 1
                && $stats['expired'] === 1 && $stats['revoked'] === 1);
        $cacheControl = $this->get('/developer')->headers->get('Cache-Control');
        $this->assertStringContainsString('private', (string) $cacheControl);
        $this->assertStringContainsString('no-store', (string) $cacheControl);
        $this->get('/developer?status=active')->assertOk()
            ->assertSee('Active integration')->assertSee('Expiring integration')
            ->assertDontSee('Expired integration')->assertDontSee('Revoked integration')
            ->assertViewHas('tokens', fn ($page) => $page->total() === 2);
        $this->get('/developer?status=expiring')->assertOk()
            ->assertSee('Expiring integration')->assertDontSee('Active integration')
            ->assertViewHas('tokens', fn ($page) => $page->total() === 1);
        $this->get('/developer?status=expired')->assertOk()
            ->assertSee('Expired integration')->assertDontSee('Revoked integration')
            ->assertViewHas('tokens', fn ($page) => $page->total() === 1);
        $this->get('/developer?status=revoked')->assertOk()
            ->assertSee('Revoked integration')->assertDontSee('Expired integration')
            ->assertViewHas('tokens', fn ($page) => $page->total() === 1);
        $this->get('/developer?status=invalid')->assertNotFound();
        $this->actingAs($this->account('admin'))->get('/developer')->assertForbidden();
        $this->actingAs($other)->get('/developer')->assertOk()
            ->assertDontSee('Active integration')->assertSee('Another owner private integration');
    }
}
