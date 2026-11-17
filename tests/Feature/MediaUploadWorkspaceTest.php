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
}
