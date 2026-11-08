<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ContentEntry;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PostBuilderExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'admin'): User
    {
        return User::query()->create([
            'name' => 'Post Author',
            'email' => uniqid('post-', true).'@example.test',
            'password' => 'TestPassword123!',
            'role' => $role,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Building trusted digital products',
            'excerpt' => 'An introduction to secure downloads and marketplace licensing.',
            'body' => '<h2>Introducing our marketplace</h2><p>Helpful guidance for buyers and responsible digital creators.</p>',
            'status' => 'draft',
            'category' => 'Technology',
            'tags' => 'Product, Licensing',
            'seo_title' => 'Bambodia Digital Marketplace Guide',
            'seo_description' => 'Discover verified product information and download guidance.',
        ], $overrides);
    }

    public function test_post_builder_creates_drafts_previews_updates_publishes_and_archives_without_affecting_page_manager(): void
    {
        $admin = $this->member();
        $this->actingAs($admin)->get(route('dashboard.posts'))->assertOk()
            ->assertSee('New post')->assertSee('Post Manager');
        $this->get(route('dashboard.posts.create'))->assertOk()
            ->assertSee('Main Content')->assertSee('Publication')
            ->assertSee('Featured media')->assertSee('Search metadata')
            ->assertDontSee('Headings, paragraphs, lists, quotes, tables and links');

        $response = $this->post(route('dashboard.posts.store'), $this->payload());
        $post = ContentEntry::query()->where('type', 'post')->firstOrFail();
        $response->assertRedirect(route('dashboard.posts.edit', $post));
        $this->assertSame('draft', $post->status);
        $this->assertSame('html', $post->body_format);
        $this->assertSame('Product, Licensing', $post->tags);
        $this->assertNull($post->published_at);
        $this->get($post->publicUrl())->assertNotFound();
        $this->get('/blog')->assertDontSee($post->title);
        $this->get(route('dashboard.posts.preview', $post))
            ->assertOk()->assertSee('Private preview')->assertSee($post->title)
            ->assertSee('<h2>Introducing our marketplace</h2>', false);

        $this->patch(route('dashboard.posts.update', $post), $this->payload([
            'status' => 'published',
            'title' => 'Trusted products and licenses',
            'tags' => 'Updates, Quality',
        ]))->assertRedirect(route('dashboard.posts.edit', $post));

        $post->refresh();
        $this->assertNotNull($post->published_at);
        $this->assertSame('trusted-products-and-licenses', $post->slug);
        $this->assertSame('published', $post->status);
        $this->get('/blog')->assertSee($post->title)->assertSee($post->excerpt);
        $this->get($post->publicUrl())->assertOk()
            ->assertSee('<h2>Introducing our marketplace</h2>', false)
            ->assertSee('Bambodia Digital Marketplace Guide')
            ->assertSee('content="Discover verified product information', false);

        $this->patch(route('dashboard.posts.update', $post), $this->payload([
            'status' => 'archived',
        ]))->assertRedirect();
        $this->assertSame('archived', $post->fresh()->status);
        $this->get($post->fresh()->publicUrl())->assertNotFound();
        $this->get('/blog')->assertDontSee($post->fresh()->title);
        $this->assertDatabaseHas('audit_events', ['actor_id'=>$admin->id, 'event'=>'post.created']);
        $this->assertDatabaseHas('audit_events', ['actor_id'=>$admin->id, 'event'=>'post.updated']);
    }

    public function test_post_manager_enforces_role_scope_and_type_boundary(): void
    {
        $author = $this->member();
        $this->actingAs($author)->post('/dashboard/page-manager', [
            'title' => 'About our company',
            'body' => 'Published information about the licensed digital marketplace.',
            'status' => 'published',
        ])->assertRedirect();
        $page = ContentEntry::query()->where('type','page')->firstOrFail();
        $this->get(route('dashboard.posts.edit', $page))->assertNotFound();
        $this->get(route('dashboard.posts.preview', $page))->assertNotFound();
        $this->patch(route('dashboard.posts.update', $page), $this->payload())->assertNotFound();
        $this->delete(route('dashboard.posts.destroy', $page), [
            'confirm_title' => $page->title,
        ])->assertNotFound();
        $this->get('/dashboard/page-manager')->assertOk();

        foreach (['customer', 'staff'] as $role) {
            $this->actingAs($this->member($role))->get(route('dashboard.posts.create'))->assertForbidden();
            $this->post(route('dashboard.posts.store'), $this->payload())->assertForbidden();
        }
    }

    public function test_post_content_is_sanitized_and_rejects_empty_html_and_invalid_tags(): void
    {
        $this->actingAs($this->member());
        $this->post(route('dashboard.posts.store'), $this->payload([
            'body'=>'<img src=x onerror=alert(1)><script>alert(1)</script>',
        ]))->assertSessionHasErrors('body');
        $this->post(route('dashboard.posts.store'), $this->payload([
            'tags'=>implode(', ', array_map(fn ($i) => 'tag'.$i, range(1, 11))),
        ]))->assertSessionHasErrors('tags');

        $this->post(route('dashboard.posts.store'), $this->payload([
            'status'=>'published',
            'body'=>'<h2>Public notice</h2><p onclick="alert(1)">A helpful marketplace guide for every reader.</p><script>alert(1)</script>',
        ]))->assertRedirect();
        $post = ContentEntry::query()->where('type', 'post')->firstOrFail();
        $this->assertStringNotContainsString('<script>', $post->body);
        $this->assertStringNotContainsString('onclick=', $post->body);
        $this->get($post->publicUrl())->assertOk()
            ->assertSee('<h2>Public notice</h2>', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('onclick="alert(1)"', false);
    }

    public function test_approved_featured_media_is_public_only_while_post_is_published_and_delete_is_confirmed(): void
    {
        Storage::fake('local');
        $admin = $this->member();
        $this->actingAs($admin);
        $path = 'marketplace/previews/'.$admin->id.'/post-test.jpg';
        Storage::disk('local')->put($path, 'qa-image-placeholder');

        $media = MediaAsset::query()->create([
            'user_id' => $admin->id,
            'original_name' => 'article-cover.jpg',
            'storage_path' => $path,
            'mime_type' => 'image/jpeg',
            'bytes' => 20,
            'width' => 320,
            'height' => 200,
            'alt_text' => 'Professional editorial cover',
            'rights_basis' => 'owned',
            'rights_holder' => 'Bambodia QA',
            'review_status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ]);
        $this->post(route('dashboard.posts.store'), $this->payload([
            'featured_media_id' => $media->id,
            'status' => 'published',
        ]))->assertRedirect();
        $post = ContentEntry::query()->where('type','post')->firstOrFail();
        $this->assertSame($media->id, $post->featured_media_id);
        $this->get($post->publicUrl())->assertOk()->assertSee($media->previewUrl());
        $this->get($media->previewUrl())->assertOk();
        $this->delete(route('dashboard.media.destroy', $media))->assertStatus(409);
        $this->delete(route('dashboard.posts.destroy', $post), ['confirm_title'=>'incorrect title'])
            ->assertSessionHasErrors('confirm_title');
        $this->assertDatabaseHas('content_entries', ['id'=>$post->id]);
        $this->delete(route('dashboard.posts.destroy', $post), ['confirm_title'=>$post->title])
            ->assertRedirect(route('dashboard.posts'));
        $this->assertDatabaseMissing('content_entries', ['id'=>$post->id]);
        $this->assertDatabaseHas('audit_events', ['event'=>'post.deleted', 'actor_id'=>$admin->id]);
        $this->get($post->publicUrl())->assertNotFound();
        $this->assertDatabaseHas('media_assets', ['id'=>$media->id]);
    }

    public function test_unapproved_media_cannot_be_attached_to_a_post(): void
    {
        $admin = $this->member();
        $this->actingAs($admin);
        $media = MediaAsset::query()->create([
            'user_id'=>$admin->id,'original_name'=>'unreviewed.png',
            'storage_path'=>'marketplace/previews/'.$admin->id.'/unreviewed.png',
            'mime_type'=>'image/png','bytes'=>20,'width'=>10,'height'=>10,
            'alt_text'=>'Unreviewed test image','rights_basis'=>'owned',
            'rights_holder'=>'Test author','review_status'=>'pending',
        ]);
        $this->post(route('dashboard.posts.store'), $this->payload([
            'featured_media_id'=>$media->id,
        ]))->assertSessionHasErrors('featured_media_id');
        $this->assertDatabaseCount('content_entries', 0);
    }

    public function test_category_dropdown_and_safe_youtube_or_color_feature(): void
    {
        $this->actingAs($this->member());
        $page = $this->get(route('dashboard.posts.create'))->assertOk();
        foreach (['AI','Technology','Business','Finance','Design','Development','Marketing','Education','Security','Trends'] as $category) {
            $page->assertSee('<option value="'.$category.'"', false);
        }
        $this->post(route('dashboard.posts.store'), $this->payload([
            'category' => 'AI',
            'featured_type' => 'youtube',
            'featured_url' => 'https://youtu.be/dQw4w9WgXcQ?t=12',
        ]))->assertRedirect();
        $post = ContentEntry::query()->where('type', 'post')->firstOrFail();
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $post->featured_url);
        $this->get(route('dashboard.posts.preview', $post))->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ');
        $this->patch(route('dashboard.posts.update', $post), $this->payload([
            'featured_type' => 'youtube',
            'featured_url' => 'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
        ]))->assertSessionHasErrors('featured_url');
        $this->patch(route('dashboard.posts.update', $post), $this->payload([
            'featured_type' => 'color',
            'featured_color' => '#cc55aa',
        ]))->assertRedirect();
        $this->assertSame('color', $post->fresh()->featured_type);
        $this->assertSame('#cc55aa', $post->fresh()->featured_color);
        $this->get(route('dashboard.posts.preview', $post))->assertSee('background-color:#cc55aa', false);
    }

    public function test_post_drag_drop_upload_is_private_and_requires_review_before_publish(): void
    {
        Storage::fake('local');
        $admin = $this->member();
        $this->actingAs($admin);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8B8CkAAAAASUVORK5CYII=');
        $payload = [
            'image' => UploadedFile::fake()->createWithContent('post-cover.png', $png),
            'alt_text' => 'Licensed editorial cover',
            'rights_basis' => 'owned',
            'rights_holder' => 'Post Author',
        ];
        $this->postJson(route('dashboard.posts.media.store'), $payload)->assertUnprocessable();
        $response = $this->postJson(route('dashboard.posts.media.store'), [
            ...$payload,
            'image' => UploadedFile::fake()->createWithContent('post-cover.png', $png),
            'rights_confirmed' => '1',
        ])->assertCreated()->assertJsonPath('review_status', 'pending');
        $asset = MediaAsset::query()->findOrFail($response->json('id'));
        $this->assertSame('local', $asset->storage_disk);
        Storage::disk('local')->assertExists($asset->storage_path);
        $this->post(route('dashboard.posts.store'), $this->payload([
            'featured_type' => 'image', 'featured_media_id' => $asset->id,
        ]))->assertRedirect();
        $post = ContentEntry::query()->where('type', 'post')->firstOrFail();
        $this->get(route('dashboard.posts.preview', $post))->assertSee($asset->previewUrl());
        $this->patch(route('dashboard.posts.update', $post), $this->payload([
            'featured_type' => 'image', 'featured_media_id' => $asset->id,
            'status' => 'published',
        ]))->assertSessionHasErrors('featured_media_id');
        $this->patch(route('dashboard.media.review', $asset), ['decision' => 'approved'])->assertRedirect();
        $this->patch(route('dashboard.posts.update', $post), $this->payload([
            'featured_type' => 'image', 'featured_media_id' => $asset->id,
            'status' => 'published',
        ]))->assertRedirect();
        $this->get($post->fresh()->publicUrl())->assertOk()->assertSee($asset->previewUrl());
    }

    public function test_approved_media_can_be_privately_streamed_from_r2_disk(): void
    {
        Storage::fake('r2');
        $admin = $this->member();
        $path = 'marketplace/previews/'.$admin->id.'/remote-test.png';
        Storage::disk('r2')->put($path, 'private-r2-image-bytes');
        $asset = MediaAsset::query()->create([
            'user_id' => $admin->id,
            'original_name' => 'remote.png',
            'storage_path' => $path,
            'storage_disk' => 'r2',
            'mime_type' => 'image/png',
            'bytes' => 22,
            'width' => 1,
            'height' => 1,
            'alt_text' => 'Remote image preview',
            'rights_basis' => 'owned',
            'rights_holder' => 'Post Author',
            'review_status' => 'approved',
        ]);
        $this->actingAs($admin)->post(route('dashboard.posts.store'), $this->payload([
            'featured_type' => 'image',
            'featured_media_id' => $asset->id,
            'status' => 'published',
        ]))->assertRedirect();
        $this->get($asset->previewUrl())->assertOk()->assertSee('private-r2-image-bytes');
    }

}
