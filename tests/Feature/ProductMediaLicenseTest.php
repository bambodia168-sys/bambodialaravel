<?php

namespace Tests\Feature;

use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductMediaLicenseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function member(string $role): User
    {
        return User::query()->create([
            'name' => 'Bambodia '.ucfirst($role),
            'email' => uniqid($role, true).'@example.test',
            'password' => 'VeryStrongAccountPassword123!',
            'role' => $role,
        ]);
    }

    private function makeImage(): UploadedFile
    {
        // A real single-pixel PNG; no GD dependency is required in PHP test environments.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l5cAAAAASUVORK5CYII=',
            true
        );
        return UploadedFile::fake()->createWithContent('original.png', $png);
    }

    private function createLicense(User $admin): LicenseDefinition
    {
        $this->actingAs($admin)->post('/dashboard/license-manager', [
            'code' => 'REGULAR',
            'tier' => 'regular',
            'name' => 'Regular Product License',
            'version' => '1.0.0',
            'permissions' => str_repeat('Use this digital work on one registered project. ', 2),
            'restrictions' => str_repeat('Do not resell, sublicense, or redistribute this source. ', 2),
            'status' => 'active',
            'confirm_review' => '1',
        ])->assertRedirect();

        return LicenseDefinition::query()->where('code', 'REGULAR')->firstOrFail();
    }

    private function uploadImage(User $creator): MediaAsset
    {
        $this->actingAs($creator)->post('/studio/media-library', [
            'image' => $this->makeImage(),
            'alt_text' => 'Detailed preview of the original theme design',
            'rights_basis' => 'owned',
            'rights_holder' => $creator->name,
            'rights_note' => null,
        ])->assertRedirect();

        return MediaAsset::query()->latest('id')->firstOrFail();
    }

    private function productForm(LicenseDefinition $license, MediaAsset $asset, string $status): array
    {
        return [
            'title' => 'Original Premium Web Template',
            'description' => str_repeat('A responsive digital template built for real business use. ', 2),
            'price' => '49.00',
            'license_definition_id' => $license->id,
            'cover_media_id' => $asset->id,
            'license_terms' => 'Support does not include custom development services.',
            'status' => $status,
        ];
    }

    public function test_license_manager_requires_admin_review_and_freezes_used_terms(): void
    {
        $admin = $this->member('admin');
        $creator = $this->member('freelancer');

        $this->actingAs($creator)->get('/dashboard/license-manager')->assertForbidden();

        $this->actingAs($admin)->post('/dashboard/license-manager', [
            'code' => 'REGULAR',
            'tier' => 'regular',
            'name' => 'Regular Product License',
            'version' => '1.0.0',
            'permissions' => str_repeat('Licensed for one commercial website. ', 2),
            'restrictions' => str_repeat('No resale or unauthorized redistribution. ', 2),
            'status' => 'active',
        ])->assertSessionHasErrors('status');
        $this->assertDatabaseCount('license_definitions', 0);

        $license = $this->createLicense($admin);
        $this->get($license->publicUrl())->assertOk()->assertSee('Regular Product License');

        MarketplaceListing::query()->create([
            'user_id' => $creator->id,
            'type' => 'product',
            'title' => 'Referenced License Product',
            'description' => str_repeat('This catalog item references the reviewed license. ', 2),
            'license_definition_id' => $license->id,
            'status' => 'draft',
        ]);

        $this->actingAs($admin)->patch('/dashboard/license-manager/'.$license->id, [
            'code' => 'REGULAR',
            'tier' => 'regular',
            'name' => 'Changed license rights',
            'version' => '1.0.1',
            'permissions' => str_repeat('Grant unrestricted redistribution to third parties. ', 2),
            'restrictions' => str_repeat('Different restrictions would affect existing listings. ', 2),
            'status' => 'active',
            'confirm_review' => '1',
        ])->assertStatus(409);
        $this->assertSame('1.0.0', $license->fresh()->version);
    }

    public function test_media_upload_is_real_private_file_and_rejects_svg(): void
    {
        $creator = $this->member('freelancer');
        $admin = $this->member('admin');

        $asset = $this->uploadImage($creator);
        $this->assertSame('pending', $asset->review_status);
        $this->assertSame('image/png', $asset->mime_type);
        $this->assertSame('local', Storage::getDefaultDriver());
        Storage::disk('local')->assertExists($asset->storage_path);

        $this->actingAs($admin)->get($asset->previewUrl())->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($creator)->post('/studio/media-library', [
            'image' => UploadedFile::fake()->createWithContent('unsafe.svg', '<svg onload="alert(1)"></svg>'),
            'alt_text' => 'An unsupported vector image',
            'rights_basis' => 'owned',
            'rights_holder' => $creator->name,
        ])->assertSessionHasErrors('image');
        $this->assertSame(1, MediaAsset::query()->count());
    }

    public function test_product_publication_requires_license_and_owned_preview_without_media_approval(): void
    {
        $creator = $this->member('freelancer');
        $admin = $this->member('admin');
        $license = $this->createLicense($admin);
        $asset = $this->uploadImage($creator);

        $this->post('/studio/products', $this->productForm($license, $asset, 'draft'))
            ->assertRedirect('/studio/products');
        $product = MarketplaceListing::query()->where('type', 'product')->firstOrFail();
        $this->assertSame('draft', $product->status);

        // A private, unlinked image is not publicly accessible regardless of review status.
        $this->post('/logout')->assertRedirect();
        $this->get($asset->previewUrl())->assertNotFound();

        $this->actingAs($creator)->patch('/studio/products/'.$product->id,
            $this->productForm($license, $asset, 'pending')
        )->assertRedirect();
        $this->assertSame('pending', $product->fresh()->status);
        $this->assertSame('pending', $asset->fresh()->review_status);

        $this->actingAs($admin)->patch('/dashboard/product-manager/'.$product->id, [
            'decision' => 'published',
        ])->assertRedirect();
        $this->assertSame('published', $product->fresh()->status);
        $this->assertDatabaseHas('account_notifications', [
            'user_id' => $creator->id, 'event' => 'listing.reviewed',
            'target_path' => '/studio/products',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id, 'event' => 'listing.review.published',
        ]);
        $this->patch('/dashboard/product-manager/'.$product->id, [
            'decision' => 'published',
        ])->assertStatus(409);
        $this->assertSame(1, \App\Models\AccountNotification::query()
            ->where('user_id', $creator->id)->where('event', 'listing.reviewed')->count());

        $this->post('/logout')->assertRedirect();
        $this->get($product->publicUrl())->assertOk()->assertSee('Regular Product License');
        $this->get($asset->previewUrl())->assertOk();
        $this->get('/search?type=product')->assertOk()->assertSee('Original Premium Web Template');

        $this->actingAs($creator)->patch('/studio/products/'.$product->id,
            $this->productForm($license, $asset, 'draft')
        )->assertStatus(409);
        $this->delete('/studio/media-library/'.$asset->id)->assertStatus(409);
        Storage::disk('local')->assertExists($asset->storage_path);
    }

    public function test_creator_cannot_attach_another_persons_private_image_even_to_draft(): void
    {
        $owner = $this->member('freelancer');
        $other = $this->member('freelancer');
        $admin = $this->member('admin');
        $license = $this->createLicense($admin);
        $asset = $this->uploadImage($owner);

        $this->actingAs($other)->post('/studio/products', $this->productForm($license, $asset, 'draft'))
            ->assertSessionHasErrors('cover_media_id');
        $this->get('/studio/media-library')->assertOk()->assertDontSee($asset->original_name);

        $this->patch('/studio/media-library/'.$asset->id, [
            'alt_text' => 'Stolen rights declaration',
            'rights_basis' => 'owned',
            'rights_holder' => 'Unauthorized',
        ])->assertNotFound();

        $this->assertDatabaseCount('marketplace_listings', 0);
    }

    public function test_only_authorized_reviewers_can_approve_images_and_products(): void
    {
        $creator = $this->member('freelancer');
        $staff = $this->member('staff');
        $admin = $this->member('admin');
        $license = $this->createLicense($admin);
        $asset = $this->uploadImage($creator);

        $this->actingAs($staff)->get('/dashboard/media-library')->assertForbidden();
        $this->actingAs($creator)->patch('/dashboard/media-library/'.$asset->id.'/review', [
            'decision' => 'approved',
        ])->assertForbidden();
        $this->actingAs($creator)->get('/dashboard/product-manager')->assertForbidden();

        $this->actingAs($admin)->patch('/dashboard/media-library/'.$asset->id.'/review', [
            'decision' => 'approved',
        ])->assertRedirect();

        $this->actingAs($creator)->post('/studio/products',
            $this->productForm($license, $asset, 'pending')
        )->assertRedirect();

        $product = MarketplaceListing::firstOrFail();
        $this->actingAs($creator)->patch('/dashboard/product-manager/'.$product->id, [
            'decision' => 'published',
        ])->assertForbidden();
        $this->assertSame('pending', $product->fresh()->status);
    }

    public function test_regular_users_manage_only_owned_files_and_administrators_manage_all(): void
    {
        $owner = $this->member('freelancer');
        $other = $this->member('freelancer');
        $admin = $this->member('admin');
        $superAdmin = $this->member('super_admin');
        $asset = $this->uploadImage($owner);

        $this->actingAs($other)->get('/studio/media-library')
            ->assertOk()->assertDontSee($asset->original_name);
        $this->get($asset->previewUrl())->assertNotFound();
        $this->patch('/studio/media-library/'.$asset->id, [
            'alt_text' => 'Not my image', 'rights_basis' => 'owned',
            'rights_holder' => 'Other',
        ])->assertNotFound();
        $this->delete('/studio/media-library/'.$asset->id)->assertNotFound();

        $this->actingAs($owner)->patch('/studio/media-library/'.$asset->id, [
            'alt_text' => 'Owner updated the photo description',
            'rights_basis' => 'owned', 'rights_holder' => 'Owner',
        ])->assertRedirect();
        $this->assertSame('Owner updated the photo description', $asset->fresh()->alt_text);

        // Previously approved files remain editable; review state no longer locks them.
        $asset->update(['review_status' => 'approved']);
        $this->actingAs($admin)->get('/dashboard/media-library')
            ->assertOk()->assertSee($asset->original_name)->assertDontSee('Approve image rights');
        $this->patch('/dashboard/media-library/'.$asset->id, [
            'alt_text' => 'Administrator correction', 'rights_basis' => 'owned',
            'rights_holder' => 'Owner',
        ])->assertRedirect();
        $this->assertSame('Administrator correction', $asset->fresh()->alt_text);
        $this->actingAs($superAdmin)->get('/dashboard/media-library')->assertOk();
        $this->get($asset->previewUrl())->assertOk();
        $this->delete('/dashboard/media-library/'.$asset->id)->assertRedirect();
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
    }

    public function test_customer_account_library_is_private_and_owner_editable(): void
    {
        $owner = $this->member('customer');
        $other = $this->member('customer');

        $this->actingAs($owner)->post(route('account.media.store'), [
            'image' => $this->makeImage(),
            'alt_text' => 'Personal project cover image',
            'rights_basis' => 'owned',
            'rights_holder' => $owner->name,
        ])->assertRedirect(route('account.media.index'));
        $asset = MediaAsset::query()->latest('id')->firstOrFail();
        $this->actingAs($owner)->get(route('account.media.index'))
            ->assertOk()->assertSee($asset->original_name)->assertSee('My Account');
        $this->patch(route('account.media.update', $asset), [
            'alt_text' => 'New personal image description',
            'rights_basis' => 'owned', 'rights_holder' => $owner->name,
        ])->assertRedirect();
        $this->assertSame('New personal image description', $asset->fresh()->alt_text);

        $this->actingAs($other)->get(route('account.media.index'))
            ->assertOk()->assertDontSee($asset->original_name);
        $this->get($asset->previewUrl())->assertNotFound();
        $this->patch(route('account.media.update', $asset), [
            'alt_text' => 'Stolen changes', 'rights_basis' => 'owned',
            'rights_holder' => $other->name,
        ])->assertNotFound();
        $this->delete(route('account.media.destroy', $asset))->assertNotFound();

        $this->actingAs($owner)->delete(route('account.media.destroy', $asset))
            ->assertRedirect();
        $this->assertDatabaseMissing('media_assets', ['id' => $asset->id]);
    }
}
