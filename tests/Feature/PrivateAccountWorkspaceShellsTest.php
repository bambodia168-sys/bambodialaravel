<?php

namespace Tests\Feature;

use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\ServiceInquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivateAccountWorkspaceShellsTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        return User::query()->create([
            'name' => 'Workspace Shell '.ucfirst($role),
            'email' => uniqid('workspace-shell-', true).'@example.test',
            'password' => 'AReallyStrongPassword123!',
            'role' => $role,
        ]);
    }

    private function job(User $client): MarketplaceJob
    {
        return MarketplaceJob::query()->create([
            'client_id' => $client->id,
            'title' => 'Original professional web project',
            'description' => str_repeat('A clear project brief with real development requirements. ', 3),
            'category' => 'development',
            'status' => 'published',
        ]);
    }

    private function contract(User $client, User $creator): FreelanceContract
    {
        $job = $this->job($client);
        $proposal = JobProposal::query()->create([
            'marketplace_job_id' => $job->id,
            'freelancer_id' => $creator->id,
            'cover_message' => str_repeat('Professional proposal covering milestones. ', 2),
            'proposed_budget' => '100.00',
            'status' => 'accepted',
        ]);

        return FreelanceContract::query()->create([
            'job_proposal_id' => $proposal->id,
            'client_id' => $client->id,
            'freelancer_id' => $creator->id,
            'title' => 'Private freelance services agreement',
            'scope' => str_repeat('Agreed scope and deliverables. ', 4),
            'terms' => str_repeat('Commercial terms and review process. ', 4),
            'agreed_budget' => '100.00',
            'status' => 'active',
        ]);
    }

    private function inquiry(User $client, User $creator): ServiceInquiry
    {
        $listing = MarketplaceListing::query()->create([
            'user_id' => $creator->id,
            'type' => 'service',
            'title' => 'Professional consulting service',
            'status' => 'published',
            'description' => str_repeat('Real professional support and consulting delivery. ', 3),
        ]);

        return ServiceInquiry::query()->create([
            'marketplace_listing_id' => $listing->id,
            'client_id' => $client->id,
            'creator_id' => $creator->id,
            'subject' => 'Confidential project requirements discussion',
            'status' => 'open',
        ]);
    }

    public function test_all_six_account_roles_see_their_private_pages_inside_the_correct_workspace(): void
    {
        $paths = [
            'customer' => [
                '/contracts' => 'account',
                '/account/inquiries' => 'account',
                '/my-jobs' => 'account',
                '/account/media-library' => 'account',
            ],
            'subscriber' => [
                '/contracts' => 'account',
                '/account/inquiries' => 'account',
                '/my-jobs' => 'account',
                '/account/media-library' => 'account',
            ],
            'freelancer' => [
                '/contracts' => 'creator',
                '/studio/inquiries' => 'creator',
                '/my-proposals' => 'creator',
                '/studio/media-library' => 'creator',
            ],
            'staff' => [
                '/contracts' => 'admin',
            ],
            'admin' => [
                '/contracts' => 'admin',
                '/dashboard/media-library' => 'admin',
            ],
            'super_admin' => [
                '/contracts' => 'admin',
                '/my-jobs' => 'account',
                '/studio/inquiries' => 'creator',
                '/studio/media-library' => 'creator',
                '/dashboard/media-library' => 'admin',
            ],
        ];

        foreach ($paths as $role => $routes) {
            $user = $this->account($role);
            foreach ($routes as $uri => $workspace) {
                $this->actingAs($user)->get($uri)
                    ->assertOk()
                    ->assertSee('data-workspace="'.$workspace.'"', false)
                    ->assertSee('workspace-sidebar', false)
                    ->assertDontSee('class="site-header"', false);
            }
        }
    }

    public function test_private_contract_details_use_the_participant_workspace_and_block_other_users(): void
    {
        $client = $this->account('customer');
        $freelancer = $this->account('freelancer');
        $admin = $this->account('admin');
        $contract = $this->contract($client, $freelancer);
        $url = '/contracts/'.$contract->public_id;

        $this->actingAs($client)->get($url)->assertOk()
            ->assertSee('data-workspace="account"', false);
        $this->actingAs($freelancer)->get($url)->assertOk()
            ->assertSee('data-workspace="creator"', false);
        $this->actingAs($admin)->get($url)->assertNotFound();
        $this->actingAs($client)->get('/en/contracts/'.$contract->public_id)->assertOk()
            ->assertSee('data-workspace="account"', false);
    }

    public function test_private_inquiry_detail_chooses_client_or_creator_workspace_from_participation(): void
    {
        $client = $this->account('subscriber');
        $freelancer = $this->account('freelancer');
        $stranger = $this->account('customer');
        $inquiry = $this->inquiry($client, $freelancer);
        $url = '/inquiries/'.$inquiry->id;

        $this->actingAs($client)->get($url)->assertOk()
            ->assertSee('data-workspace="account"', false);
        $this->actingAs($freelancer)->get($url)->assertOk()
            ->assertSee('data-workspace="creator"', false);
        $this->actingAs($stranger)->get($url)->assertNotFound();

        $superCreator = $this->account('super_admin');
        $superInquiry = $this->inquiry($client, $superCreator);
        $this->actingAs($superCreator)->get('/inquiries/'.$superInquiry->id)->assertOk()
            ->assertSee('data-workspace="creator"', false);
        $this->get('/en/inquiries/'.$superInquiry->id)->assertOk()
            ->assertSee('data-workspace="creator"', false);
    }

    public function test_job_posting_proposals_keep_client_workspace_and_freelancer_submissions_keep_creator_workspace(): void
    {
        $customer = $this->account('customer');
        $freelancer = $this->account('freelancer');
        $superAdmin = $this->account('super_admin');
        $job = $this->job($customer);

        $this->actingAs($customer)->get('/my-jobs/'.$job->id.'/proposals')
            ->assertOk()->assertSee('data-workspace="account"', false);
        $this->actingAs($freelancer)->get('/my-proposals')
            ->assertOk()->assertSee('data-workspace="creator"', false);
        $this->get('/my-jobs')->assertForbidden();
        $this->get('/my-jobs/'.$job->id.'/proposals')->assertNotFound();

        $superJob = $this->job($superAdmin);
        $this->actingAs($superAdmin)->get('/my-jobs/'.$superJob->id.'/proposals')
            ->assertOk()->assertSee('data-workspace="account"', false);
    }

    public function test_super_admin_creator_studio_contract_links_preserve_creator_shell_without_expanding_permissions(): void
    {
        $admin = $this->account('super_admin');
        $client = $this->account('customer');
        $contract = $this->contract($client, $admin);

        $this->actingAs($admin)->get('/creator')->assertOk()
            ->assertSee(route('contracts.index', ['workspace' => 'creator']), false);
        $this->get('/contracts?workspace=creator')->assertOk()
            ->assertSee('data-workspace="creator"', false)
            ->assertSee((string) $contract->public_id)
            ->assertSee('name="workspace" value="creator"', false);
        $this->get('/contracts?workspace=creator&status=active')->assertOk()
            ->assertSee('data-workspace="creator"', false);
        $this->get('/contracts/'.$contract->public_id)->assertOk()
            ->assertSee('data-workspace="creator"', false)
            ->assertSee(route('contracts.index', ['workspace' => 'creator']), false);

        $this->get('/contracts')->assertOk()->assertSee('data-workspace="account"', false);
        $this->get('/en/contracts?workspace=creator')->assertOk()
            ->assertSee('data-workspace="creator"', false);

        $customer = $this->account('subscriber');
        $this->actingAs($customer)->get('/contracts?workspace=creator')->assertOk()
            ->assertSee('data-workspace="account"', false);
    }

    public function test_public_pages_remain_public_and_private_pages_require_authentication(): void
    {
        auth()->logout();
        foreach ([
            '/contracts', '/account/inquiries', '/studio/inquiries',
            '/my-jobs', '/my-proposals', '/studio/media-library',
            '/account/media-library',
        ] as $path) {
            $this->get($path)->assertRedirect('/login');
        }

        $this->get('/jobs')->assertOk()->assertSee('class="site-header"', false);
        $this->get('/support-center')->assertOk()->assertSee('class="site-header"', false);
    }
}
