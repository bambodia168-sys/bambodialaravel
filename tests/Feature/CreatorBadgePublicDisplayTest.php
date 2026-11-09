<?php

namespace Tests\Feature;

use App\Models\CreatorBadge;
use App\Models\CreatorBadgeGrant;
use App\Models\CreatorProfile;
use App\Models\MarketplaceListing;
use App\Models\User;
use App\Support\CreatorBadgeAwards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatorBadgePublicDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function creator(string $name, string $role = 'freelancer', ?string $email = null): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email ?? uniqid('creator-badges-', true).'@example.test',
            'password' => 'LongUniquePassword123!',
            'role' => $role,
        ]);
    }

    private function publicProject(User $creator, string $title): MarketplaceListing
    {
        return MarketplaceListing::query()->create([
            'user_id' => $creator->id,
            'type' => 'project',
            'title' => $title,
            'description' => str_repeat('An independently approved marketplace sample. ', 4),
            'status' => 'published',
            'currency' => 'USD',
        ]);
    }

    public function test_owner_public_badge_profile_exists_without_publishing_private_biography(): void
    {
        $owner = $this->creator('Bamboo Cambodia', 'super_admin', CreatorBadgeAwards::OWNER_EMAIL);
        CreatorProfile::query()->create([
            'user_id' => $owner->id,
            'headline' => 'PRIVATE HEADLINE SHOULD NOT BE PUBLIC',
            'biography' => str_repeat('PRIVATE BIOGRAPHY IS NOT PUBLIC. ', 4),
            'is_public' => false,
        ]);

        $this->get(route('creators.show', $owner->public_id))
            ->assertOk()
            ->assertSee('Bamboo Cambodia')
            ->assertSee('Top Rated')
            ->assertSee('VIP Client Favorite')
            ->assertSee('images/badges/top-rated.svg')
            ->assertDontSee('PRIVATE HEADLINE SHOULD NOT BE PUBLIC')
            ->assertDontSee('PRIVATE BIOGRAPHY IS NOT PUBLIC')
            ->assertDontSee(CreatorBadgeAwards::OWNER_EMAIL);

        $this->get(route('creators.index'))
            ->assertOk()
            ->assertSee('Bamboo Cambodia')
            ->assertSee('images/badges/top-rated.svg')
            ->assertDontSee('PRIVATE BIOGRAPHY IS NOT PUBLIC');
    }

    public function test_badges_display_on_correct_listing_publisher_even_without_public_biography(): void
    {
        $owner = $this->creator('Bamboo Cambodia', 'super_admin', CreatorBadgeAwards::OWNER_EMAIL);
        $listing = $this->publicProject($owner, 'Bamboo owner published project');

        $this->get($listing->publicUrl())
            ->assertOk()
            ->assertSee('About the creator')
            ->assertSee('Published by')
            ->assertSee('Bamboo Cambodia')
            ->assertSee('images/badges/top-rated.svg')
            ->assertSee('View public profile');
    }

    public function test_unrelated_listing_does_not_inherit_designated_owner_badges(): void
    {
        $this->creator('Bamboo Cambodia', 'super_admin', CreatorBadgeAwards::OWNER_EMAIL);
        $otherCreator = $this->creator('Bambodia Layout Preview (Demo Creator)');
        $listing = $this->publicProject($otherCreator, 'Unrelated demo creator listing');

        $this->get($listing->publicUrl())
            ->assertOk()
            ->assertSee('Bambodia Layout Preview (Demo Creator)')
            ->assertDontSee('images/badges/top-rated.svg')
            ->assertDontSee('VIP Client Favorite');
        $this->get(route('creators.show', $otherCreator->public_id))->assertNotFound();
    }

    public function test_manual_badge_is_visible_for_publisher_without_public_biography(): void
    {
        $creator = $this->creator('Independent freelancer');
        $badge = CreatorBadge::query()->where('slug', 'skill-certified')->firstOrFail();
        CreatorBadgeGrant::query()->create([
            'user_id' => $creator->id,
            'creator_badge_id' => $badge->id,
            'source' => 'manual',
            'evidence_note' => 'Independent assessment record',
        ]);
        $listing = $this->publicProject($creator, 'Independent freelance listing');

        $this->get($listing->publicUrl())
            ->assertOk()
            ->assertSee('Skill Certified')
            ->assertSee('images/badges/skill-certified.svg')
            ->assertDontSee('View public profile');

        $this->get(route('creators.show', $creator->public_id))->assertNotFound();
    }

    public function test_normal_creator_private_profile_remains_private(): void
    {
        $private = $this->creator('Private talent');
        CreatorProfile::query()->create([
            'user_id' => $private->id,
            'headline' => 'PRIVATE TITLE NOT FOR PUBLICATION',
            'biography' => str_repeat('Personal unpublished profile content. ', 4),
            'is_public' => false,
        ]);
        $this->get(route('creators.show', $private->public_id))->assertNotFound();
        $this->get(route('creators.index'))
            ->assertOk()->assertDontSee('PRIVATE TITLE NOT FOR PUBLICATION');
    }
}
