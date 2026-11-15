<?php

namespace Tests\Feature;

use App\Models\MarketplaceCategory;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\SavedListing;
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

    public function test_published_layout_test_listing_can_transfer_with_its_owned_media(): void
    {
        $admin = $this->member('super_admin');
        $recipient = $this->member('freelancer');
        Storage::fake('local');
        $asset = $this->asset($admin);
        $listing = $this->record('freelance', $admin, $asset);
        $listing->update(['title' => '[LAYOUT TEST] Freelance example', 'status' => 'published']);
        $originalUrl = $listing->publicUrl();
        $url = route('dashboard.marketplace.transfer', ['user' => $admin, 'type' => 'freelance', 'id' => $listing->id]);
        $this->actingAs($admin)->get($url)->assertOk()->assertSee($listing->title);

        $this->post(route('dashboard.marketplace.transfer.store', $admin), $this->transferData($listing, $recipient->email))
            ->assertRedirect(MarketplaceManagement::indexUrl('freelance'));

        $this->assertSame($recipient->id, $listing->fresh()->user_id);
        $this->assertSame($recipient->id, $asset->fresh()->user_id);
        $this->assertSame('published', $listing->fresh()->status);
        $this->assertSame($originalUrl, $listing->fresh()->publicUrl());
        $this->assertDatabaseHas('user_ownership_transfers', [
            'resource_id' => $listing->id, 'from_user_id' => $admin->id, 'to_user_id' => $recipient->id,
        ]);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $admin->id, 'event' => 'user.resource.transferred']);
    }

    public function test_conflict_returns_to_the_selected_listing_and_preserves_input(): void
    {
        $admin = $this->member('super_admin');
        $owner = $this->member('freelancer');
        $recipient = $this->member('freelancer');
        $listing = $this->record('project', $owner);
        SavedListing::query()->create([
            'user_id' => $this->member('customer')->id, 'marketplace_listing_id' => $listing->id,
        ]);
        $params = ['user' => $owner, 'type' => 'project', 'id' => $listing->id];
        $form = route('dashboard.marketplace.transfer', $params);
        $post = route('dashboard.marketplace.transfer.store', $owner);
        $data = $this->transferData($listing, $recipient->public_id);

        $this->actingAs($admin)->get(route('localized.en.dashboard.marketplace.transfer', $params))
            ->assertOk()->assertSee('action="'.$post.'"', false);
        $this->get(route('dashboard.marketplace.transfer', $owner))
            ->assertRedirect(MarketplaceManagement::indexUrl('freelance'));
        $this->post($post, [...$data, 'recipient' => $owner->public_id])
            ->assertRedirect($form)->assertSessionHasErrors(['recipient' => 'The new owner must be a different user.']);
        $this->followingRedirects()->post($post, $data)->assertOk()
            ->assertSee('This record has customer activity')
            ->assertSee($recipient->public_id)->assertSee($data['reason']);
        $this->postJson($post, $data)->assertConflict();
        $this->assertSame($owner->id, $listing->fresh()->user_id);
        $this->assertDatabaseCount('user_ownership_transfers', 0);
    }

    public function test_shared_media_stays_with_its_owner_when_a_transfer_is_blocked(): void
    {
        $admin = $this->member('super_admin');
        $owner = $this->member('freelancer');
        $recipient = $this->member('freelancer');
        Storage::fake('local');
        $asset = $this->asset($owner);
        $listing = $this->record('service', $owner, $asset);
        $this->record('project', $owner, $asset);
        $form = route('dashboard.marketplace.transfer', ['user' => $owner, 'type' => 'service', 'id' => $listing->id]);

        $this->actingAs($admin)->post(route('dashboard.marketplace.transfer.store', $owner), $this->transferData($listing, $recipient->public_id))
            ->assertRedirect($form)
            ->assertSessionHasErrors(['transfer' => 'This media is also used by another record and cannot be reassigned automatically.']);
        $this->assertSame($owner->id, $listing->fresh()->user_id);
        $this->assertSame($owner->id, $asset->fresh()->user_id);
        $this->assertDatabaseCount('user_ownership_transfers', 0);
    }

    private function transferData(MarketplaceListing $listing, string $recipient): array
    {
        return [
            'type' => $listing->type, 'id' => $listing->id, 'recipient' => $recipient,
            'reason' => 'Authorized ownership reassignment requested by the creator.',
            'rights_confirmed' => '1', 'confirmation' => 'TRANSFER',
        ];
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
