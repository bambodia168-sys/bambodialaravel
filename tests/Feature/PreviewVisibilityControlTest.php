<?php

namespace Tests\Feature;

use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\SavedListing;
use App\Models\PreviewVisibilityAction;
use App\Models\User;
use App\Support\PreviewContentReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PreviewVisibilityControlTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::query()->create([
            'name' => 'Isolated Visibility '.$role,
            'email' => uniqid('visibility-', true).'@example.test',
            'password' => 'SyntheticOnlyNotForRealAccounts123!',
            'role' => $role,
        ]);
    }

    private function listing(User $owner): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id' => $owner->id,
            'type' => 'service',
            'title' => '[LAYOUT TEST] Synthetic service display',
            'description' => 'Synthetic service data for isolated acceptance and no real customers.',
            'status' => 'published',
        ]);
    }

    private function job(User $owner): MarketplaceJob
    {
        return MarketplaceJob::query()->create([
            'client_id' => $owner->id,
            'title' => '[LAYOUT TEST] Synthetic private job',
            'description' => 'Synthetic job data, not real recruitment.',
            'category' => 'development',
            'status' => 'published',
        ]);
    }

    private function form(string $kind, MarketplaceListing|MarketplaceJob $record): array
    {
        return [
            'confirm_public_id' => (string) $record->public_id,
            'expected_fingerprint' => PreviewContentReview::record($kind, (int) $record->id)['review_fingerprint'],
            'owner_confirmation_reference' => 'QA-OWNER-CONFIRMED-2026',
            'backup_reference' => 'QA-MYSQL-SNAPSHOT-VERIFIED-2026',
            'rights_review_reference' => null,
            'reason' => 'Authorized synthetic review decision with intact database history.',
            'acknowledge' => '1',
        ];
    }

    public function test_no_changes_possible_when_feature_switch_is_off(): void
    {
        $super = $this->user('super_admin');
        $owner = $this->user('freelancer');
        $item = $this->listing($owner);

        $this->actingAs($super)->get(route('dashboard.preview-visibility.index'))
            ->assertOk()->assertSee('Changes locked')->assertSee($item->public_id);

        $data = $this->form('listing', $item);
        $this->patch(route('dashboard.preview-visibility.withdraw', [
            'kind' => 'listing', 'publicId' => $item->public_id,
        ]), $data)->assertForbidden();

        $this->assertSame('published', $item->fresh()->status);
        $this->assertDatabaseCount('preview_visibility_actions', 0);
    }

    public function test_super_admin_can_withdraw_one_clean_listing_and_restore_to_pending_only(): void
    {
        config()->set('preview_visibility.enabled', true);
        $super = $this->user('super_admin');
        $owner = $this->user('freelancer');
        $item = $this->listing($owner);
        $this->actingAs($super);

        $data = $this->form('listing', $item);
        $this->patch(route('dashboard.preview-visibility.withdraw', [
            'kind'=>'listing','publicId'=>$item->public_id,
        ]), $data)->assertRedirect();

        $this->assertSame('withdrawn', $item->fresh()->status);
        $this->assertFalse(MarketplaceListing::query()->publiclyAvailable()
            ->whereKey($item->id)->exists());
        $this->assertDatabaseHas('preview_visibility_actions', [
            'subject_kind'=>'listing','subject_id'=>$item->id,
            'actor_id'=>$super->id,'from_status'=>'published','to_status'=>'withdrawn',
            'action'=>'withdraw',
        ]);
        $this->assertDatabaseCount('marketplace_listings', 1);
        $this->assertDatabaseCount('account_notifications', 1);

        $this->patch(route('dashboard.preview-visibility.withdraw', [
            'kind'=>'listing','publicId'=>$item->public_id,
        ]), $data)->assertStatus(409);

        $restore = $this->form('listing', $item->fresh());
        $this->patch(route('dashboard.preview-visibility.restore', [
            'kind'=>'listing','publicId'=>$item->public_id,
        ]), $restore)->assertRedirect();

        $this->assertSame('pending', $item->fresh()->status);
        $this->assertFalse(MarketplaceListing::query()->publiclyAvailable()
            ->whereKey($item->id)->exists());
        $this->assertDatabaseHas('preview_visibility_actions', [
            'subject_kind'=>'listing','subject_id'=>$item->id,
            'action'=>'restore','from_status'=>'withdrawn','to_status'=>'pending',
        ]);
        $this->assertDatabaseCount('preview_visibility_actions', 2);
        $this->assertDatabaseCount('account_notifications', 2);
        $this->get(route('dashboard.reviews'))->assertOk()->assertSee($item->title);
    }

    public function test_only_super_admin_can_see_or_execute_withdrawal(): void
    {
        config()->set('preview_visibility.enabled', true);
        $owner = $this->user('freelancer');
        $item = $this->listing($owner);
        $data = $this->form('listing', $item);
        $url = route('dashboard.preview-visibility.withdraw', [
            'kind'=>'listing','publicId'=>$item->public_id,
        ]);

        $this->get(route('dashboard.preview-visibility.index'))->assertRedirect(route('login'));
        foreach (['admin','staff','freelancer','customer'] as $role) {
            $actor = $this->user($role);
            $this->actingAs($actor)->get(route('dashboard.preview-visibility.index'))->assertForbidden();
            $this->patch($url, $data)->assertForbidden();
        }

        $this->assertDatabaseCount('preview_visibility_actions', 0);
        $this->assertSame('published', $item->fresh()->status);
    }

    public function test_customer_saved_item_is_immutable_and_blocks_withdrawal(): void
    {
        config()->set('preview_visibility.enabled', true);
        $super = $this->user('super_admin');
        $owner = $this->user('freelancer');
        $customer = $this->user('customer');
        $item = $this->listing($owner);
        SavedListing::query()->create([
            'user_id'=>$customer->id,'marketplace_listing_id'=>$item->id,
        ]);

        $this->actingAs($super)->patch(
            route('dashboard.preview-visibility.withdraw', [
                'kind'=>'listing','publicId'=>$item->public_id,
            ]),
            $this->form('listing', $item)
        )->assertStatus(409);

        $this->assertSame('published', $item->fresh()->status);
        $this->assertDatabaseCount('saved_listings', 1);
        $this->assertDatabaseCount('preview_visibility_actions', 0);
        $this->assertDatabaseCount('account_notifications', 0);
    }

    public function test_stale_fingerprint_and_wrong_public_id_are_refused(): void
    {
        config()->set('preview_visibility.enabled', true);
        $super = $this->user('super_admin');
        $owner = $this->user('freelancer');
        $item = $this->listing($owner);
        $form = $this->form('listing', $item);
        $url = route('dashboard.preview-visibility.withdraw', [
            'kind'=>'listing','publicId'=>$item->public_id,
        ]);

        $this->actingAs($super)->patch($url, [
            ...$form, 'confirm_public_id' => '111222333',
        ])->assertStatus(422);
        $item->update(['description'=>'Changed between review and moderation.']);
        $this->patch($url, $form)->assertStatus(409);

        $this->assertDatabaseCount('preview_visibility_actions', 0);
        $this->assertSame('published', $item->fresh()->status);
    }

    public function test_media_requires_rights_attestation_and_preserves_references(): void
    {
        config()->set('preview_visibility.enabled', true);
        $super = $this->user('super_admin');
        $owner = $this->user('freelancer');
        $item = $this->listing($owner);
        $item->gallery_media_ids = ['123456789'];
        $item->save();
        $this->actingAs($super);
        $url = route('dashboard.preview-visibility.withdraw', [
            'kind'=>'listing','publicId'=>$item->public_id,
        ]);
        $form = $this->form('listing', $item);
        $this->patch($url, $form)->assertStatus(409);
        $this->assertSame('published', $item->fresh()->status);

        $form['rights_review_reference'] = 'QA-MEDIA-RIGHTS-REVIEW-2026';
        $this->patch($url, $form)->assertRedirect();
        $this->assertSame('withdrawn', $item->fresh()->status);
        $this->assertSame(['123456789'], $item->fresh()->gallery_media_ids);
        $this->assertDatabaseHas('preview_visibility_actions', [
            'subject_kind'=>'listing','subject_id'=>$item->id,
            'rights_review_reference'=>'QA-MEDIA-RIGHTS-REVIEW-2026',
        ]);
    }

    public function test_published_job_can_be_withdrawn_without_deleting_job_and_restored_to_pending(): void
    {
        config()->set('preview_visibility.enabled', true);
        $super = $this->user('super_admin');
        $client = $this->user('customer');
        $item = $this->job($client);
        $this->actingAs($super);

        $this->get('/jobs')->assertOk()->assertSee($item->title);
        $this->patch(route('dashboard.preview-visibility.withdraw', [
            'kind'=>'job','publicId'=>$item->public_id,
        ]), $this->form('job', $item))->assertRedirect();

        $this->assertSame('withdrawn', $item->fresh()->status);
        $this->get('/jobs')->assertOk()->assertDontSee($item->title);
        $this->assertDatabaseCount('marketplace_jobs', 1);

        $this->patch(route('dashboard.preview-visibility.restore', [
            'kind'=>'job','publicId'=>$item->public_id,
        ]), $this->form('job', $item->fresh()))->assertRedirect();

        $this->assertSame('pending', $item->fresh()->status);
        $this->get('/jobs')->assertOk()->assertDontSee($item->title);
        $this->assertDatabaseCount('preview_visibility_actions', 2);
        $this->get(route('dashboard.jobs.index', ['status'=>'pending']))
            ->assertOk()->assertSee($item->title);
    }

    public function test_confirmation_is_required_and_has_no_side_effects(): void
    {
        config()->set('preview_visibility.enabled', true);
        $super = $this->user('super_admin');
        $owner = $this->user('freelancer');
        $item = $this->listing($owner);
        $form = $this->form('listing', $item);
        unset($form['acknowledge']);

        $this->actingAs($super)->patch(route('dashboard.preview-visibility.withdraw', [
            'kind'=>'listing','publicId'=>$item->public_id,
        ]), $form)->assertSessionHasErrors('acknowledge');

        $this->assertSame('published', $item->fresh()->status);
        $this->assertDatabaseCount('preview_visibility_actions', 0);
    }
    public function test_visibility_action_history_cannot_be_edited_or_deleted_through_models(): void
    {
        $super = $this->user('super_admin');
        $owner = $this->user('freelancer');
        $listing = $this->listing($owner);
        $entry = PreviewVisibilityAction::query()->create([
            'subject_kind' => 'listing',
            'subject_id' => $listing->id,
            'subject_public_id' => $listing->public_id,
            'actor_id' => $super->id,
            'action' => 'withdraw',
            'from_status' => 'published',
            'to_status' => 'withdrawn',
            'review_fingerprint' => str_repeat('a', 64),
            'owner_confirmation_reference' => 'QA-OWNER-APPROVED-2026',
            'backup_reference' => 'QA-BACKUP-MANIFEST-2026',
            'reason' => 'An independent test decision requiring immutable records.',
        ]);

        try {
            $entry->update(['reason' => 'Tampered audit history.']);
            $this->fail('Audit history update should be rejected.');
        } catch (\LogicException $e) {
            $this->assertSame('Preview visibility history is immutable.', $e->getMessage());
        }

        try {
            $entry->fresh()->delete();
            $this->fail('Audit history deletion should be rejected.');
        } catch (\LogicException $e) {
            $this->assertSame('Preview visibility history cannot be deleted.', $e->getMessage());
        }

        $this->assertDatabaseHas('preview_visibility_actions', [
            'id' => $entry->id,
            'reason' => 'An independent test decision requiring immutable records.',
            'actor_id' => $super->id,
        ]);
        $this->assertDatabaseCount('preview_visibility_actions', 1);
    }


    public function test_new_customer_activity_after_preview_invalidates_approval_snapshot(): void
    {
        config()->set('preview_visibility.enabled', true);
        $super = $this->user('super_admin');
        $owner = $this->user('freelancer');
        $customer = $this->user('customer');
        $listing = $this->listing($owner);

        // The operator reviewed the item before a real customer saved it.
        $oldForm = $this->form('listing', $listing);
        SavedListing::query()->create([
            'marketplace_listing_id' => $listing->id, 'user_id' => $customer->id,
        ]);

        $this->actingAs($super)->patch(route('dashboard.preview-visibility.withdraw', [
            'kind' => 'listing', 'publicId' => $listing->public_id,
        ]), $oldForm)->assertStatus(409);

        $this->assertSame('published', $listing->fresh()->status);
        $this->assertDatabaseCount('saved_listings', 1);
        $this->assertDatabaseCount('preview_visibility_actions', 0);
    }

    public function test_job_proposal_blocks_withdrawal_even_when_report_is_current(): void
    {
        config()->set('preview_visibility.enabled', true);
        $super = $this->user('super_admin');
        $client = $this->user('customer');
        $freelancer = $this->user('freelancer');
        $job = $this->job($client);
        \App\Models\JobProposal::query()->create([
            'marketplace_job_id' => $job->id,
            'freelancer_id' => $freelancer->id,
            'cover_message' => 'A legitimate candidate response in an isolated QA fixture.',
            'status' => 'submitted',
        ]);

        $this->actingAs($super)->patch(route('dashboard.preview-visibility.withdraw', [
            'kind' => 'job', 'publicId' => $job->public_id,
        ]), $this->form('job', $job))->assertStatus(409);

        $this->assertSame('published', $job->fresh()->status);
        $this->assertDatabaseCount('job_proposals', 1);
        $this->assertDatabaseCount('preview_visibility_actions', 0);
    }
}
