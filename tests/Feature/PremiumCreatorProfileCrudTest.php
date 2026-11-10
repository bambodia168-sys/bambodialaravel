<?php

namespace Tests\Feature;

use App\Models\CreatorBadge;
use App\Models\CreatorBadgeGrant;
use App\Models\CreatorProfile;
use App\Models\CreatorProfileItem;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PremiumCreatorProfileCrudTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'freelancer'): User
    {
        return User::query()->create([
            'name' => 'Profile QA Creator',
            'email' => uniqid('profile-', true).'@example.test',
            'role' => $role,
            'password' => 'LongUniquePassword123!',
        ]);
    }

    public function test_public_profile_places_all_awards_in_sidebar_before_contact_and_keeps_listing_grid(): void
    {
        $creator = $this->member();
        foreach (CreatorBadge::query()->where('enabled', true)->get() as $badge) {
            CreatorBadgeGrant::query()->create([
                'user_id' => $creator->id,
                'creator_badge_id' => $badge->id,
                'source' => 'manual',
                'evidence_note' => 'Public layout test fixture',
            ]);
        }
        MarketplaceListing::query()->create([
            'user_id' => $creator->id,
            'type' => 'project',
            'title' => 'Profile layout testing work',
            'description' => str_repeat('Original marketplace sample content. ', 4),
            'status' => 'published',
            'currency' => 'USD',
        ]);
        $html = $this->get(route('creators.show', $creator->public_id))->assertOk()
            ->assertSee('Click to copy')
            ->assertSee('Profile Highlights')
            ->assertSee('Published work')
            ->getContent();

        $this->assertStringContainsString('class="profile-v3-sidebar"', $html);
        $this->assertStringContainsString('data-profile-copy="'.$creator->public_id.'"', $html);
        $this->assertSame(16, substr_count($html, 'class="creator-badge creator-badge--'));
        $this->assertLessThan(strpos($html, 'class="btn primary profile-v3-contact"'), strpos($html, 'class="profile-v3-awards"'));
        $this->assertStringContainsString('class="marketplace-card-grid"', $html);
        $this->assertStringContainsString('Profile layout testing work', $html);
    }

    public function test_creator_editing_and_profile_items_have_full_owner_crud(): void
    {
        $creator = $this->member();
        $this->actingAs($creator)->get(route('studio.profile.edit'))
            ->assertOk()->assertSee('Creator Profile')->assertSee('Add expertise');
        $this->actingAs($creator)->patch(route('studio.profile.update'), [
            'headline' => 'Full stack Laravel engineer',
            'biography' => str_repeat('I create accessible modern web applications with documented handovers. ', 2),
            'is_public' => '1',
        ])->assertRedirect();
        $this->assertTrue((bool) CreatorProfile::query()->where('user_id', $creator->id)->firstOrFail()->is_public);

        $this->post(route('account.profile.items.store'), [
            'kind' => 'expertise', 'label' => 'Laravel Engineering',
            'detail' => 'Backend and API design', 'sort_order' => '1', 'is_visible' => '1',
        ])->assertRedirect();
        $item = CreatorProfileItem::query()->where('user_id', $creator->id)->firstOrFail();
        $this->get(route('creators.show', $creator->public_id))->assertOk()->assertSee('Laravel Engineering');

        $this->patch(route('account.profile.items.update', $item), [
            'label' => 'Laravel & API Engineering', 'detail' => 'Full stack',
            'sort_order' => '2', 'is_visible' => '0',
        ])->assertRedirect();
        $this->assertSame('Laravel & API Engineering', $item->fresh()->label);
        $this->get(route('creators.show', $creator->public_id))->assertOk()
            ->assertDontSee('Laravel & API Engineering');
        $this->delete(route('account.profile.items.destroy', $item))->assertRedirect();
        $this->assertDatabaseMissing('creator_profile_items', ['id' => $item->id]);
    }

    public function test_hidden_creator_biography_and_items_stay_private(): void
    {
        $creator = $this->member();
        CreatorProfile::query()->create([
            'user_id' => $creator->id,
            'headline' => 'SECRET NOT PUBLISHED',
            'biography' => str_repeat('A private account biography. ', 4),
            'is_public' => false,
        ]);
        CreatorProfileItem::query()->create([
            'user_id' => $creator->id,
            'kind' => 'link', 'label' => 'Private portfolio',
            'url' => 'https://private.example.test', 'is_visible' => true,
        ]);
        MarketplaceListing::query()->create([
            'user_id' => $creator->id, 'type' => 'project',
            'title' => 'Public work title', 'description' => str_repeat('Public approved work. ', 5),
            'status' => 'published', 'currency' => 'USD',
        ]);

        $this->get(route('creators.show', $creator->public_id))->assertOk()
            ->assertDontSee('SECRET NOT PUBLISHED')
            ->assertDontSee('private.example.test')
            ->assertDontSee('Private portfolio')
            ->assertSee('Public work title');
    }

    public function test_cross_account_profile_item_access_is_denied_and_admin_can_manage(): void
    {
        $creator = $this->member();
        $other = $this->member('customer');
        $admin = $this->member('super_admin');
        $item = CreatorProfileItem::query()->create([
            'user_id' => $creator->id,
            'kind' => 'language', 'label' => 'Khmer',
            'detail' => 'Native', 'is_visible' => true,
        ]);
        $payload = ['label' => 'English', 'detail' => 'Professional', 'is_visible' => '1'];

        $this->actingAs($other)->patch(route('account.profile.items.update',$item), $payload)
            ->assertNotFound();
        $this->actingAs($other)->get(route('dashboard.users.profile.edit',$creator))
            ->assertForbidden();

        $this->actingAs($admin)->get(route('dashboard.users.profile.edit',$creator))
            ->assertOk()->assertSee('Khmer');
        $this->patch(route('dashboard.users.profile.items.update',[$creator,$item]), $payload)
            ->assertRedirect();
        $this->assertSame('English', $item->fresh()->label);
        $this->delete(route('dashboard.users.profile.items.destroy',[$creator,$item]))
            ->assertRedirect();
        $this->assertDatabaseMissing('creator_profile_items', ['id' => $item->id]);
    }

    public function test_member_may_update_private_account_profile_without_creator_status(): void
    {
        $member = $this->member('customer');
        $this->actingAs($member)->get(route('account.profile.edit'))->assertOk();
        $this->patch(route('account.profile.update'), [
            'name' => 'New Account Name', 'public_location' => 'Battambang, Cambodia',
            'availability' => 'busy',
        ])->assertRedirect();
        $this->assertSame('New Account Name', $member->fresh()->name);
        $this->assertSame('Battambang, Cambodia', $member->fresh()->userProfile->public_location);
    }
}
