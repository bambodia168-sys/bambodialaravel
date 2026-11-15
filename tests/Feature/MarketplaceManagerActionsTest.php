<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategory;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\MarketplaceManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketplaceManagerActionsTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRIPTION = 'A detailed listing with clear requirements, deliverables, and enough information for review and publication.';

    public function test_five_managers_link_to_existing_builder_and_scope_media_and_filters(): void
    {
        $admin = $this->member('admin');
        $owner = $this->member('super_admin');
        Storage::fake('local');
        $cover = $this->asset($owner);
        $unrelated = $this->asset($owner);
        $this->actingAs($admin);

        foreach (['project', 'freelance', 'service', 'product', 'job'] as $type) {
            $record = $this->record($type, $owner, $cover);
            $differentOwner = $this->member('super_admin');
            $other = $this->record($type, $differentOwner);
            $preview = MarketplaceManagement::builderUrl($record, true);
            $edit = MarketplaceManagement::builderUrl($record);
            $media = $this->mediaUrl($record, $cover);

            $this->get(MarketplaceManagement::indexUrl($type))->assertOk()
                ->assertSee($record->title)->assertSee($preview)->assertSee($edit)
                ->assertSee($media)->assertSee('Configure')->assertSee('In Review');
            $this->get(MarketplaceManagement::indexUrl($type, ['owner' => $owner->id]))
                ->assertOk()->assertSee($record->title)->assertDontSee($other->title);
            $category = $record instanceof MarketplaceJob ? $record->category : $record->category_id;
            $this->get(MarketplaceManagement::indexUrl($type, ['category' => $category]))
                ->assertOk()->assertSee($record->title);
            $this->get($preview)->assertOk()->assertSee('Read-only preview')->assertSee($record->title)
                ->assertDontSee('name="accept_terms"', false)->assertDontSee($unrelated->alt_text);
            $this->get($edit)->assertOk()->assertSee('Save '.($type === 'product' ? 'product' : 'changes'));
            $this->get($media)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
            $this->get($this->mediaUrl($record, $unrelated))->assertNotFound();
        }
    }

    public function test_review_transitions_validate_and_managed_edits_keep_identity_owner_and_status(): void
    {
        $admin = $this->member('super_admin');
        $owner = $this->member('freelancer');
        $record = $this->record('project', $owner);
        $params = ['type' => 'project', 'publicId' => $record->public_id];
        $status = route('dashboard.marketplace.status', $params);
        $this->actingAs($admin);

        $this->patch($status, ['expected_status' => 'draft', 'status' => 'published'])->assertConflict();
        $this->patch($status, ['expected_status' => 'draft', 'status' => 'in_review'])->assertRedirect();
        $this->patch($status, ['expected_status' => 'draft', 'status' => 'pending'])->assertConflict();

        $edit = [
            'title' => 'Updated project requirements',
            'description' => self::DESCRIPTION,
            'expected_revision' => MarketplaceManagement::revision($record->fresh()),
            'expected_owner' => $owner->id,
            'user_id' => $admin->id,
            'status' => 'published',
        ];
        $this->patch(route('builder.manage.update', $params), $edit)->assertRedirect();
        $this->assertDatabaseHas('marketplace_listings', [
            'id' => $record->id, 'user_id' => $owner->id, 'public_id' => $record->public_id,
            'title' => $edit['title'], 'status' => 'in_review',
        ]);
        $this->patch(route('builder.manage.update', $params), $edit)->assertConflict();
        foreach (['in_review' => 'pending', 'pending' => 'rejected', 'rejected' => 'in_review'] as $from => $to) {
            $this->patch($status, ['expected_status' => $from, 'status' => $to])->assertRedirect();
        }
        $this->patch($status, ['expected_status' => 'in_review', 'status' => 'published'])->assertRedirect();
        $this->patch($status, ['expected_status' => 'published', 'status' => 'draft'])->assertRedirect();
        $this->assertSame('draft', $record->fresh()->status);

        $product = $this->record('product', $owner);
        $productStatus = route('dashboard.marketplace.status', ['type' => 'product', 'publicId' => $product->public_id]);
        $this->patch($productStatus, ['expected_status' => 'draft', 'status' => 'pending'])->assertRedirect();
        $this->patchJson($productStatus, ['expected_status' => 'pending', 'status' => 'published'])
            ->assertUnprocessable()->assertJsonValidationErrors(['license_definition_id', 'cover_media_id']);
        $this->assertSame('pending', $product->fresh()->status);
    }

    public function test_only_assigned_staff_can_transfer_and_modifying_or_exposing_unrelated_records_is_denied(): void
    {
        $staff = $this->member('staff');
        $owner = $this->member('freelancer');
        $recipient = $this->member('freelancer');
        Storage::fake('local');
        $cover = $this->asset($owner);
        $record = $this->record('service', $owner, $cover);
        $unrelated = $this->member('customer');
        $transferParams = ['user' => $owner, 'type' => 'service', 'id' => $record->id];
        $url = route('dashboard.marketplace.transfer', $transferParams);
        $post = route('dashboard.marketplace.transfer.store', $owner);
        $data = [
            'type' => 'service', 'id' => $record->id, 'recipient' => $recipient->public_id,
            'reason' => 'Authorized ownership reassignment requested by the creator.',
            'rights_confirmed' => '1', 'confirmation' => 'TRANSFER',
        ];
        $this->actingAs($unrelated)->get(MarketplaceManagement::builderUrl($record, true))->assertForbidden();
        $this->get($this->mediaUrl($record, $cover))->assertForbidden();

        $this->actingAs($staff)->get($url)->assertForbidden();
        $this->post($post, $data)->assertForbidden();
        DB::table('user_permission_overrides')->insert([
            'user_id' => $staff->id, 'permission' => 'marketplace_transfer', 'allowed' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->get($url)->assertOk()->assertSee('Transfer ownership');
        $this->get(MarketplaceManagement::builderUrl($record))->assertForbidden();
        $this->get($this->mediaUrl($record, $cover))->assertOk();
        $this->patch(route('dashboard.marketplace.status', ['type'=>'service', 'publicId'=>$record->public_id]), [])->assertForbidden();
        $this->post($post, $data)->assertRedirect(MarketplaceManagement::indexUrl('service'));

        $this->assertSame($recipient->id, (int) $record->fresh()->user_id);
        $this->assertSame($recipient->id, (int) $cover->fresh()->user_id);
        $this->assertDatabaseHas('user_ownership_transfers', [
            'actor_id' => $staff->id, 'from_user_id' => $owner->id, 'to_user_id' => $recipient->id,
            'resource_type' => 'service', 'resource_public_id' => $record->public_id,
        ]);
        $this->post($post, $data)->assertNotFound();
    }

    private function member(string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role).' '.Str::random(8),
            'email' => Str::uuid().'@example.test',
            'password' => 'TestOnlyMarketplacePassword!',
            'role' => $role,
        ]);
    }

    public function test_managers_can_archive_restore_and_delete_all_marketplace_types(): void
    {
        $admin = $this->member('super_admin');
        $owner = $this->member('super_admin');
        $this->actingAs($admin);

        foreach (['product', 'project', 'job', 'freelance', 'service'] as $type) {
            $record = $this->record($type, $owner);
            $url = route('dashboard.marketplace.status', ['type' => $type, 'publicId' => $record->public_id]);
            $this->get(MarketplaceManagement::indexUrl($type))->assertOk()
                ->assertSee('View details')->assertSee('Archive')->assertSee('Delete');
            $this->patch($url, ['status' => 'archived', 'expected_status' => 'draft'])
                ->assertRedirect(MarketplaceManagement::indexUrl($type, ['status' => 'archived']));
            $this->get(MarketplaceManagement::indexUrl($type))->assertDontSee($record->title);
            $this->get(MarketplaceManagement::indexUrl($type, ['status' => 'archived']))->assertSee($record->title);
            $this->patch($url, ['status' => 'draft', 'expected_status' => 'archived', 'open_editor' => 1])
                ->assertRedirect(MarketplaceManagement::builderUrl($record));
            $this->patch($url, ['status' => 'deleted', 'expected_status' => 'draft'])
                ->assertRedirect(MarketplaceManagement::indexUrl($type, ['status' => 'deleted']));
            $this->assertSame('deleted', $record->fresh()->status);
        }
    }

    public function test_job_owner_can_archive_restore_submit_and_delete_but_another_user_cannot(): void
    {
        $owner = $this->member('customer');
        $job = $this->record('job', $owner);
        $job->update(['status' => 'published']);
        $route = route('jobs.status', $job);
        $this->actingAs($this->member('customer'))->patch($route, ['status' => 'archived', 'expected_status' => 'published'])
            ->assertNotFound();
        $this->actingAs($owner)->get(route('jobs.mine'))->assertOk()->assertSee('View published job')
            ->assertSee('Archive')->assertSee('Delete');
        $this->patch($route, ['status' => 'archived', 'expected_status' => 'published'])
            ->assertRedirect(route('jobs.mine', ['status' => 'archived']));
        $this->get($job->publicUrl())->assertNotFound();
        $this->patch($route, ['status' => 'draft', 'expected_status' => 'archived'])->assertRedirect();
        $this->patch('/builder?='.$job->public_id, [
            'title' => 'Updated owner job posting', 'description' => self::DESCRIPTION, 'category' => 'development',
            'status' => 'pending', 'accept_terms' => 1, 'terms_sha256' => \App\Support\BuilderSubmissionTerms::digest(),
        ])->assertRedirect();
        $this->assertSame('pending', $job->fresh()->status);
        $this->patch($route, ['status' => 'deleted', 'expected_status' => 'pending'])->assertRedirect(route('jobs.mine'));
        $this->assertSame('deleted', $job->fresh()->status);
        $this->get('/builder?='.$job->public_id)->assertNotFound();
    }

    public function test_product_edit_keeps_attached_media_from_another_library(): void
    {
        $admin = $this->member('super_admin');
        $creator = $this->member('freelancer');
        $other = $this->member('freelancer');
        Storage::fake('local');
        $attached = $this->asset($other);
        $unrelated = $this->asset($other);
        $product = $this->record('product', $creator, $attached);
        $product->update(['gallery_media_ids' => [$attached->id]]);
        $url = route('builder.manage.update', ['type' => 'product', 'publicId' => $product->public_id]);
        $data = [
            'expected_owner' => $creator->id, 'expected_revision' => MarketplaceManagement::revision($product->fresh()),
            'title' => 'Updated product with attached media', 'description' => self::DESCRIPTION,
            'featured_kind' => 'image', 'featured_media_id' => $attached->id,
            'cover_media_id' => $attached->id, 'gallery_media_ids' => [$attached->id],
        ];

        $this->actingAs($admin)->get(MarketplaceManagement::builderUrl($product))->assertOk()
            ->assertSee($attached->alt_text)->assertDontSee($unrelated->alt_text);
        $this->get($this->mediaUrl($product, $attached))->assertOk();
        $this->patchJson($url, [...$data, 'featured_media_id' => $unrelated->id])
            ->assertUnprocessable()->assertJsonValidationErrors('featured_media_id');
        $this->patch($url, $data)->assertRedirect(MarketplaceManagement::builderUrl($product));
        $this->assertSame($attached->id, $product->fresh()->cover_media_id);
        $this->assertSame($attached->id, $product->fresh()->featured_media_id);
        $this->assertSame([$attached->id], $product->fresh()->gallery_media_ids);

        $ownerPreview = route('media.preview', [
            'publicId' => $attached->public_id, 'listing_type' => 'product', 'listing' => $product->public_id,
        ]);
        $this->actingAs($creator)->get($ownerPreview)->assertOk();
        $this->actingAs($this->member('freelancer'))->get($ownerPreview)->assertNotFound();
    }

    private function record(string $type, User $owner, ?MediaAsset $asset = null): MarketplaceJob|MarketplaceListing
    {
        $details = [
            'title' => ucfirst($type).' manager example '.Str::random(10), 'description' => self::DESCRIPTION,
            'status' => 'draft', 'currency' => 'USD',
            'featured_kind' => $asset ? 'image' : 'none', 'featured_media_id' => $asset?->id,
        ];
        if ($type === 'job') {
            return MarketplaceJob::query()->create([...$details, 'client_id' => $owner->id, 'category' => 'development']);
        }
        $category = MarketplaceCategory::query()->create([
            'type' => $type, 'name' => 'Category '.Str::random(8), 'created_by' => $owner->id, 'is_active' => true,
        ]);

        return MarketplaceListing::query()->create([
            ...$details, 'type' => $type, 'user_id' => $owner->id, 'category_id' => $category->id,
            'cover_media_id' => $type === 'product' ? $asset?->id : null,
        ]);
    }

    private function asset(User $owner): MediaAsset
    {
        $path = 'media/'.Str::uuid().'.jpg';
        Storage::disk('local')->put($path, 'Example image bytes for storage access test.');

        return MediaAsset::query()->create([
            'user_id' => $owner->id, 'original_name' => basename($path), 'storage_path' => $path,
            'mime_type' => 'image/jpeg', 'bytes' => 44, 'width' => 1, 'height' => 1,
            'alt_text' => 'Example media '.Str::random(10), 'rights_basis' => 'owned',
            'rights_holder' => $owner->name, 'review_status' => 'pending',
        ]);
    }

    private function mediaUrl(MarketplaceJob|MarketplaceListing $record, MediaAsset $asset): string
    {
        return route('dashboard.marketplace.asset', [
            'type' => MarketplaceManagement::type($record), 'publicId' => $record->public_id, 'mediaId' => $asset->public_id,
        ]);
    }
}
