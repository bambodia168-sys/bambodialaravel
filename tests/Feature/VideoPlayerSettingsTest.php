<?php

namespace Tests\Feature;

use App\Models\ContentEntry;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\VideoPlayerSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class VideoPlayerSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::query()->create([
            'name' => 'Player settings '.$role,
            'email' => uniqid($role, true).'@example.test',
            'password' => 'VideoPlayerTestPassword123!',
            'role' => $role,
        ]);
    }

    private function profile(array $values = []): array
    {
        return array_replace_recursive(VideoPlayerSettings::defaults(), $values);
    }

    private function savePlayer(string $type, array $profile)
    {
        return $this->patch(route('dashboard.player-settings.update'), [
            'type' => $type, 'profiles' => [$type => $profile],
        ]);
    }

    public function test_navigation_and_settings_are_available_to_administrators_only(): void
    {
        $url = route('dashboard.player-settings');
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs($this->member('staff'))->get($url)->assertForbidden();
        $this->savePlayer('bambo', $this->profile())->assertForbidden();
        $this->actingAs($this->member('admin'))->get($url)->assertOk()
            ->assertSeeInOrder(['Assets &amp; Communication', 'Media Library', 'Player Settings', 'Digital File Vault'], false)
            ->assertSee('Bambo Player')
            ->assertSee('Plyr Player');

        $this->assertSame('bambo', VideoPlayerSettings::read()['type']);
        $this->assertFalse(VideoPlayerSettings::read()['profiles']['bambo']['ad']['enabled']);
    }

    public function test_player_type_preserves_independent_settings_and_allows_optional_controls_to_be_removed(): void
    {
        $admin = $this->member('super_admin');
        $this->actingAs($admin);
        $bambo = $this->profile(['skin' => 'midnight', 'overlay_text' => 'Bambodia editorial']);
        $bambo['controls'] = [];
        $this->savePlayer('bambo', $bambo)->assertSessionHasNoErrors();

        $plyr = $this->profile(['skin' => 'ocean', 'logo_url' => 'https://cdn.example.org/logo.png']);
        $plyr['controls'] = ['progress', 'rewind'];
        $this->savePlayer('plyr', $plyr)->assertSessionHasNoErrors();
        $settings = VideoPlayerSettings::read();
        $this->assertSame('plyr', $settings['type']);
        $this->assertSame([], $settings['profiles']['bambo']['controls']);
        $this->assertSame('midnight', $settings['profiles']['bambo']['skin']);
        $this->assertSame(['progress', 'rewind'], $settings['profiles']['plyr']['controls']);
        $this->assertDatabaseHas('site_settings', ['key' => VideoPlayerSettings::KEY, 'updated_by' => $admin->id]);
    }

    public function test_urls_formats_and_provider_tags_are_validated(): void
    {
        $this->actingAs($this->member('admin'));

        $this->savePlayer('bambo', $this->profile(['logo_url' => 'javascript:alert(1)']))
            ->assertSessionHasErrors('profiles.bambo.logo_url');
        $this->savePlayer('bambo', $this->profile(['controls' => ['rewind']]))
            ->assertSessionHasErrors('profiles.bambo.controls.0');

        $google = $this->profile(['ad' => ['enabled' => true, 'provider' => 'google', 'format' => 'google_tag',
            'media_url' => 'https://pubads.g.doubleclick.net.example.org/gampad/ads']]);
        $this->savePlayer('plyr', $google)->assertSessionHasErrors('profiles.plyr.ad.media_url');
        $google['ad']['media_url'] = 'https://pubads.g.doubleclick.net/gampad/ads?output=vast';
        $this->savePlayer('plyr', $google)->assertSessionHasNoErrors();
        $google['ad']['provider'] = 'facebook';
        $this->savePlayer('plyr', $google)->assertSessionHasErrors('profiles.plyr.ad.media_url');
    }

    public function test_published_video_uses_the_selected_player_and_private_previews_never_play_ads(): void
    {
        Storage::fake('local');
        $user = $this->member('admin');
        $asset = MediaAsset::query()->create([
            'user_id' => $user->id, 'original_name' => 'example.webm', 'storage_path' => 'preview/example.webm',
            'mime_type' => 'video/webm', 'bytes' => 10, 'width' => 0, 'height' => 0, 'alt_text' => 'Example video',
            'rights_basis' => 'owned', 'rights_holder' => 'Bambodia', 'review_status' => 'approved',
        ]);
        Storage::disk('local')->put($asset->storage_path, 'test video');
        $post = ContentEntry::query()->create([
            'type' => 'post', 'author_id' => $user->id, 'title' => 'Uploaded video player',
            'status' => 'published',
            'featured_type' => 'video', 'featured_media_id' => $asset->id,
            'body' => 'An editorial post.', 'published_at' => now(),
        ]);
        $profile = $this->profile([
            'skin' => 'ocean', 'overlay_text' => '<b>Bambodia</b>',
            'ad' => ['enabled' => true, 'provider' => 'facebook', 'format' => 'text', 'text' => 'Campaign message'],
        ]);
        $this->actingAs($user);
        $this->savePlayer('plyr', $profile)->assertSessionHasNoErrors();
        $this->get($post->publicUrl())->assertOk()
            ->assertSee('data-configured-player="plyr"', false)
            ->assertSee($asset->previewUrl(), false)
            ->assertSee('configured-player--ocean', false)
            ->assertSee('&lt;b&gt;Bambodia&lt;/b&gt;', false)
            ->assertSee('data-player-ad="text"', false)
            ->assertSee('Campaign message')
            ->assertDontSee('data-blog-premium-player', false);
        $this->get(route('dashboard.media.index'))->assertOk()
            ->assertSee('data-configured-player="plyr"', false)
            ->assertDontSee('data-player-ad=', false);
        $this->get(route('dashboard.posts.preview', $post))->assertOk()
            ->assertSee('data-configured-player="plyr"', false)
            ->assertDontSee('data-player-ad=', false);
    }
}
