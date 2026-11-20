<?php

namespace Tests\Feature;

use App\Models\MarketplaceListing;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_in_navigation_uses_the_saved_avatar_in_each_workspace_and_on_public_pages(): void
    {
        Storage::fake('public');
        $user = $this->member('super_admin');
        $path = 'profile-avatars/account.png';
        Storage::disk('public')->put($path, 'profile image');
        $user->userProfile()->create(['avatar_path' => $path]);

        $service = MarketplaceListing::query()->create([
            'user_id' => $user->id,
            'type' => 'service',
            'title' => 'Website design support',
            'description' => 'Original fixed-price design work for small businesses.',
            'price' => '100.00',
            'status' => 'published',
        ]);

        $this->actingAs($user);
        $routes = [
            '/account?='.$user->public_id,
            '/dashboard',
            '/creator',
            '/developer',
            $service->publicUrl(),
            route('detail.support', ['kind' => 'service', 'reference' => $service->public_id, 'slug' => $service->slug]),
            '/account/profile',
        ];
        foreach ($routes as $url) {
            $menu = $this->menu($this->get($url)->assertOk());
            $photo = $menu->getElementsByTagName('img')->item(0);
            $this->assertInstanceOf(DOMElement::class, $photo, $url);
            $this->assertSame(Storage::disk('public')->url($path), $photo->getAttribute('src'), $url);
            $this->assertTrue($menu->getElementsByTagName('span')->item(1)->hasAttribute('hidden'), $url);
        }

        Storage::disk('public')->delete($path);
        $this->assertFallback($this->menu($this->get('/dashboard')->assertOk()));

        $user->userProfile()->update(['avatar_path' => null]);
        $user->unsetRelation('userProfile');
        $this->assertFallback($this->menu($this->get('/account?='.$user->public_id)->assertOk()));
    }

    public function test_guest_and_account_without_profile_use_the_user_icon(): void
    {
        $this->assertFallback($this->menu($this->get('/')->assertOk()));

        $member = $this->member('customer');
        $this->assertFallback($this->menu($this->actingAs($member)->get('/account?='.$member->public_id)->assertOk()));
    }

    public function test_public_creator_surfaces_follow_existing_profile_visibility(): void
    {
        Storage::fake('public');
        $creator = $this->member('freelancer');
        $creator->creatorProfile()->create([
            'headline' => 'Independent design consultant',
            'biography' => str_repeat('Original design and storefront consultation. ', 3),
            'is_public' => true,
        ]);
        $path = 'profile-avatars/creator.png';
        Storage::disk('public')->put($path, 'creator image');
        $creator->userProfile()->create(['avatar_path' => $path]);
        $listing = MarketplaceListing::query()->create([
            'user_id' => $creator->id,
            'type' => 'service',
            'title' => 'Design consultation',
            'description' => 'A published public service by an independent design consultant.',
            'price' => '75.00',
            'status' => 'published',
        ]);

        $avatar = Storage::disk('public')->url($path);
        $profile = route('creators.show', $creator->public_id);
        foreach (['/', '/creators', $profile, $listing->publicUrl()] as $url) {
            $this->get($url)->assertOk()->assertSee($avatar);
        }

        $creator->creatorProfile()->update(['is_public' => false]);
        foreach (['/', '/creators', $profile, $listing->publicUrl()] as $url) {
            $this->get($url)->assertOk()->assertDontSee($avatar);
        }
    }

    private function member(string $role): User
    {
        return User::query()->create([
            'name' => 'Avatar test member',
            'email' => $role.'-avatar@example.test',
            'password' => 'AvatarTestingPassword123!',
            'role' => $role,
        ]);
    }

    private function menu(TestResponse $response): DOMElement
    {
        $document = new DOMDocument();
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $menu = (new DOMXPath($document))->query('//summary[@class="profile-trigger" or @class="workspace-profile"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $menu);

        return $menu;
    }

    private function assertFallback(DOMElement $menu): void
    {
        $this->assertSame(0, $menu->getElementsByTagName('img')->length);
        $this->assertSame(1, $menu->getElementsByTagName('svg')->length);
        $this->assertFalse($menu->getElementsByTagName('span')->item(1)->hasAttribute('hidden'));
    }
}
