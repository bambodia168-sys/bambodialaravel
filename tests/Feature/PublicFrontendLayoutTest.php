<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicFrontendLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function creator(): User
    {
        $user=User::query()->create([
            'name'=>'Original Public Creator',
            'email'=>'original-public-creator@example.test',
            'password'=>'StrongOriginalCreatorPassword123!',
            'role'=>'freelancer',
        ]);
        CreatorProfile::query()->create([
            'user_id'=>$user->id,
            'headline'=>'Independent original design and engineering',
            'biography'=>str_repeat('This public creator independently offers original work. ',3),
            'is_public'=>true,
        ]);
        return $user;
    }

    private function listing(User $creator,string $type): MarketplaceListing
    {
        $attributes=[
            'user_id'=>$creator->id,
            'type'=>$type,
            'title'=>'Original '.ucfirst($type).' Listing Layout',
            'description'=>str_repeat('A real approved marketplace listing with independent original content. ',3),
            'status'=>'published',
            'price'=>'75.00',
            'currency'=>'USD',
        ];
        if($type==='product'){
            $license=LicenseDefinition::query()->create([
                'code'=>'ORIGINAL_LAYOUT','name'=>'Original QA Layout License','version'=>'1.0.0',
                'permissions'=>str_repeat('Authorized original QA testing only. ',3),
                'restrictions'=>str_repeat('No fake license transactions. ',3),
                'status'=>'active','published_at'=>now(),'created_by'=>$creator->id,
            ]);
            $asset=MediaAsset::query()->create([
                'user_id'=>$creator->id,'original_name'=>'original-qa.png',
                'storage_path'=>'qa-original.png','mime_type'=>'image/png','bytes'=>68,
                'width'=>1,'height'=>1,'alt_text'=>'Approved creator-owned original product screenshot',
                'rights_basis'=>'owned','rights_holder'=>'Original Public Creator',
                'review_status'=>'approved','reviewed_at'=>now(),
            ]);
            $attributes['license_definition_id']=$license->id;
            $attributes['cover_media_id']=$asset->id;
            $attributes['license_terms']='Independent original license terms.';
        }
        return MarketplaceListing::query()->create($attributes);
    }

    public function test_homepage_freelancer_avatar_uses_uploaded_public_profile_image_and_creator_link(): void
    {
        Storage::fake('public');
        $creator=$this->creator();
        $path='profile-avatars/qa-creator.png';
        Storage::disk('public')->put($path, 'test-image-contents');
        UserProfile::query()->create([
            'user_id'=>$creator->id,
            'avatar_path'=>$path,
        ]);

        $response=$this->get('/')->assertOk()
            ->assertSee('class="marketplace-freelancer-avatar" href="'.route('creators.show',$creator->public_id).'"', false)
            ->assertSee('src="'.Storage::disk('public')->url($path).'"', false)
            ->assertSee('alt="'.$creator->name.' profile photo"', false)
            ->assertSee('View '.$creator->name.' creator profile')
            ->assertDontSee('Public profile')
            ->assertDontSee('View portfolio')
            ->assertDontSee('marketplace-freelancer-visibility', false);

        $this->assertSame(1, substr_count($response->getContent(), 'class="marketplace-freelancer-avatar"'));
        $this->get(route('creators.show',$creator->public_id))->assertOk()
            ->assertSee(Storage::disk('public')->url($path));
    }

    public function test_homepage_freelancer_avatar_falls_back_to_initial_without_uploaded_image(): void
    {
        $creator=$this->creator();
        $response=$this->get('/')->assertOk();
        $html=$response->getContent();
        $this->assertStringContainsString('class="marketplace-freelancer-avatar" href="'.route('creators.show',$creator->public_id).'"', $html);
        $this->assertStringContainsString('aria-hidden="true">O</span>', $html);
        $this->assertStringNotContainsString('Public profile</span>', $html);
        $this->assertStringNotContainsString('View portfolio', $html);
        $this->assertStringNotContainsString($creator->name.' profile photo', $html);
    }

    public function test_homepage_type_directory_links_to_real_published_results(): void
    {
        $creator=$this->creator();
        foreach(MarketplaceListing::TYPES as $type){
            $this->listing($creator,$type);
        }
        $this->get('/')->assertOk()
            ->assertSee('marketplace-type-directory')
            ->assertSee('Find the work that fits')
            ->assertSee('4',false);
        foreach(MarketplaceListing::TYPES as $type){
            $this->get('/search?type='.$type)->assertOk()
                ->assertSee('marketplace-browse-layout')
                ->assertSee('marketplace-filter-sidebar')
                ->assertSee('Original '.ucfirst($type).' Listing Layout')
                ->assertDontSee('Original '.ucfirst(
                    $type==='product'?'service':'product'
                ).' Listing Layout');
        }
    }

    public function test_all_four_detail_types_render_shared_premium_layout_with_real_sidebar_data(): void
    {
        $creator=$this->creator();
        foreach(MarketplaceListing::TYPES as $type){
            $listing=$this->listing($creator,$type);
            $response=$this->get($listing->publicUrl());
            $response->assertOk()
                ->assertSee('premium-detail-layout')
                ->assertSee('premium-detail-tabs')
                ->assertSee('premium-detail-sidebar')
                ->assertSee('Reference')
                ->assertSee($listing->public_id)
                ->assertSee('premium-detail-below-title')
                ->assertDontSee('marketplace-breadcrumb')
                ->assertSee('Original Public Creator')
                ->assertSee('View creator profile')
                ->assertDontSee('About the creator')
                ->assertSee('USD')
                ->assertSee('Information')
                ->assertDontSee('Buy Now')
                ->assertDontSee('Quality checked by Codester')
                ->assertDontSee('Lowest price guarantee');
            if($type==='product'){
                $response->assertSee('premium-showcase-cover')
                    ->assertSee('Product license')
                    ->assertSee('Original QA Layout License')
                    ->assertSee('Read full license')
                    ->assertSee('Direct checkout is not enabled')
                    ->assertDontSee('request-service');
            } else {
                $response->assertSee('premium-showcase-placeholder')
                    ->assertSee('How it works')
                    ->assertSee('Sign in to contact');
                $client=User::query()->create([
                    'name'=>'Original Customer '.$type,
                    'email'=>'public-customer-'.$type.'@example.test',
                    'password'=>'TestCustomerPassword123!',
                    'role'=>'customer',
                ]);
                $this->actingAs($client)->get($listing->publicUrl())->assertOk()
                    ->assertSee('id="request-service"',false)
                    ->assertSee(route('inquiries.store',$listing),false); // working private inquiry action
                $this->post('/logout')->assertRedirect();
            }
        }
    }

    public function test_unapproved_product_never_enters_public_detail_or_search(): void
    {
        $creator=$this->creator();
        $private=MarketplaceListing::query()->create([
            'user_id'=>$creator->id,'type'=>'product',
            'title'=>'Unlicensed Restricted Product Private',
            'description'=>str_repeat('Private unlicensed content must remain hidden. ',3),
            'status'=>'published','price'=>'15.00',
        ]);
        $this->get($private->publicUrl())->assertNotFound();
        $this->get('/search?type=product')->assertOk()
            ->assertDontSee('Unlicensed Restricted Product Private');
        $this->get('/')->assertOk()->assertDontSee('Unlicensed Restricted Product Private');
    }


    public function test_public_header_uses_category_search_language_and_profile_without_redundant_links(): void
    {
        $response = $this->get('/')->assertOk();
        $html = $response->getContent();
        $header = explode('</header>', explode('<header class="site-header">', $html, 2)[1], 2)[0];
        $footer = explode('</footer>', explode('<footer class="site-footer">', $html, 2)[1], 2)[0];

        $this->assertStringContainsString('data-public-category-menu', $header);
        $this->assertStringContainsString('data-public-mobile-search', $header);
        $this->assertStringContainsString('class="nav-search"', $header);
        $this->assertStringContainsString('nav-language-menu', $header);
        $this->assertStringContainsString('class="profile-trigger"', $header);
        $this->assertStringNotContainsString('nav-marketplace-links', $header);
        $this->assertStringNotContainsString('<nav class="nav-marketplace-links"', $header);
        $this->assertStringContainsString('Product', $header);
        $this->assertStringContainsString('Project', $header);
        $this->assertStringContainsString('Service', $header);
        $this->assertStringContainsString('Freelance', $header);

        $this->assertLessThan(strpos($header, 'data-public-category-menu'), strpos($header, 'class="brand"'));
        $this->assertLessThan(strpos($header, 'data-public-mobile-search'), strpos($header, 'data-public-category-menu'));
        $this->assertLessThan(strpos($header, 'nav-language-menu'), strpos($header, 'data-public-mobile-search'));
        $this->assertLessThan(strpos($header, 'profile-trigger'), strpos($header, 'nav-language-menu'));
        $this->assertSame(1, substr_count($header, 'nav-language-menu'));

        $this->assertStringContainsString('action="'.route('locale.update').'"', $header);
        $this->assertStringContainsString('name="locale"', $header);
        $this->assertStringNotContainsString('class="language-menu"', $footer);
        $this->assertStringContainsString('class="footer-social"', $footer);
        $this->assertStringContainsString('footer-columns', $footer);
    }

    public function test_language_switcher_remains_functional_from_the_public_header(): void
    {
        $this->get('/')->assertOk()->assertSee('English')->assertSee('nav-language-menu', false);
        $this->post(route('locale.update'), ['locale' => 'km'])->assertRedirect();
        $this->get('/')->assertOk()->assertSee('ខ្មែរ')
            ->assertSee('name="locale" value="km"', false)
            ->assertSee('nav-language-menu', false);
    }


    public function test_footer_reuses_header_brand_and_preserves_copyright_social_row_with_back_to_top(): void
    {
        $response = $this->get('/')->assertOk();
        $html = $response->getContent();
        $header = explode('</header>', explode('<header class="site-header">', $html, 2)[1], 2)[0];
        $footer = explode('</footer>', explode('<footer class="site-footer">', $html, 2)[1], 2)[0];

        $this->assertSame(2, substr_count($html, 'images/bambodia-logo.webp'));
        $this->assertSame(2, substr_count($html, 'images/bambodia-logo-dark.svg'));
        $this->assertStringContainsString('brand-logo-pair', $header);
        $this->assertStringContainsString('class="brand footer-brand"', $footer);
        $this->assertStringContainsString('class="brand-logo-light"', $footer);
        $this->assertStringContainsString('class="brand-logo-dark"', $footer);
        $this->assertStringNotContainsString('class="brand-mark"', $footer);
        $this->assertStringContainsString('class="footer-copyright"', $footer);
        $this->assertStringContainsString('© '.date('Y').' Bambodia', $footer);
        $socialContents = explode('</div>', explode('<div class="footer-social"', $footer, 2)[1], 2)[0];
        $this->assertSame(2, substr_count($socialContents, '<a '));
        $this->assertStringContainsString('aria-label="Author website"', $footer);
        $this->assertStringContainsString('aria-label="Bambodia GitHub repository"', $footer);
        $this->assertStringContainsString('class="back-to-top"', $html);
        $this->assertStringContainsString('data-back-to-top', $html);
        $this->assertStringContainsString('aria-label="Back to top"', $html);
        $backToTopMarkup = explode('</button>', explode('data-back-to-top', $html, 2)[1], 2)[0];
        $this->assertStringContainsString('<svg', $backToTopMarkup);
        $this->assertStringNotContainsString('back-to-top-label', $backToTopMarkup);
        $this->assertStringContainsString('header-flag-only', $header);
        $this->assertStringNotContainsString('nav-marketplace-links', $header);
    }
}
