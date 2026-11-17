<?php

namespace Tests\Feature;

use App\Models\ContentEntry;
use App\Models\MediaAsset;
use App\Models\SiteBrandAsset;
use App\Models\SiteLanguage;
use App\Models\SiteSeoPage;
use App\Models\SiteSetting;
use App\Models\SiteSocialLink;
use App\Models\SiteTranslationEntry;
use App\Models\SiteTypographyRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminSiteControlCenterTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role='super_admin'): User
    {
        return User::query()->create([
            'name'=>'Control Test '.$role,
            'email'=>uniqid($role,true).'@example.test',
            'password'=>'BrowserOnlyStrongPassword123!',
            'role'=>$role,
        ]);
    }

    public function test_identity_updates_actual_public_title_meta_and_manifest(): void
    {
        $admin=$this->actor();
        $this->actingAs($admin)->get(route('dashboard.site-identity'))
            ->assertOk()->assertSee('Brand assets')->assertSee('Site name');

        $this->patch(route('dashboard.site-identity.update'),[
            'key'=>'identity.name','value'=>'Bambodia Creative Hub',
        ])->assertRedirect();
        $this->patch(route('dashboard.site-identity.update'),[
            'key'=>'identity.tagline','value'=>'Products, jobs and freelance services',
        ])->assertRedirect();
        $this->get('/')->assertOk()
            ->assertSee('Bambodia Creative Hub')
            ->assertSee('site.webmanifest');
        $this->get('/site.webmanifest')->assertOk()
            ->assertJsonPath('name','Bambodia Creative Hub')
            ->assertJsonPath('description','Products, jobs and freelance services');
        $this->assertSame('Bambodia Creative Hub',SiteSetting::setting('identity.name'));
        $this->assertDatabaseHas('audit_events',['event'=>'site.setting.changed','actor_id'=>$admin->id]);
    }

    public function test_localized_identity_is_editable_and_defaults_cover_the_language_catalog(): void
    {
        $this->actingAs($this->actor())->get(route('dashboard.site-identity'))->assertOk()
            ->assertSee('Localized site identity')->assertSee('Shared fallback image');
        $defaults = require lang_path('site-identity.php');
        $this->assertSame(array_values(array_diff(array_keys(SiteLanguage::CATALOG), ['en'])), array_keys($defaults));

        foreach (SiteLanguage::activeCodes() as $locale) {
            $identity = \App\Support\SiteIdentity::metadata($locale);
            $this->get('/'.$locale)->assertOk()->assertSee('<title>'.e($identity['title']).'</title>', false)
                ->assertSee('content="'.e($identity['description']).'"', false)
                ->assertSee('images/bambodia-social-sharing.jpg');
            $this->get('/'.$locale.'/site.webmanifest')->assertOk()->assertJsonPath('lang', $locale)
                ->assertJsonPath('description', $identity['tagline']);
        }
        $change = ['locale'=>'km','tagline'=>'ស្លាកសម្គាល់ថ្មី','title'=>'Bambodia · ចំណងជើងថ្មី',
            'description'=>'ការពិពណ៌នាថ្មីសម្រាប់គេហទំព័រ'];
        $this->patch(route('dashboard.site-identity.update'), $change)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/km')->assertOk()->assertSee('<title>'.$change['title'].'</title>', false)->assertSee($change['description']);
        $this->get('/en')->assertOk()->assertDontSee($change['title']);
        $this->get('/km/site.webmanifest')->assertOk()->assertJsonPath('description', $change['tagline']);
        $this->patch(route('dashboard.site-identity.update'), ['locale' => 'invalid'])
            ->assertSessionHasErrors('locale');
    }

    public function test_featured_image_precedes_language_and_shared_social_fallbacks(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $admin = $this->actor();
        $this->actingAs($admin);
        $imageRoute = route('dashboard.site-identity.assets.store');
        $fixture = public_path('images/bambodia-social-sharing.jpg');
        foreach (['social_shared', 'social_km'] as $slot) {
            $this->post($imageRoute, ['slot' => $slot,
                'image' => new UploadedFile($fixture, 'share.jpg', 'image/jpeg', null, true)])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $shared = Storage::disk('public')->url(SiteBrandAsset::query()->findOrFail('social_shared')->storage_path);
        $khmer = Storage::disk('public')->url(SiteBrandAsset::query()->findOrFail('social_km')->storage_path);
        $tag = fn (string $url) => '<meta property="og:image" content="'.e($url).'">';
        $this->get('/km')->assertOk()->assertSee($tag($khmer), false);
        $this->get('/zh')->assertOk()->assertSee($tag($shared), false);

        Storage::disk('local')->put('site-test/featured.jpg', file_get_contents($fixture));
        $asset = MediaAsset::query()->create([
            'user_id' => $admin->id, 'original_name' => 'featured.jpg', 'storage_path' => 'site-test/featured.jpg',
            'mime_type' => 'image/jpeg', 'bytes' => filesize($fixture), 'width' => 1200, 'height' => 630,
            'alt_text' => 'Featured artwork', 'rights_basis' => 'owned', 'rights_holder' => $admin->name,
            'review_status' => 'approved',
        ]);
        $post = ContentEntry::query()->create(['type'=>'post','title'=>'Social sharing example','body'=>'Public post',
            'author_id'=>$admin->id,'status'=>'published','published_at'=>now(),
            'featured_type'=>'image','featured_media_id'=>$asset->id]);
        $path = '/'.$post->public_id.'_'.$post->slug;
        $featuredUrl = route('localized.km.media.preview', $asset->public_id);
        $this->get('/km'.$path)->assertOk()->assertSee($tag($featuredUrl), false)
            ->assertSee('<meta name="twitter:image" content="'.e($featuredUrl).'">', false);
        auth()->logout();
        $this->get($featuredUrl)->assertOk();
        $this->actingAs($admin)->delete(route('dashboard.site-identity.assets.destroy', 'social_km'))->assertRedirect();
        $this->get('/km')->assertOk()->assertSee($tag($shared), false);
    }

    public function test_social_image_uploads_use_a_two_megabyte_limit_with_flexible_dimensions(): void
    {
        Storage::fake('public');
        $this->actingAs($this->actor())->get(route('dashboard.site-identity'))->assertOk()
            ->assertSee('PNG, JPEG, or WebP · 1,200 × 630 px recommended · up to 2 MB');
        $upload = route('dashboard.site-identity.assets.store');
        $smallImage = file_get_contents(public_path('favicon.png'));
        foreach (['social_shared', 'social_km'] as $slot) {
            $this->post($upload, ['slot' => $slot, 'image' => UploadedFile::fake()
                ->createWithContent('sharing.png', $smallImage)->size(2048)])
                ->assertRedirect()->assertSessionHasNoErrors();
            $saved = SiteBrandAsset::query()->findOrFail($slot);
            $this->post($upload, ['slot' => $slot, 'image' => UploadedFile::fake()
                ->createWithContent('sharing.png', $smallImage)->size(2049)])
                ->assertSessionHasErrors('image');
            $this->assertSame($saved->storage_path, $saved->fresh()->storage_path);
        }
    }

    public function test_brand_asset_upload_replace_and_reset_work_in_public_layout(): void
    {
        Storage::fake('public');
        $admin=$this->actor();
        // The repository's real PNG fixture avoids reliance on the GD extension in CI.
        $file=new UploadedFile(public_path('favicon.png'), 'logo.png', 'image/png', null, true);
        $this->actingAs($admin)->post(route('dashboard.site-identity.assets.store'),[
            'slot'=>'logo_light','image'=>$file,
        ])->assertRedirect();

        $asset=SiteBrandAsset::query()->findOrFail('logo_light');
        Storage::disk('public')->assertExists($asset->storage_path);
        $this->get('/')->assertOk()
            ->assertSee(Storage::disk('public')->url($asset->storage_path), false);

        $this->delete(route('dashboard.site-identity.assets.destroy','logo_light'))
            ->assertRedirect();
        $this->assertDatabaseMissing('site_brand_assets',['slot'=>'logo_light']);
        Storage::disk('public')->assertMissing($asset->storage_path);
        $this->get('/')->assertSee('images/bambodia-logo.webp');
    }

    public function test_page_seo_crud_changes_real_public_meta_and_removes_it_cleanly(): void
    {
        $admin=$this->actor();
        $this->actingAs($admin)->get(route('dashboard.seo-settings'))
            ->assertOk()->assertSee('Global SEO defaults');
        $data=[
            'path'=>'/','locale'=>'en','title'=>'Independent Design Marketplace',
            'description'=>'Professional licensed marketplace for carefully reviewed digital work.',
            'keywords'=>'design, marketplace','is_indexable'=>'0','is_active'=>'1',
        ];
        $this->post(route('dashboard.seo.pages.store'),$data)->assertRedirect();
        $page=SiteSeoPage::query()->firstOrFail();
        $this->get('/')->assertOk()
            ->assertSee('<title>Independent Design Marketplace</title>',false)
            ->assertSee('content="noindex,nofollow"',false)
            ->assertSee('Professional licensed marketplace');

        $this->patch(route('dashboard.seo.pages.update',$page),array_merge($data,[
            'title'=>'Updated Independent Design Marketplace',
            'is_indexable'=>'1',
        ]))->assertRedirect();
        $this->get('/')->assertSee('Updated Independent Design Marketplace')
            ->assertSee('content="index,follow"',false);

        $this->delete(route('dashboard.seo.pages.destroy',$page))->assertRedirect();
        $this->get('/')->assertDontSee('Updated Independent Design Marketplace');
        $this->assertDatabaseHas('audit_events',['event'=>'site.seo.deleted']);
    }

    public function test_seo_overrides_cannot_target_private_routes_or_other_canonical_hosts(): void
    {
        $admin=$this->actor();
        $this->actingAs($admin)->post(route('dashboard.seo.pages.store'),[
            'path'=>'/dashboard','locale'=>'en','title'=>'Malicious Dashboard Index',
            'description'=>'A forbidden metadata override for the private administration.',
            'is_indexable'=>'1','is_active'=>'1',
        ])->assertStatus(422);

        $this->post(route('dashboard.seo.pages.store'),[
            'path'=>'/','locale'=>'en','title'=>'Canonical Override Example',
            'description'=>'This is a long enough search engine description.',
            'canonical_url'=>'https://not-our-domain.example/path',
            'is_indexable'=>'1','is_active'=>'1',
        ])->assertStatus(422);
    }

    public function test_footer_social_full_crud_updates_the_actual_footer(): void
    {
        $admin=$this->actor();
        $this->actingAs($admin)->get(route('dashboard.social.index'))
            ->assertOk()->assertSee('Footer Social Links');
        $this->post(route('dashboard.social.store'),[
            'platform'=>'website','label'=>'Community homepage',
            'url'=>'https://community.example.org/','sort_order'=>1,'is_active'=>'1',
        ])->assertRedirect();
        $link=SiteSocialLink::query()->where('label','Community homepage')->firstOrFail();
        $this->get('/')->assertSee('https://community.example.org/',false);

        $this->patch(route('dashboard.social.update',$link),[
            'platform'=>'website','label'=>'Community homepage',
            'url'=>'https://community.example.org/','sort_order'=>1,'is_active'=>'0',
        ])->assertRedirect();
        $this->get('/')->assertDontSee('https://community.example.org/');

        $this->delete(route('dashboard.social.destroy',$link))->assertRedirect();
        $this->assertDatabaseMissing('site_social_links',['id'=>$link->id]);
    }

    public function test_languages_copy_english_and_override_words_with_fallback(): void
    {
        $admin=$this->actor();
        $this->actingAs($admin)->get(route('dashboard.languages.index'))
            ->assertOk()->assertSee('Language Manager');
        $this->post(route('dashboard.languages.store'),['code'=>'fr'])->assertRedirect();
        $this->assertDatabaseHas('site_languages',['code'=>'fr','is_enabled'=>true]);
        $this->assertDatabaseHas('site_translation_entries',[
            'language_code'=>'fr','translation_key'=>'marketplace','is_translated'=>false,
        ]);
        $this->get('/fr')->assertOk()->assertSee('Marketplace');

        $this->patch(route('dashboard.languages.translate','fr'),[
            'key'=>'marketplace','value'=>'Marché numérique',
        ])->assertRedirect();
        $this->get('/fr')->assertOk()->assertSee('Marché numérique');

        $this->patch(route('dashboard.languages.translate','fr'),[
            'key'=>'marketplace','value'=>'',
        ])->assertRedirect();
        $this->get('/fr')->assertOk()->assertSee('Marketplace');
        $this->assertDatabaseHas('site_translation_entries',[
            'language_code'=>'fr','translation_key'=>'marketplace','is_translated'=>false,
        ]);

        $this->patch(route('dashboard.languages.update','fr'),['is_enabled'=>'0'])->assertRedirect();
        $this->get('/fr')->assertNotFound();
    }

    public function test_typography_can_be_assigned_per_language_and_deleted(): void
    {
        $admin=$this->actor();
        $this->actingAs($admin)->get(route('dashboard.typography.index'))
            ->assertOk()->assertSee('Typography');
        $this->post(route('dashboard.typography.store'),[
            'language_code'=>'en','element'=>'button','font_family'=>'georgia',
            'font_size'=>16,'font_weight'=>600,'line_height'=>1.5,'alignment'=>'center',
        ])->assertRedirect();

        $rule=SiteTypographyRule::query()->firstOrFail();
        $this->get('/')->assertOk()
            ->assertSee('id="site-language-typography"',false)
            ->assertSee('font-family: Georgia,serif',false);
        $this->patch(route('dashboard.typography.update',$rule),[
            'language_code'=>'en','element'=>'button','font_family'=>'arial',
            'font_size'=>17,'font_weight'=>700,'line_height'=>1.6,'alignment'=>'right',
        ])->assertRedirect();
        $this->get('/')->assertSee('font-size: 17px',false);

        $this->delete(route('dashboard.typography.destroy',$rule))->assertRedirect();
        $this->get('/')->assertDontSee('id="site-language-typography"',false);
    }

    public function test_customers_and_staff_cannot_change_site_controls(): void
    {
        $customer=$this->actor('customer');
        $this->actingAs($customer)->get(route('dashboard.site-identity'))->assertForbidden();
        $this->get(route('dashboard.seo-settings'))->assertForbidden();
        $this->get(route('dashboard.social.index'))->assertForbidden();
        $this->get(route('dashboard.languages.index'))->assertForbidden();
        $this->get(route('dashboard.typography.index'))->assertForbidden();

        $this->patch(route('dashboard.site-identity.update'),[
            'key'=>'identity.name','value'=>'Hacked Site Name',
        ])->assertForbidden();
        $this->post(route('dashboard.languages.store'),['code'=>'fr'])->assertForbidden();

        $staff=$this->actor('staff');
        $this->actingAs($staff)->get(route('dashboard.seo-settings'))->assertForbidden();
    }
}
