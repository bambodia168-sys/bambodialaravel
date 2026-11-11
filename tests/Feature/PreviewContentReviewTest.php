<?php

namespace Tests\Feature;

use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\SavedJob;
use App\Models\SavedListing;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Support\PreviewContentReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PreviewContentReviewTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        return User::query()->create([
            'name' => 'Synthetic QA Account',
            'email' => uniqid('preview_review_', true).'@example.test',
            'password' => 'SyntheticOnlyNotProduction2026!',
            'role' => $role,
        ]);
    }

    private function listing(User $owner, string $title, string $status = 'published'): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id' => $owner->id,
            'type' => 'service',
            'title' => $title,
            'description' => 'A synthetic publication test description.',
            'status' => $status,
        ]);
    }

    private function job(User $client, string $title, string $status = 'published'): MarketplaceJob
    {
        return MarketplaceJob::query()->create([
            'client_id' => $client->id,
            'title' => $title,
            'description' => 'A synthetic project scope for isolated review acceptance.',
            'category' => 'development',
            'status' => $status,
        ]);
    }

    public function test_only_public_marked_items_are_reported_without_changing_data(): void
    {
        $creator = $this->account('freelancer');
        $client = $this->account('customer');

        $listing = $this->listing($creator, '[LAYOUT TEST] Responsive application');
        $job = $this->job($client, '[LAYOUT TEST] Website development');
        $this->listing($creator, '[SAMPLE] Private draft only', 'draft');
        $this->job($client, '[DEMO] Pending proposal', 'pending');
        $this->listing($creator, 'Authentic published service');

        $this->assertSame(2, Artisan::call('marketplace:preview-review', [
            '--json' => true, '--strict' => true,
        ]));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('manual_review_required', $report['status']);
        $this->assertSame('review_only', $report['operation']);
        $this->assertTrue($report['read_only']);
        $this->assertSame(1, $report['counts']['public_preview_listings']);
        $this->assertSame(1, $report['counts']['published_preview_jobs']);
        $this->assertSame(0, $report['counts']['customer_activity_blocked']);
        $this->assertSame([$listing->public_id, $job->public_id], array_column($report['records'], 'public_id'));
        $this->assertSame('ownership_confirmation_required', $report['records'][0]['review_status']);
        $this->assertFalse($report['records'][0]['auto_unpublish_allowed']);
        $this->assertStringNotContainsString('Private draft only', $output);
        $this->assertStringNotContainsString('Pending proposal', $output);
        $this->assertStringNotContainsString('@example.test', $output);
        $this->assertSame('published', $listing->fresh()->status);
        $this->assertSame('published', $job->fresh()->status);
        $this->assertDatabaseCount('marketplace_listings', 3);
        $this->assertDatabaseCount('marketplace_jobs', 2);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_customer_dependencies_block_unreviewed_withdrawal_and_leave_records_untouched(): void
    {
        $creator = $this->account('freelancer');
        $client = $this->account('customer');
        $thirdParty = $this->account('freelancer');

        $listing = $this->listing($creator, '[SAMPLE] Illustration service');
        SavedListing::query()->create([
            'marketplace_listing_id' => $listing->id, 'user_id' => $client->id,
        ]);
        ServiceInquiry::query()->create([
            'marketplace_listing_id' => $listing->id,
            'client_id' => $client->id,
            'creator_id' => $creator->id,
            'subject' => 'A real customer inquiry in a synthetic test',
        ]);

        $job = $this->job($client, '[DEMO] Freelancer application');
        SavedJob::query()->create(['marketplace_job_id' => $job->id, 'user_id' => $thirdParty->id]);
        $proposal = JobProposal::query()->create([
            'marketplace_job_id' => $job->id,
            'freelancer_id' => $thirdParty->id,
            'cover_message' => 'Synthetic candidate proposal.',
            'status' => 'submitted',
        ]);
        FreelanceContract::query()->create([
            'job_proposal_id' => $proposal->id,
            'client_id' => $client->id,
            'freelancer_id' => $thirdParty->id,
            'title' => 'Synthetic project agreement',
            'scope' => 'Isolated acceptance scope.',
            'terms' => 'Synthetic only.',
            'agreed_budget' => 200,
            'status' => 'draft',
        ]);

        $before = PreviewContentReview::inspect();
        $after = PreviewContentReview::inspect();

        $this->assertSame($before, $after);
        $this->assertSame(2, $before['counts']['customer_activity_blocked']);
        $this->assertSame('customer_activity_blocked', $before['records'][0]['review_status']);
        $this->assertSame(1, $before['records'][0]['related_counts']['saved_by_accounts']);
        $this->assertSame(1, $before['records'][0]['related_counts']['service_inquiries']);
        $this->assertSame('customer_activity_blocked', $before['records'][1]['review_status']);
        $this->assertSame(1, $before['records'][1]['related_counts']['proposals']);
        $this->assertSame(1, $before['records'][1]['related_counts']['contracts']);
        $this->assertFalse($before['records'][1]['auto_unpublish_allowed']);
        $this->assertDatabaseCount('freelance_contracts', 1);
        $this->assertDatabaseCount('job_proposals', 1);
        $this->assertSame('published', $listing->fresh()->status);
        $this->assertSame('published', $job->fresh()->status);
    }

    public function test_media_references_require_manual_rights_review_without_file_access(): void
    {
        $creator = $this->account('freelancer');
        $item = $this->listing($creator, '[LAYOUT TEST] Media presentation');
        $item->gallery_media_ids = ['123456789', '123456789', '987654321'];
        $item->save();

        $report = PreviewContentReview::inspect();

        $this->assertSame(1, $report['counts']['media_review_required']);
        $this->assertSame(2, $report['records'][0]['attached_media_references']);
        $this->assertTrue($report['records'][0]['attachments_present']);
        $this->assertSame('media_or_rights_review', $report['records'][0]['review_status']);
        $this->assertFalse($report['records'][0]['auto_unpublish_allowed']);
        $this->assertSame('published', $item->fresh()->status);
    }

    public function test_clean_public_data_passes_strict_review(): void
    {
        $owner = $this->account('freelancer');
        $this->listing($owner, 'Real service with an authentic title');

        $this->assertSame(0, Artisan::call('marketplace:preview-review', [
            '--json' => true, '--strict' => true,
        ]));
        $r = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('no_preview_markers', $r['status']);
        $this->assertSame([], $r['records']);
        $this->assertSame(0, $r['counts']['public_preview_listings']);
        $this->assertSame(0, $r['counts']['published_preview_jobs']);
        $this->assertDatabaseCount('audit_events', 0);
    }
}
