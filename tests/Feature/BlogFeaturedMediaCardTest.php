<?php

namespace Tests\Feature;

use App\Models\ContentEntry;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BlogFeaturedMediaCardTest extends TestCase
{
    use RefreshDatabase;

    private function author(): User
    {
        return User::query()->create([
            'name' => 'Editorial Test Author',
            'email' => 'editorial-featured-test@example.test',
            'password' => 'TestPassword123!',
            'role' => 'super_admin',
        ]);
    }

    private function published(User $author, string $title, array $values = []): ContentEntry
    {
        return ContentEntry::query()->create(array_replace([
            'author_id' => $author->id,
            'type' => 'post',
            'title' => $title,
            'body' => '<p>Real editorial guidance for the public blog.</p>',
            'status' => 'published',
            'published_at' => now(),
            'category' => 'Technology',
        ], $values));
    }

    private function asset(User $author, string $mime = 'video/mp4'): MediaAsset
    {
        $path = 'marketplace/previews/'.$author->id.'/featured-media.'.($mime === 'video/mp4' ? 'mp4' : 'jpg');
        Storage::disk('local')->put($path, 'test-media-bytes');
        return MediaAsset::query()->create([
            'user_id' => $author->id,
            'original_name' => basename($path),
            'storage_path' => $path,
            'mime_type' => $mime,
            'bytes' => 16,
            'width' => $mime === 'video/mp4' ? 0 : 640,
            'height' => $mime === 'video/mp4' ? 0 : 400,
            'alt_text' => 'Editorial featured media',
            'rights_basis' => 'owned',
            'rights_holder' => 'Editorial Test Author',
            'review_status' => 'approved',
        ]);
    }

    public function test_uploaded_video_has_real_preview_source_and_premium_overlay_on_home_and_blog(): void
    {
        Storage::fake('local');
        $author = $this->author();
        $asset = $this->asset($author);
        $post = $this->published($author, 'Video preview featured article', [
            'featured_type' => 'video',
            'featured_media_id' => $asset->id,
        ]);

        foreach (['/', '/blog'] as $path) {
            $response = $this->get($path)->assertOk()
                ->assertSee('Video preview featured article')
                ->assertSee('data-blog-video-poster', false)
                ->assertSee('class="blog-card-video-thumbnail"', false)
                ->assertSee('class="blog-card-play-overlay"', false)
                ->assertSee($asset->previewUrl().'#t=0.1')
                ->assertSee('preload="none"', false)
                ->assertDontSee('Video article');
            $html = $response->getContent();
            $this->assertStringContainsString($post->publicUrl(), $html);
            $this->assertStringNotContainsString('<iframe class="post-public-youtube"', $html);
        }

        $this->get($asset->previewUrl())->assertOk();
    }

    public function test_youtube_uses_allowlisted_thumbnail_and_clickable_article_without_loading_player(): void
    {
        $author = $this->author();
        $post = $this->published($author, 'YouTube featured article', [
            'featured_type' => 'youtube',
            'featured_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);
        foreach (['/', '/blog'] as $path) {
            $response = $this->get($path)->assertOk()
                ->assertSee($post->title)
                ->assertSee('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg')
                ->assertSee('data-blog-youtube-thumbnail', false)
                ->assertSee('blog-card-youtube-backdrop', false)
                ->assertSee('blog-card-play-overlay', false)
                ->assertDontSee('Video article')
                ->assertDontSee('<iframe class="post-public-youtube"', false);
            $this->assertStringContainsString($post->publicUrl(), $response->getContent());
        }
        // The public article still owns the actual YouTube player.
        $this->get($post->publicUrl())->assertOk()
            ->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ');
    }

    public function test_images_and_colors_keep_existing_featured_media_output(): void
    {
        Storage::fake('local');
        $author = $this->author();
        $image = $this->asset($author, 'image/jpeg');
        $this->published($author, 'Image feature', [
            'featured_type' => 'image', 'featured_media_id' => $image->id,
        ]);
        $this->published($author, 'Color feature', [
            'featured_type' => 'color', 'featured_color' => '#2868ab',
        ]);
        foreach (['/', '/blog'] as $path) {
            $this->get($path)->assertOk()
                ->assertSee($image->previewUrl())
                ->assertSee('background-color:#2868ab', false)
                ->assertSee('Featured color', false);
        }
    }

    public function test_invalid_stored_youtube_url_never_creates_a_third_party_thumbnail(): void
    {
        $author = $this->author();
        $this->published($author, 'Untrusted URL must not load', [
            'featured_type' => 'youtube',
            'featured_url' => 'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
        ]);
        $this->get('/')->assertOk()
            ->assertSee('Untrusted URL must not load')
            ->assertDontSee('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg')
            ->assertSee('Blog article');
    }
}
