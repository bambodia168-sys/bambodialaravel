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

    public function test_pending_media_can_be_published_without_admin_approval(): void
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
        Storage::fake('local');
        Storage::disk('local')->put($media->storage_path, 'pending-image-bytes');
        $this->post(route('dashboard.posts.store'), $this->payload([
            'featured_media_id'=>$media->id,
            'status'=>'published',
        ]))->assertRedirect();
        $entry = ContentEntry::query()->where('type', 'post')->firstOrFail();
        $this->assertSame('published', $entry->status);
        $this->get($entry->publicUrl())->assertOk()->assertSee($media->previewUrl());
        $this->post('/logout')->assertRedirect();
        $this->get($media->previewUrl())->assertOk();
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

    public function test_post_drag_drop_upload_can_publish_with_owner_attestation(): void
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
        ]))->assertRedirect();
        $this->assertSame('pending', $asset->fresh()->review_status);
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
        $this->get($asset->previewUrl())->assertOk()->assertStreamed()->assertStreamedContent('private-r2-image-bytes');
    }


    public function test_administrators_can_use_media_from_any_owner_without_review(): void
    {
        Storage::fake('local');
        $owner = $this->member('customer');
        $admin = $this->member('admin');
        $asset = MediaAsset::query()->create([
            'user_id' => $owner->id, 'original_name' => 'creator-cover.png',
            'storage_path' => 'marketplace/previews/'.$owner->id.'/creator-cover.png',
            'mime_type' => 'image/png', 'bytes' => 10, 'width' => 1, 'height' => 1,
            'alt_text' => 'Creator cover', 'rights_basis' => 'owned',
            'rights_holder' => 'Creator', 'review_status' => 'pending',
        ]);
        Storage::disk('local')->put($asset->storage_path, 'image contents');
        $this->actingAs($admin)->get(route('dashboard.posts.create'))
            ->assertOk()->assertSee('creator-cover.png')->assertDontSee('Pending review');
        $this->post(route('dashboard.posts.store'), $this->payload([
            'featured_type' => 'image', 'featured_media_id' => $asset->id,
            'status' => 'published',
        ]))->assertRedirect();
        $this->assertDatabaseHas('content_entries', [
            'type' => 'post', 'status' => 'published', 'featured_media_id' => $asset->id,
        ]);
    }

    public function test_post_manager_uses_top_right_action_clickable_titles_and_ten_row_page_navigation(): void
    {
        $admin = $this->member();
        $this->actingAs($admin);
        foreach (range(1, 21) as $i) {
            ContentEntry::query()->create([
                'type' => 'post',
                'author_id' => $admin->id,
                'title' => sprintf('QA Compact Post %02d', $i),
                'excerpt' => 'HIDDEN_ROW_SUMMARY_'.$i,
                'body' => '<p>Readable text for the post manager table and editing workflow.</p>',
                'status' => $i % 2 === 0 ? 'published' : 'draft',
                'category' => 'Technology',
            ]);
        }

        $response = $this->get(route('dashboard.posts'))->assertOk();
        $response->assertViewHas('posts', fn ($items) => $items->count() === 10
            && $items->perPage() === 10 && $items->total() === 21
            && $items->currentPage() === 1);
        $response->assertSee('class="page-intro post-manager-page-heading"', false)
            ->assertSee('class="btn primary post-manager-create"', false)
            ->assertSee('class="post-manager-title-link"', false)
            ->assertSee('Previous')->assertSee('Next')->assertSee('Go To')
            ->assertSee('name="page"', false)
            ->assertDontSee('HIDDEN_ROW_SUMMARY_');

        $newest = ContentEntry::query()->where('type', 'post')->orderByDesc('id')->firstOrFail();
        $response->assertSee('href="'.route('dashboard.posts.edit', $newest).'"', false);
        $this->get(route('dashboard.posts.edit', $newest))->assertOk()
            ->assertSee($newest->title);

        $second = $this->get(route('dashboard.posts', ['q' => 'QA Compact', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('posts', fn ($items) => $items->count() === 10
                && $items->currentPage() === 2);
        $second->assertSee('name="q" value="QA Compact"', false)
            ->assertSee('name="page"', false)
            ->assertSee('Go To');

        $third = $this->get(route('dashboard.posts', ['q' => 'QA Compact', 'page' => 3]))
            ->assertOk()
            ->assertViewHas('posts', fn ($items) => $items->count() === 1
                && $items->currentPage() === 3);
        $third->assertSee('Showing 21–21 of 21');

        $filtered = $this->get(route('dashboard.posts', ['status' => 'published', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('posts', fn ($items) => $items->count() === 0
                && $items->total() === 10);
        $filtered->assertSee('name="status" value="published"', false);
    }

    public function test_blog_defaults_to_four_column_grid_and_three_rows_per_page(): void
    {
        $author = $this->member();
        foreach (range(1, 16) as $i) {
            ContentEntry::query()->create([
                'type' => 'post',
                'author_id' => $author->id,
                'title' => sprintf('Grid News Item %02d', $i),
                'excerpt' => 'GRID_SUMMARY_'.$i,
                'body' => '<p>Published news content used to verify public blog pagination.</p>',
                'status' => 'published',
                'category' => $i % 2 === 0 ? 'AI' : 'Technology',
                'published_at' => now()->subMinutes(16 - $i),
            ]);
        }
        $result = $this->get(route('blog'))->assertOk()
            ->assertViewHas('view', 'grid')->assertViewHas('columns', 4)
            ->assertViewHas('posts', fn ($posts) => $posts->perPage() === 12
                && $posts->count() === 12 && $posts->total() === 16)
            ->assertSee('blog-post-grid columns-4', false)
            ->assertSee('Grid News Item 16')
            ->assertDontSee('Grid News Item 01')
            ->assertDontSee('Insights &amp; Updates', false)
            ->assertDontSee('Published articles')
            ->assertSee('Previous')->assertSee('Next')->assertSee('Go To');

        $newest = ContentEntry::query()->where('type', 'post')->orderByDesc('id')->firstOrFail();
        $result->assertSee('href="'.$newest->publicUrl().'"', false);

        $this->get(route('blog', ['page' => 2]))->assertOk()
            ->assertViewHas('posts', fn ($posts) => $posts->perPage() === 12
                && $posts->count() === 4 && $posts->currentPage() === 2)
            ->assertSee('Grid News Item 01');

        foreach ([2 => 6, 4 => 12, 5 => 15] as $columns => $expected) {
            $this->get(route('blog', ['view' => 'grid', 'columns' => $columns]))
                ->assertOk()
                ->assertViewHas('posts', fn ($posts) => $posts->perPage() === $expected
                    && $posts->count() === $expected);
        }

        $this->get(route('blog', ['view' => 'list']))->assertOk()
            ->assertViewHas('posts', fn ($posts) => $posts->perPage() === 3
                && $posts->count() === 3)
            ->assertSee('blog-list-number', false)
            ->assertSee('Grid News Item 16')
            ->assertSee('>Read</a>', false)
            ->assertDontSee('GRID_SUMMARY_')
            ->assertDontSee('Read article');

        $this->get(route('blog', ['view' => 'list', 'page' => 2]))->assertOk()
            ->assertViewHas('posts', fn ($posts) => $posts->currentPage() === 2
                && $posts->count() === 3)
            ->assertSee('4.')->assertSee('Grid News Item 13');
        $this->get(route('blog', ['view' => 'list', 'page' => 6]))->assertOk()
            ->assertViewHas('posts', fn ($posts) => $posts->currentPage() === 6
                && $posts->count() === 1)
            ->assertSee('16.')->assertSee('Grid News Item 01');

        $this->get(route('blog', ['category' => 'AI']))->assertOk()
            ->assertViewHas('posts', fn ($posts) => $posts->total() === 8)
            ->assertSee('Category:')->assertSee('Clear filter')
            ->assertDontSee('Grid News Item 15');
    }

    public function test_public_article_has_sidebar_and_preserves_natural_featured_image_dimensions(): void
    {
        Storage::fake('local');
        $author = $this->member();
        $asset = MediaAsset::query()->create([
            'user_id' => $author->id,
            'original_name' => 'natural-cover.png',
            'storage_path' => 'marketplace/previews/'.$author->id.'/natural-cover.png',
            'mime_type' => 'image/png', 'bytes' => 32,
            'width' => 420, 'height' => 620,
            'alt_text' => 'Uncropped tall cover', 'rights_basis' => 'owned',
            'rights_holder' => 'Post Author', 'review_status' => 'pending',
        ]);
        Storage::disk('local')->put($asset->storage_path, 'test image bytes');
        $post = ContentEntry::query()->create([
            'type' => 'post', 'author_id' => $author->id,
            'title' => 'Read this featured article',
            'excerpt' => 'READ_ARTICLE_EXCERPT',
            'body' => '<p>Article body after media.</p>',
            'body_format' => 'html',
            'status' => 'published',
            'category' => 'Design',
            'featured_type' => 'image',
            'featured_media_id' => $asset->id,
            'published_at' => now(),
        ]);
        $other = ContentEntry::query()->create([
            'type' => 'post', 'author_id' => $author->id,
            'title' => 'Related design article',
            'body' => 'Another published article with helpful information.',
            'status' => 'published',
            'category' => 'Design',
            'published_at' => now()->subMinute(),
        ]);
        $response = $this->get($post->publicUrl())->assertOk()
            ->assertSee('blog-article-sidebar', false)
            ->assertSee('More articles')
            ->assertSee('Categories')
            ->assertSee('Related design article')
            ->assertSee($other->publicUrl())
            ->assertSee('class="post-public-image"', false)
            ->assertSee($asset->previewUrl())
            ->assertSee('post-featured-figure', false);
        $html = $response->getContent();
        $this->assertLessThan(strpos($html, '<p class="post-public-excerpt">READ_ARTICLE_EXCERPT'), strpos($html, 'post-featured-figure'));

        $page = ContentEntry::query()->create([
            'type' => 'page', 'author_id' => $author->id,
            'title' => 'Public About Page',
            'body' => 'Information about the platform.',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $this->get($page->publicUrl())->assertOk()->assertDontSee('blog-article-sidebar', false);

        $this->get(route('blog', ['view' => 'list']))->assertSee('Read this featured article');
        $post->update(['status' => 'draft', 'published_at' => null]);
        $this->get($post->fresh()->publicUrl())->assertNotFound();
        $this->get(route('blog'))->assertDontSee('Read this featured article');
    }
}
