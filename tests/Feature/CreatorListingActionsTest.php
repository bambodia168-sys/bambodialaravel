<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\ProductEntitlement;
use App\Models\ProductFileVersion;
use App\Models\ServiceInquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorListingActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_submissions_open_the_correct_owned_builder(): void
    {
        $owner = User::factory()->create(['role' => 'freelancer']);
        $product = $this->listing($owner, 'product', 'Creator dashboard product');
        $service = $this->listing($owner, 'service', 'Creator dashboard service');
        $other = $this->listing(User::factory()->create(['role' => 'freelancer']));

        $this->actingAs($owner)->get('/creator')->assertOk()
            ->assertSee('href="'.$product->builderUrl().'"', false)
            ->assertSee('href="'.$service->builderUrl().'"', false)
            ->assertDontSee('href="'.url('/builder?=').'"', false)
            ->assertDontSee($other->title);
        $this->get($product->builderUrl())->assertOk()->assertSee($product->title);
        $this->get($service->builderUrl())->assertOk()->assertSee($service->title);
        $this->get($other->builderUrl())->assertNotFound();
    }

    public function test_creator_can_archive_draft_and_delete_their_published_listing(): void
    {
        $owner = User::factory()->create(['role' => 'freelancer']);
        $listing = $this->listing($owner, 'service');
        $inquiry = ServiceInquiry::query()->create([
            'marketplace_listing_id' => $listing->id,
            'client_id' => User::factory()->create(['role' => 'customer'])->id,
            'creator_id' => $owner->id,
            'subject' => 'Existing client inquiry',
        ]);
        $index = route('studio.listings');
        $archive = route('studio.listings', ['status' => 'archived']);
        $statusUrl = route('studio.listings.status', $listing);

        $this->actingAs($owner)->get($index)->assertOk()
            ->assertSee($listing->builderUrl())->assertSee($listing->publicUrl())
            ->assertSee('row-action-menu-account-listing-'.$listing->id)
            ->assertSee('listing-'.$listing->id.'-archived')
            ->assertSee('listing-'.$listing->id.'-draft')
            ->assertSee('listing-'.$listing->id.'-deleted');
        $this->get($listing->publicUrl())->assertOk();
        $this->patch($statusUrl, ['status' => 'archived'])->assertRedirect($archive);
        $this->patch($statusUrl, ['status' => 'archived'])->assertRedirect($archive);
        $this->get($listing->publicUrl())->assertNotFound();
        $this->get($index)->assertOk()->assertDontSee($listing->title);
        $this->get($archive)->assertOk()->assertSee($listing->title)
            ->assertDontSee($listing->publicUrl());
        $this->get($listing->builderUrl())->assertOk()->assertSee($listing->title);

        $this->patch($statusUrl, ['status' => 'draft'])->assertRedirect($listing->builderUrl());
        $this->get($listing->builderUrl())->assertOk()->assertSee('data-builder-readonly="0"', false);
        $this->get($archive)->assertDontSee($listing->title);

        $this->delete(route('studio.listings.destroy', $listing))->assertRedirect($index);
        $this->assertSame('deleted', $listing->fresh()->status);
        $this->assertSame($listing->id, $inquiry->fresh()->listing->id);
        $this->get($index)->assertOk()->assertDontSee($listing->title);
        $this->get('/creator')->assertOk()->assertDontSee($listing->title);
        $this->get($listing->builderUrl())->assertNotFound();
        $this->patch($statusUrl, ['status' => 'draft'])->assertNotFound();
        $this->assertDatabaseCount('audit_events', 3);
    }

    public function test_mutations_require_ownership_and_respect_moderation(): void
    {
        $owner = User::factory()->create(['role' => 'freelancer']);
        $listing = $this->listing($owner);
        $statusUrl = route('studio.listings.status', $listing);
        $deleteUrl = route('studio.listings.destroy', $listing);

        $this->actingAs(User::factory()->create(['role' => 'freelancer']));
        $this->patch($statusUrl, ['status' => 'archived'])->assertNotFound();
        $this->delete($deleteUrl)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'customer']));
        $this->patch($statusUrl, ['status' => 'archived'])->assertForbidden();
        $this->actingAs($owner)->patchJson($statusUrl, ['status' => 'published'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $listing->update(['status' => 'withdrawn']);
        $this->patch($statusUrl, ['status' => 'draft'])->assertConflict();
        $this->delete($deleteUrl)->assertConflict();
    }

    public function test_creator_actions_preserve_customer_access_to_digital_products(): void
    {
        $owner = User::factory()->create(['role' => 'freelancer']);
        $product = $this->listing($owner, 'product');
        $version = ProductFileVersion::query()->create([
            'marketplace_listing_id' => $product->id,
            'uploaded_by' => $owner->id,
            'version' => '1.0.0',
            'release_notes' => 'Original release notes.',
            'original_filename' => 'package.zip',
            'storage_path' => 'test/package.zip',
            'file_type' => 'zip',
            'byte_size' => 50,
            'sha256' => str_repeat('a', 64),
            'rights_attested_at' => now(),
        ]);
        $entitlement = ProductEntitlement::query()->create([
            'user_id' => User::factory()->create(['role' => 'customer'])->id,
            'product_file_version_id' => $version->id,
            'granted_by' => User::factory()->create(['role' => 'super_admin'])->id,
            'grant_reason' => 'Isolated fulfillment fixture',
            'expires_at' => now()->addWeek(),
        ]);

        $this->actingAs($owner)->patchJson(route('studio.listings.status', $product), ['status' => 'archived'])
            ->assertUnprocessable()->assertJsonValidationErrors('listing');
        $this->patchJson(route('studio.listings.status', $product), ['status' => 'draft'])
            ->assertUnprocessable()->assertJsonValidationErrors('listing');
        $this->deleteJson(route('studio.listings.destroy', $product))
            ->assertUnprocessable()->assertJsonValidationErrors('listing');
        $this->assertSame('published', $product->fresh()->status);
        $entitlement->update(['expires_at' => now()->subDay()]);
        $this->delete(route('studio.listings.destroy', $product))->assertRedirect(route('studio.listings'));
        $this->get(route('studio.products.index'))->assertOk()->assertDontSee($product->title);
    }

    private function listing(User $owner, string $type = 'freelance', string $title = 'Creator listing under test'): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id' => $owner->id,
            'type' => $type,
            'title' => $title,
            'description' => str_repeat('Independent marketplace listing for Creator Studio checks. ', 2),
            'status' => 'published',
        ]);
    }
}
