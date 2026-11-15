<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MediaUploadWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::query()->create([
            'name' => 'Media uploader', 'email' => uniqid().'@example.test',
            'password' => 'VeryStrongAccountPassword123!', 'role' => $role,
        ]);
    }

    private function image(string $name = 'photo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l5cAAAAASUVORK5CYII='
        ));
    }

    public function test_upload_pages_and_library_links_are_available_in_each_workspace(): void
    {
        foreach (['dashboard' => 'admin', 'studio' => 'freelancer', 'account' => 'customer'] as $prefix => $role) {
            $this->actingAs($this->member($role))->get("/$prefix/media-library/upload")
                ->assertOk()->assertSee('data-dropzone', false)->assertSee('multiple', false)
                ->assertSee('Advanced Settings');
            $this->get("/$prefix/media-library")->assertOk()
                ->assertSee("/$prefix/media-library/upload")->assertDontSee('id="media-new"', false);
        }
    }

    public function test_file_only_uploads_return_json_and_preserve_successes_when_another_file_fails(): void
    {
        Storage::fake('local');
        foreach (['dashboard' => 'admin', 'studio' => 'freelancer', 'account' => 'customer'] as $prefix => $role) {
            $owner = $this->member($role);
            $this->actingAs($owner)->postJson("/$prefix/media-library", ['image' => $this->image()])
                ->assertCreated()->assertJsonPath('alt_text', 'photo');
            $asset = MediaAsset::query()->where('user_id', $owner->id)->firstOrFail();
            $this->assertSame('', $asset->rights_basis);
            $this->assertSame('', $asset->rights_holder);
            Storage::disk('local')->assertExists($asset->storage_path);
            $this->postJson("/$prefix/media-library", [
                'image' => UploadedFile::fake()->createWithContent('bad.svg', '<svg></svg>'),
            ])->assertUnprocessable()->assertJsonValidationErrors('image');
            $this->assertSame(1, MediaAsset::query()->where('user_id', $owner->id)->count());
            $this->postJson("/$prefix/media-library", ['image' => $this->image('second.png')])
                ->assertCreated();
            $this->assertSame(2, MediaAsset::query()->where('user_id', $owner->id)->count());
        }
    }

    public function test_advanced_fields_are_optional_even_for_licensed_uploads(): void
    {
        Storage::fake('local');
        $this->actingAs($this->member('customer'))->postJson('/account/media-library', [
            'image' => $this->image(), 'rights_basis' => 'licensed',
        ])->assertCreated();
        $this->assertSame('licensed', MediaAsset::query()->firstOrFail()->rights_basis);
    }

    public function test_upload_page_keeps_existing_access_boundaries(): void
    {
        $this->get('/account/media-library/upload')->assertRedirect('/login');
        $this->actingAs($this->member('customer'))->get('/dashboard/media-library/upload')->assertForbidden();
    }

    public function test_unused_media_can_be_edited_and_deleted_in_every_workspace(): void
    {
        Storage::fake('local');
        foreach (['dashboard' => 'admin', 'studio' => 'freelancer', 'account' => 'customer'] as $prefix => $role) {
            $owner = $this->member($role);
            $this->actingAs($owner)->postJson("/$prefix/media-library", ['image' => $this->image()])->assertCreated();
            $asset = MediaAsset::query()->where('user_id', $owner->id)->firstOrFail();
            $url = "/$prefix/media-library/".$asset->id;
            $this->get($url)->assertOk()->assertSee('Delete unused media');
            $this->patch($url, ['alt_text' => 'Updated description', 'rights_basis' => '', 'rights_holder' => '', 'rights_note' => ''])
                ->assertRedirect($url)->assertSessionHasNoErrors();
            $this->assertSame('Updated description', $asset->fresh()->alt_text);
            $this->assertSame('', $asset->fresh()->rights_holder);
            $this->delete($url)->assertRedirect("/$prefix/media-library");
            $this->assertModelMissing($asset);
            Storage::disk('local')->assertMissing($asset->storage_path);
        }
    }

    public function test_gallery_references_match_ui_and_return_useful_html_error_while_allowing_edits(): void
    {
        Storage::fake('local');
        $owner = $this->member('customer');
        $this->actingAs($owner)->postJson('/account/media-library', ['image' => $this->image()])->assertCreated();
        $asset = MediaAsset::query()->firstOrFail();
        $listing = \App\Models\MarketplaceListing::query()->create([
            'user_id' => $owner->id, 'type' => 'service', 'title' => 'Gallery example',
            'description' => 'Example gallery', 'status' => 'draft', 'gallery_media_ids' => [$asset->id],
        ]);
        $url = '/account/media-library/'.$asset->id;
        $this->get('/account/media-library')->assertOk()->assertSee('1 listing(s), including galleries')->assertDontSee('Delete unused media');
        $this->get($url)->assertOk()->assertDontSee('Delete unused media')->assertSee('Save media details');
        $this->delete($url)->assertRedirect($url)->assertSessionHasErrors('media');
        Storage::disk('local')->assertExists($asset->storage_path);
        $this->patch($url, ['alt_text' => 'Edited while linked'])->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertSame('Edited while linked', $asset->fresh()->alt_text);
        $listing->update(['gallery_media_ids' => [(string) $asset->id]]);
        $this->deleteJson($url)->assertStatus(409);
        $listing->update(['gallery_media_ids' => []]);
        $this->delete($url)->assertRedirect('/account/media-library');
        $this->assertModelMissing($asset);
    }

    public function test_blog_reference_blocks_deletion_and_other_owners_cannot_edit_or_delete(): void
    {
        Storage::fake('local');
        $owner = $this->member('customer');
        $this->actingAs($owner)->postJson('/account/media-library', ['image' => $this->image()])->assertCreated();
        $asset = MediaAsset::query()->firstOrFail();
        \App\Models\ContentEntry::query()->create([
            'author_id' => $owner->id, 'type' => 'post', 'title' => 'Media post', 'body' => 'Body',
            'status' => 'draft', 'featured_media_id' => $asset->id,
        ]);
        $url = '/account/media-library/'.$asset->id;
        $this->get($url)->assertOk()->assertSee('1 content entry/entries')->assertDontSee('Delete unused media');
        $this->delete($url)->assertRedirect($url)->assertSessionHasErrors('media');
        $this->actingAs($this->member('customer'))->get($url)->assertNotFound();
        $this->patch($url, ['alt_text' => 'Unauthorized'])->assertNotFound();
        $this->delete($url)->assertNotFound();
        $this->assertModelExists($asset);
    }
}
