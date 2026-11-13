<?php

namespace Tests\Feature;

use App\Models\ContentEntry;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BlogPremiumNativePlayerTest extends TestCase
{
    use RefreshDatabase;

    private function author(): User
    {
        return User::query()->create([
            'name' => 'Player Editorial Author',
            'email' => 'player-author@example.test',
            'password' => 'LocalTestPassword123!',
            'role' => 'super_admin',
        ]);
    }

    private function post(User $author, string $title, array $attributes): ContentEntry
    {
        return ContentEntry::query()->create(array_merge([
            'type' => 'post',
            'author_id' => $author->id,
            'title' => $title,
            'body' => '<p>Article content with useful guidance.</p>',
            'status' => 'published',
            'category' => 'Development',
            'published_at' => now(),
        ], $attributes));
    }

    public function test_uploaded_video_has_accessible_progressive_player_on_public_post_and_private_preview(): void
    {
        Storage::fake('local');
        $author = $this->author();
        $path = 'marketplace/previews/'.$author->id.'/player.webm';
        Storage::disk('local')->put($path, 'test player bytes');
        $asset = MediaAsset::query()->create([
            'user_id' => $author->id,
            'original_name' => 'player.webm',
            'storage_path' => $path,
            'mime_type' => 'video/webm',
            'bytes' => 17,
            'width' => 0,
            'height' => 0,
            'alt_text' => 'Engineering demo video',
            'rights_basis' => 'owned',
            'rights_holder' => 'Player Editorial Author',
            'review_status' => 'approved',
        ]);
        $title = 'From code changes to dependable releases';
        $post = $this->post($author, $title, [
            'featured_type' => 'video', 'featured_media_id' => $asset->id,
        ]);

        $this->get($post->publicUrl())->assertOk()
            ->assertSee('class="blog-premium-player"', false)
            ->assertSee('data-blog-premium-player', false)
            ->assertSee('data-blog-player-video', false)
            ->assertSee('data-blog-player-seek', false)
            ->assertSee('data-blog-player-volume', false)
            ->assertSee('data-blog-player-rate', false)
            ->assertSee('data-blog-player-fullscreen', false)
            ->assertSee('data-blog-player-pip', false)
            ->assertSee('data-blog-player-toggle', false)
            ->assertSee('aria-label="Play video"', false)
            ->assertSee('controls playsinline preload="metadata"', false)
            ->assertSee('src="'.$asset->previewUrl().'"', false)
            ->assertSee('<h1>'.$title.'</h1>', false)
            ->assertDontSee('autoplay', false)
            ->assertDontSee('post-public-youtube', false);
        $this->get($asset->previewUrl())->assertOk();

        $post->update(['status' => 'draft', 'published_at' => null]);
        $this->actingAs($author)
            ->get(route('dashboard.posts.preview', $post))
            ->assertOk()
            ->assertSee('data-blog-premium-player', false);
    }

    public function test_youtube_player_remains_existing_iframe_without_native_player(): void
    {
        $post = $this->post($this->author(), 'YouTube tutorial', [
            'featured_type' => 'youtube',
            'featured_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $this->get($post->publicUrl())->assertOk()
            ->assertSee('class="post-public-youtube"', false)
            ->assertSee('youtube-nocookie.com/embed/dQw4w9WgXcQ')
            ->assertDontSee('data-blog-premium-player', false);
    }

    public function test_non_video_article_does_not_load_native_player_controls(): void
    {
        $post = $this->post($this->author(), 'Regular article', [
            'featured_type' => 'color', 'featured_color' => '#163f7a',
        ]);

        $this->get($post->publicUrl())->assertOk()
            ->assertSee('<h1>Regular article</h1>', false)
            ->assertSee('background-color:#163f7a', false)
            ->assertDontSee('data-blog-premium-player', false);
    }
}
