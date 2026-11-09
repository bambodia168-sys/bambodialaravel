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
    public function test_all_sixteen_awarded_badges_render_in_both_listing_sections_without_truncation(): void
    {
        $creator=$this->creator('Samith QA', 'freelancer');
        $badges=CreatorBadge::query()->where('enabled',true)->orderBy('sort_order')->get();
        $this->assertCount(16,$badges);
        foreach($badges as $badge) {
            CreatorBadgeGrant::query()->create([
                'user_id'=>$creator->id,
                'creator_badge_id'=>$badge->id,
                'source'=>'manual',
                'evidence_note'=>'Dedicated test fixture only; no actual credential verification.',
            ]);
        }
        $listing=$this->publicProject($creator,'Full medal publication QA project');
        $response=$this->get($listing->publicUrl())->assertOk();
        $html=$response->getContent();
        $this->assertSame('badge',
            \App\Support\CreatorBadgeDisplay::settings()->display_mode);
        $sellerStart=strpos($html,'id="seller"');
        $sidebarStart=strpos($html,'premium-side-card premium-side-creator');
        $this->assertNotFalse($sellerStart);
        $this->assertNotFalse($sidebarStart);
        $seller=substr($html,$sellerStart, $sidebarStart-$sellerStart);
        $sidebar=substr($html,$sidebarStart);

        $this->assertSame(16,substr_count($seller,'class="creator-badge creator-badge--'));
        $this->assertSame(16,substr_count($sidebar,'class="creator-badge creator-badge--'));
        $this->assertStringContainsString('creator-badge-row--badge',$seller);
        $this->assertStringContainsString('creator-badge-row--badge',$sidebar);
        $this->assertStringNotContainsString('class="creator-badge-name"',$seller);
        $this->assertStringNotContainsString('class="creator-badge-name"',$sidebar);
        $this->assertSame(32,substr_count($html,'class="creator-badge-tooltip" role="tooltip"'));
        $this->assertSame(2,substr_count($html,'images/badges/vip-client-favorite.svg'));
        $this->assertStringContainsString('aria-label="VIP Client Favorite:', $html);
        $this->assertStringNotContainsString('View the creator profile for all', $html);
    }

    public function test_sidebar_remains_artwork_only_when_admin_enables_badge_with_name(): void
    {
        $creator=$this->creator('Display-mode QA');
        $badge=CreatorBadge::query()->where('slug','expert-vetted')->firstOrFail();
        CreatorBadgeGrant::query()->create([
            'user_id'=>$creator->id,'creator_badge_id'=>$badge->id,
            'source'=>'manual','evidence_note'=>'Independent test-only assessment.',
        ]);
        $admin=$this->creator('System Admin','admin');
        $this->actingAs($admin)->patch(route('dashboard.badges.display.update'),[
            'display_mode'=>'badge_name','badge_size'=>'standard','tooltips_enabled'=>1,
        ])->assertRedirect();
        $listing=$this->publicProject($creator,'Display-mode QA listing');
        $html=$this->get($listing->publicUrl())->assertOk()->getContent();
        $start=strpos($html,'id="seller"');
        $side=strpos($html,'premium-side-card premium-side-creator');
        $main=substr($html,$start,$side-$start);
        $sidebar=substr($html,$side);
        $this->assertStringContainsString('class="creator-badge-name"',$main);
        $this->assertStringNotContainsString('class="creator-badge-name"',$sidebar);
        $this->assertStringContainsString('creator-badge-row--badge',$sidebar);
        $this->assertStringContainsString('aria-label="Expert Vetted:', $sidebar);
        $this->assertStringContainsString('class="creator-badge-tooltip" role="tooltip"',$sidebar);
    }

}
