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

    public function test_designated_owner_profile_exists_without_publishing_private_biography(): void
    {
        $owner=$this->creator('Bamboo Cambodia','super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        CreatorProfile::query()->create([
            'user_id'=>$owner->id,
            'headline'=>'PRIVATE HEADLINE SHOULD NOT BE PUBLIC',
            'biography'=>str_repeat('PRIVATE BIOGRAPHY IS NOT PUBLIC. ',4),
            'is_public'=>false,
        ]);

        $this->get(route('creators.show',$owner->public_id))
            ->assertOk()
            ->assertSee('Bamboo Cambodia')
            ->assertSee('Top Rated')
            ->assertSee('VIP Client Favorite')
            ->assertSee('images/badges/top-rated.svg')
            ->assertDontSee('PRIVATE HEADLINE SHOULD NOT BE PUBLIC')
            ->assertDontSee('PRIVATE BIOGRAPHY IS NOT PUBLIC')
            ->assertDontSee(CreatorBadgeAwards::OWNER_EMAIL);

        $this->get(route('creators.index'))
            ->assertOk()->assertSee('Bamboo Cambodia')
            ->assertSee('images/badges/top-rated.svg')
            ->assertDontSee('PRIVATE BIOGRAPHY IS NOT PUBLIC');
    }

    public function test_single_sidebar_replaces_duplicate_about_creator_section(): void
    {
        $owner=$this->creator('Bamboo Cambodia','super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $listing=$this->publicProject($owner,'Bamboo owner published project');
        $html=$this->get($listing->publicUrl())->assertOk()
            ->assertSee('Published by')
            ->assertDontSee('About the creator')
            ->assertSee('Bamboo Cambodia')
            ->assertSee('View creator profile')
            ->assertSee('images/badges/top-rated.svg')
            ->assertSee(route('creators.show',$owner->public_id))
            ->getContent();

        $this->assertStringNotContainsString('id="seller"',$html);
        $this->assertStringContainsString('id="publisher"',$html);
        $this->assertStringContainsString('href="#publisher"',$html);
        $this->assertSame(1,substr_count($html,'class="premium-side-card premium-side-creator"'));
        $this->assertSame(16,substr_count($html,'class="creator-badge creator-badge--'));
        $this->assertSame(16,substr_count($html,'class="creator-badge-tooltip" role="tooltip"'));
        $this->assertSame(1,substr_count($html,'images/badges/top-rated.svg'));
    }

    public function test_publisher_profile_link_works_without_published_biography_but_private_data_stays_hidden(): void
    {
        $creator=$this->creator('Independent freelancer');
        CreatorProfile::query()->create([
            'user_id'=>$creator->id,
            'headline'=>'PRIVATE UNPUBLISHED HEADLINE',
            'biography'=>str_repeat('PERSONAL PRIVATE BIOGRAPHY. ',4),
            'website_url'=>'https://private.example.test',
            'is_public'=>false,
        ]);
        $badge=CreatorBadge::query()->where('slug','skill-certified')->firstOrFail();
        CreatorBadgeGrant::query()->create([
            'user_id'=>$creator->id,
            'creator_badge_id'=>$badge->id,
            'source'=>'manual',
            'evidence_note'=>'Independent test fixture review',
        ]);
        $listing=$this->publicProject($creator,'Independent freelancer published project');

        $this->get($listing->publicUrl())
            ->assertOk()
            ->assertSee('Skill Certified')
            ->assertDontSee('About the creator')
            ->assertSee(route('creators.show',$creator->public_id))
            ->assertSee('View creator profile');

        $this->get(route('creators.show',$creator->public_id))
            ->assertOk()
            ->assertSee('Independent freelancer')
            ->assertSee('Skill Certified')
            ->assertSee('Independent freelancer published project')
            ->assertDontSee('PRIVATE UNPUBLISHED HEADLINE')
            ->assertDontSee('PERSONAL PRIVATE BIOGRAPHY')
            ->assertDontSee('private.example.test')
            ->assertDontSee($creator->email);

        // Opt-in directory remains separate; listing-derived profiles are not indexed there.
        $this->get(route('creators.index'))
            ->assertOk()->assertDontSee('Independent freelancer');
    }

    public function test_creator_without_public_biography_or_published_work_remains_private(): void
    {
        $private=$this->creator('Private talent');
        CreatorProfile::query()->create([
            'user_id'=>$private->id,
            'headline'=>'PRIVATE TITLE NOT FOR PUBLICATION',
            'biography'=>str_repeat('Personal unpublished profile content. ',4),
            'is_public'=>false,
        ]);
        $this->get(route('creators.show',$private->public_id))->assertNotFound();
        $this->get(route('creators.index'))->assertOk()
            ->assertDontSee('Private talent')
            ->assertDontSee('PRIVATE TITLE NOT FOR PUBLICATION');
    }

    public function test_unrelated_listing_does_not_inherit_designated_owner_badges(): void
    {
        $this->creator('Bamboo Cambodia','super_admin',CreatorBadgeAwards::OWNER_EMAIL);
        $other=$this->creator('Bambodia Layout Preview (Demo Creator)');
        $listing=$this->publicProject($other,'Unrelated demo creator listing');

        $this->get($listing->publicUrl())->assertOk()
            ->assertSee('Bambodia Layout Preview (Demo Creator)')
            ->assertDontSee('images/badges/top-rated.svg')
            ->assertDontSee('VIP Client Favorite');
        $this->get(route('creators.show',$other->public_id))
            ->assertOk()
            ->assertSee('Bambodia Layout Preview (Demo Creator)')
            ->assertDontSee('VIP Client Favorite');
    }

    public function test_all_sixteen_awarded_badges_render_once_in_sidebar_with_tooltips(): void
    {
        $creator=$this->creator('Samith QA');
        $badges=CreatorBadge::query()->where('enabled',true)->orderBy('sort_order')->get();
        $this->assertCount(16,$badges);
        foreach($badges as $badge) {
            CreatorBadgeGrant::query()->create([
                'user_id'=>$creator->id,
                'creator_badge_id'=>$badge->id,
                'source'=>'manual',
                'evidence_note'=>'Dedicated test fixture only; no credential verification implied',
            ]);
        }
        $listing=$this->publicProject($creator,'Full medal publication QA project');
        $response=$this->get($listing->publicUrl())->assertOk();
        $html=$response->getContent();
        $sideAt=strpos($html,'class="premium-side-card premium-side-creator"');
        $this->assertNotFalse($sideAt);
        $this->assertStringNotContainsString('id="seller"',$html);
        $this->assertStringNotContainsString('About the creator',$html);
        $this->assertSame(16,substr_count($html,'class="creator-badge creator-badge--'));
        $this->assertSame(16,substr_count($html,'class="creator-badge-tooltip" role="tooltip"'));
        $this->assertSame(1,substr_count($html,'images/badges/vip-client-favorite.svg'));
        $this->assertStringContainsString('creator-badge-row--badge',$html);
        $this->assertStringNotContainsString('class="creator-badge-name"',$html);
        $this->assertStringContainsString('aria-label="VIP Client Favorite:', $html);
        $this->assertStringContainsString('href="'.route('creators.show',$creator->public_id).'"',$html);
        $this->get(route('creators.show',$creator->public_id))->assertOk()
            ->assertSee('VIP Client Favorite')
            ->assertSee('Full medal publication QA project')
            ->assertDontSee($creator->email);
    }

    public function test_sidebar_remains_artwork_only_when_admin_enables_badge_with_name(): void
    {
        $creator=$this->creator('Display-mode QA');
        $badge=CreatorBadge::query()->where('slug','expert-vetted')->firstOrFail();
        CreatorBadgeGrant::query()->create([
            'user_id'=>$creator->id,
            'creator_badge_id'=>$badge->id,
            'source'=>'manual',
            'evidence_note'=>'Independent test fixture',
        ]);
        $admin=$this->creator('System Admin','admin');
        $this->actingAs($admin)->patch(route('dashboard.badges.display.update'),[
            'display_mode'=>'badge_name','badge_size'=>'standard','tooltips_enabled'=>1,
        ])->assertRedirect();
        $listing=$this->publicProject($creator,'Display-mode QA listing');
        $html=$this->get($listing->publicUrl())->assertOk()->getContent();
        $this->assertStringContainsString('creator-badge-row--badge',$html);
        $this->assertStringNotContainsString('class="creator-badge-name"',$html);
        $this->assertStringContainsString('aria-label="Expert Vetted:', $html);
        $this->assertStringContainsString('class="creator-badge-tooltip" role="tooltip"',$html);
        // Standalone profile follows the selected mode.
        $profile=$this->get(route('creators.show',$creator->public_id))->assertOk()->getContent();
        $this->assertStringContainsString('class="creator-badge-name"',$profile);
    }
}
