<?php

namespace Tests\Feature;

use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        return User::query()->create([
            'name' => 'Builder '.$role,
            'email' => uniqid($role, true).'@example.test',
            'password' => 'SecureBuilderPassword123!',
            'role' => $role,
        ]);
    }

    private function agreed(): array
    {
        return [
            'builder_mode' => '1',
            'accept_terms' => '1',
            'terms_sha256' => \App\Support\BuilderSubmissionTerms::digest(),
        ];
    }

    private function jobData(): array
    {
        return [
            'title' => 'Build a secure Laravel marketplace application',
            'description' => str_repeat('Create and test a maintainable Laravel marketplace for real customers. ', 2),
            'category' => 'development',
            'budget' => '1800.00',
        ];
    }

    public function test_builder_requires_login_and_shows_only_authorized_types(): void
    {
        $this->get('/builder')->assertRedirect('/login');

        $this->actingAs($this->member('freelancer'))->get('/builder')
            ->assertOk()->assertSee('Product')->assertSee('Freelance')->assertDontSee('Create job');
        $this->get('/builder?type=product')->assertOk()
            ->assertSee('name="license_definition_id"', false)
            ->assertSee('name="cover_media_id"', false);
        $this->get('/builder?type=job')->assertForbidden();

        $this->actingAs($this->member('customer'))->get('/builder')
            ->assertOk()->assertSee('Job')->assertDontSee('Create product');
        $this->get('/builder?type=job')->assertOk()->assertSee('name="budget"', false);
        $this->get('/builder?type=product')->assertForbidden();

        $this->actingAs($this->member('super_admin'))->get('/builder')
            ->assertOk()->assertSee('Product')->assertSee('Job');
        $this->get('/builder?type=wrong')->assertNotFound();
        $this->get('/builder?=not-a-public-id')->assertNotFound();
    }

    public function test_creation_redirects_to_the_single_builder_public_id_url(): void
    {
        $creator = $this->member('freelancer');
        $this->actingAs($creator)->post('/studio/listings', [
            ...$this->agreed(),
            'type' => 'service',
            'title' => 'Professional Laravel security review',
            'description' => str_repeat('A professional audit with a complete report and recommendations. ', 2),
        ])->assertRedirect();

        $listing = MarketplaceListing::query()->firstOrFail();
        $this->assertSame('pending', $listing->status);
        $this->get('/builder?='.$listing->public_id)->assertOk()
            ->assertSee('Professional Laravel security review')
            ->assertSee('Public ID');
        $this->get('/studio/listings')->assertSee('/builder?='.$listing->public_id);

        $this->post('/studio/products', [
            ...$this->agreed(),
            'title' => 'Original reusable digital interface',
            'description' => str_repeat('Original assets and documented features for a licensed project. ', 2),
            'status' => 'draft',
        ])->assertRedirect();

        $product = MarketplaceListing::query()->where('type', 'product')->firstOrFail();
        $this->assertSame('draft', $product->status);
        $this->get('/builder?='.$product->public_id)->assertOk()->assertSee('name="status"', false);
        $this->get('/studio/products')->assertSee('/builder?='.$product->public_id);
    }

    public function test_job_builder_handles_owner_editing_review_and_locked_publication(): void
    {
        $client = $this->member('customer');
        $this->actingAs($client)->post('/my-jobs', [
            ...$this->jobData(), ...$this->agreed(),
        ])->assertRedirect();
        $job = MarketplaceJob::query()->firstOrFail();
        $this->get('/builder?='.$job->public_id)->assertOk()->assertSee($job->title);
        $this->get('/my-jobs')->assertSee('/builder?='.$job->public_id);

        $this->patch('/builder?='.$job->public_id, [
            ...$this->jobData(),
            'title' => 'An updated and reviewed Laravel marketplace brief',
        ])->assertRedirect('/builder?='.$job->public_id);
        $this->assertSame('pending', $job->fresh()->status);
        $this->assertSame('An updated and reviewed Laravel marketplace brief', $job->fresh()->title);

        $job->update(['status'=>'published']);
        $this->get('/builder?='.$job->public_id)->assertOk()->assertSee('cannot change it directly');
        $this->patch('/builder?='.$job->public_id, $this->jobData())->assertStatus(409);

        $stranger = $this->member('customer');
        $this->actingAs($stranger)->get('/builder?='.$job->public_id)->assertNotFound();
        $this->patch('/builder?='.$job->public_id, $this->jobData())->assertNotFound();
    }

    public function test_creator_listing_visibility_is_owner_scoped_and_reviewed_items_are_locked(): void
    {
        $owner = $this->member('freelancer');
        $other = $this->member('freelancer');
        $listing = MarketplaceListing::query()->create([
            'user_id'=>$owner->id, 'type'=>'project',
            'title'=>'Original web application project',
            'description'=>str_repeat('Original independent project with detailed deliverables. ',2),
            'status'=>'published',
        ]);
        $this->actingAs($other)->get('/builder?='.$listing->public_id)->assertNotFound();
        $this->actingAs($owner)->get('/builder?='.$listing->public_id)
            ->assertOk()->assertSee('cannot change it directly');
    }


    public function test_builder_studio_uses_public_layout_with_full_editor_and_featured_options(): void
    {
        $owner = $this->member('super_admin');
        $this->actingAs($owner)->get('/builder?type=product')->assertOk()
            ->assertSee('class="site-header"', false)
            ->assertSee('class="site-footer"', false)
            ->assertDontSee('id="workspace-shell"', false)
            ->assertSee('Builder Studio')
            ->assertSee('data-editor-command="bold"', false)
            ->assertSee('data-editor-command="insertOrderedList"', false)
            ->assertSee('data-editor-action="table"', false)
            ->assertSee('data-editor-action="source"', false)
            ->assertSee('name="featured_kind"', false)
            ->assertSee('value="video"', false)
            ->assertSee('value="animated"', false)
            ->assertSee('value="color"', false)
            ->assertSee('data-builder-copy-url', false);
        $this->get('/builder')->assertOk()->assertSee('Create service')->assertSee('Create job')
            ->assertSee('studio-landing-hero')
            ->assertSee('Creative workspace')
            ->assertSee('studio-landing-shield')
            ->assertSee('studio-landing-website')
            ->assertSee('What can you create?')
            ->assertSee('Regular License')
            ->assertSee('Extended License')
            ->assertSee('automatic license delivery are not currently enabled')
            ->assertDontSee('class="studio-breadcrumb"', false)
            ->assertDontSee('Bambodia / Creative workspace')
            ->assertDontSee('name="arrow-up-right"', false);
        foreach (['product', 'project', 'service', 'freelance', 'job'] as $type) {
            $this->get('/builder?type='.$type)->assertOk()
                ->assertDontSee('class="studio-breadcrumb"', false)
                ->assertDontSee('Bambodia / Creative workspace')
                ->assertSee('class="studio-landing-hero studio-editor-hero"', false)
                ->assertSee('Creative workspace')
                ->assertSee('id="studio-editor-heading"', false)
                ->assertSee('Create a professional '.ucfirst($type).' in one focused workspace.')
                ->assertSee('class="studio-type-actions"', false)
                ->assertSee('data-builder-preview-toggle', false)
                ->assertSee('My Content')
                ->assertSee('<svg width="17" height="17"', false)
                ->assertDontSee('studio-landing-guide');
        }
    }

    public function test_html_description_sanitization_preserves_safe_formatting_and_prevents_xss(): void
    {
        $creator = $this->member('freelancer');
        $html = '<h2>Complete application build</h2><p><strong>Deliver a professional application with documentation and tests.</strong>'
            .'<a href="javascript:alert(1)" onclick="alert(1)">Unsafe link</a>'
            .'<a href="https://example.com/docs" onclick="alert(1)">Docs</a></p>'
            .'<script>alert("injected")</script><img src=x onerror=alert(1)>';
        $this->actingAs($creator)->post('/studio/listings', [
            'type'=>'project',
            'title'=>'Modern and well-documented application',
            'description'=>'irrelevant',
            'description_html'=>$html,
            'featured_kind'=>'color',
            'featured_color'=>'#123abc',
            ...$this->agreed(),
        ])->assertRedirect();
        $listing = MarketplaceListing::query()->firstOrFail();
        $this->assertSame('pending', $listing->status);
        $this->assertSame('color', $listing->featured_kind);
        $this->assertSame('#123abc', $listing->featured_color);
        $this->assertStringContainsString('<strong>', $listing->description_html);
        $this->assertStringNotContainsString('javascript:', $listing->description_html);
        $this->assertStringNotContainsString('onclick', $listing->description_html);
        $this->assertStringNotContainsString('<script', $listing->description_html);
        $this->assertStringNotContainsString('<img', $listing->description_html);
        $this->assertStringContainsString('Deliver a professional application', $listing->description);

        $listing->update(['status'=>'published']);
        $this->get($listing->publicUrl())->assertOk()
            ->assertSee('background-color:#123abc', false)
            ->assertSee('<strong>', false)
            ->assertDontSee('javascript:');
    }

    public function test_featured_media_requires_approved_owned_asset_and_matches_kind(): void
    {
        $creator = $this->member('freelancer');
        $other = $this->member('freelancer');
        $asset = \App\Models\MediaAsset::query()->create([
            'user_id'=>$creator->id,'original_name'=>'demo.gif','storage_path'=>'media/test-animate.gif',
            'mime_type'=>'image/gif','bytes'=>1234,'width'=>10,'height'=>10,
            'alt_text'=>'Original animation','rights_basis'=>'owned','rights_holder'=>'Creator',
            'review_status'=>'approved',
        ]);
        $payload = [
            'type'=>'service',
            'title'=>'Original interface animation service',
            'description'=>str_repeat('Accessible animation with complete deliverables. ',2),
            'featured_kind'=>'animated',
            'featured_media_id'=>$asset->id,
        ];

        $this->actingAs($other)->post('/studio/listings',$payload)
            ->assertSessionHasErrors('featured_media_id');
        $this->actingAs($creator)->post('/studio/listings',[
            ...$payload,'featured_kind'=>'video',
        ])->assertSessionHasErrors('featured_media_id');
        $this->post('/studio/listings',$payload)->assertRedirect();
        $listing=MarketplaceListing::query()->firstOrFail();
        $this->assertSame('animated',$listing->featured_kind);
        $this->assertSame($asset->id,(int)$listing->featured_media_id);
        $this->get('/builder?='.$listing->public_id)
            ->assertOk()->assertSee('data-media-mime="image/gif"',false);
    }

    public function test_builder_media_upload_reuses_private_rights_review_and_blocks_external_redirects(): void
    {
        $owner=$this->member('customer');
        $this->actingAs($owner)->post('/builder/media',[
            'return_to'=>'https://malicious.example/',
            'alt_text'=>'Artwork for a customer job',
            'rights_basis'=>'owned','rights_holder'=>'Customer',
            'image'=>\Illuminate\Http\UploadedFile::fake()->image('cover.png'),
        ])->assertStatus(422);

        $this->post('/builder/media',[
            'return_to'=>'/builder?type=job',
            'alt_text'=>'Artwork for an original customer job',
            'rights_basis'=>'owned','rights_holder'=>'Customer',
            'image'=>\Illuminate\Http\UploadedFile::fake()->image('cover.png'),
        ])->assertRedirect('/builder?type=job');
        $asset=\App\Models\MediaAsset::query()->firstOrFail();
        $this->assertSame('pending',$asset->review_status);
        $this->assertSame($owner->id,$asset->user_id);

        $this->actingAs($this->member('staff'))->post('/builder/media',[
            'return_to'=>'/builder',
            'alt_text'=>'Unauthorized media upload',
            'rights_basis'=>'owned','rights_holder'=>'Staff',
            'image'=>\Illuminate\Http\UploadedFile::fake()->image('cover.png'),
        ])->assertForbidden();
    }

    public function test_rich_editor_preserves_safe_color_alignment_and_rejects_unsafe_css(): void
    {
        $html = '<p style="text-align:center;color:rgb(194, 65, 12);background-color:#fff1a8;position:fixed">'
            .'Carefully formatted marketplace content for international buyers.</p>'
            .'<p style="background-color:url(javascript:alert(1));color:rgb(999,0,0)">No dangerous styles</p>';
        $safe = \App\Support\BuilderContent::sanitize($html);
        $this->assertStringContainsString('text-align:center', $safe);
        $this->assertStringContainsString('color:#c2410c', $safe);
        $this->assertStringContainsString('background-color:#fff1a8', $safe);
        $this->assertStringNotContainsString('position:fixed', $safe);
        $this->assertStringNotContainsString('javascript:', $safe);
        $this->assertStringNotContainsString('rgb(999', $safe);
    }

    public function test_all_five_studio_forms_show_required_terms_popup_and_complete_media_controls(): void
    {
        $this->actingAs($this->member('super_admin'));
        foreach (['product', 'project', 'service', 'freelance', 'job'] as $type) {
            $this->get('/builder?type='.$type)->assertOk()
                ->assertSee('data-builder-terms-check', false)
                ->assertSee('id="studio-terms-popup"', false)
                ->assertSee('name="terms_sha256"', false)
                ->assertSee('data-builder-upload-files', false)
                ->assertSee('data-builder-gallery', false)
                ->assertDontSee('Rich text editor')
                ->assertDontSee('Headings · Lists · Links · Tables · Formatting');
        }
        $this->get('/builder?type=product')->assertOk()
            ->assertSee('Regular License price')
            ->assertSee('Extended License price')
            ->assertSee('name="regular_price"', false)
            ->assertSee('name="extended_price"', false)
            ->assertSee('Original downloadable product')
            ->assertSee('Save your product as a draft first.')
            ->assertSee('studio-final-action')
            ->assertDontSee('class="studio-card studio-final-action"', false);
    }

    public function test_builder_acceptance_is_required_and_is_recorded_by_content_id(): void
    {
        $creator = $this->member('freelancer');
        $payload = [
            'type'=>'project',
            'title'=>'Professional application customization project',
            'description'=>str_repeat('Original documentation and deliverables for a licensed project. ',2),
            'builder_mode'=>'1',
        ];
        $this->actingAs($creator)->post('/studio/listings',$payload)
            ->assertSessionHasErrors('accept_terms');
        $this->assertDatabaseCount('marketplace_listings',0);

        $this->post('/studio/listings', [
            ...$payload,'accept_terms'=>'1','terms_sha256'=>str_repeat('f',64),
        ])->assertSessionHasErrors('accept_terms');
        $this->assertDatabaseCount('marketplace_listings',0);

        $this->post('/studio/listings', [
            ...$payload,...$this->agreed(),
        ])->assertRedirect();
        $listing=MarketplaceListing::firstOrFail();
        $this->assertDatabaseHas('builder_terms_acceptances',[
            'user_id'=>$creator->id,'content_type'=>'project','content_id'=>$listing->id,
            'terms_sha256'=>\App\Support\BuilderSubmissionTerms::digest(),
        ]);
    }

    public function test_product_builder_saves_separate_license_tiers_and_prices_as_draft(): void
    {
        $creator=$this->member('freelancer');
        $admin=$this->member('admin');
        $regular=\App\Models\LicenseDefinition::query()->create([
            'code'=>'BUILDER_REG','name'=>'Regular License','version'=>'1.0.0',
            'permissions'=>str_repeat('Allowed use of an original digital product. ',2),
            'restrictions'=>str_repeat('No unauthorized redistribution or resale. ',2),
            'status'=>'active','created_by'=>$admin->id,
        ]);
        $extended=\App\Models\LicenseDefinition::query()->create([
            'code'=>'BUILDER_EXT','name'=>'Extended License','version'=>'1.0.0',
            'permissions'=>str_repeat('Broader published commercial rights for a product. ',2),
            'restrictions'=>str_repeat('No unauthorized sublicensing or source redistribution. ',2),
            'status'=>'active','created_by'=>$admin->id,
        ]);
        $this->actingAs($creator)->post('/studio/products', [
            ...$this->agreed(),
            'title'=>'Premium licensed Laravel content',
            'description'=>str_repeat('A polished original Laravel application template with docs. ',2),
            'regular_price'=>'39.00','extended_price'=>'189.00',
            'license_definition_id'=>$regular->id,
            'extended_license_definition_id'=>$extended->id,
            'status'=>'draft',
        ])->assertRedirect();

        $product=MarketplaceListing::query()->where('type','product')->firstOrFail();
        $this->assertEquals(39.00,(float)$product->regular_price);
        $this->assertEquals(189.00,(float)$product->extended_price);
        $this->assertEquals(39.00,(float)$product->price);
        $this->assertSame($extended->id,(int)$product->extended_license_definition_id);
        $this->get('/builder?='.$product->public_id)->assertOk()
            ->assertSee('name="extended_license_definition_id"',false)
            ->assertSee('id="studio-archive-file"',false);
        $this->assertDatabaseCount('product_entitlements',0);
    }

    public function test_builder_can_attach_private_pending_screenshots_without_public_exposure(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $creator=$this->member('freelancer');
        $other=$this->member('freelancer');
        $asset=\App\Models\MediaAsset::query()->create([
            'user_id'=>$creator->id,'original_name'=>'screen.png',
            'storage_path'=>'marketplace/previews/screenshot.png',
            'mime_type'=>'image/png','bytes'=>123,'width'=>20,'height'=>20,
            'alt_text'=>'Private original screenshot','rights_basis'=>'owned',
            'rights_holder'=>'Creator','review_status'=>'pending',
        ]);
        $payload=[
            ...$this->agreed(),'type'=>'service',
            'title'=>'Screenshot-driven software installation service',
            'description'=>str_repeat('A professionally delivered software installation service. ',2),
            'gallery_media_ids'=>[$asset->id],
        ];
        $this->actingAs($other)->post('/studio/listings',$payload)
            ->assertSessionHasErrors('gallery_media_ids');

        $this->actingAs($creator)->post('/studio/listings',$payload)->assertRedirect();
        $listing=MarketplaceListing::firstOrFail();
        $this->assertSame([$asset->id],$listing->gallery_media_ids);
        $this->actingAs($other)->get($asset->previewUrl())->assertNotFound();

        $this->actingAs($creator)->postJson('/builder/media',[
            'return_to'=>'/builder?type=service','alt_text'=>'Upload without an actual file',
            'rights_basis'=>'owned','rights_holder'=>'Creator',
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_product_release_metadata_is_saved_in_draft_and_shown_in_builder(): void
    {
        $creator=$this->member('freelancer');
        $fields=[
            ...$this->agreed(),
            'title'=>'Licensed Laravel Module Release',
            'description'=>str_repeat('An original documented digital product with deployment guidance. ',2),
            'software_version'=>'v2.4.1-beta.2',
            'software_published_on'=>'2026-01-12',
            'software_updated_on'=>'2026-02-17',
            'status'=>'draft',
        ];
        $this->actingAs($creator)->post('/studio/products',$fields)->assertRedirect();
        $product=MarketplaceListing::query()->where('type','product')->firstOrFail();
        $this->assertSame('v2.4.1-beta.2',$product->software_version);
        $this->assertSame('2026-01-12',$product->software_published_on->format('Y-m-d'));
        $this->assertSame('2026-02-17',$product->software_updated_on->format('Y-m-d'));
        $this->get('/builder?='.$product->public_id)->assertOk()
            ->assertSee('name="software_version"',false)
            ->assertSee('name="software_published_on"',false)
            ->assertSee('name="software_updated_on"',false)
            ->assertSee('v2.4.1-beta.2');

        $this->post('/studio/products',[
            ...$fields,
            'software_published_on'=>'2026-04-01',
            'software_updated_on'=>'2026-03-01',
        ])->assertSessionHasErrors('software_updated_on');

        $this->post('/studio/products',[
            ...$fields,
            'software_version'=>'../../unsafe-release',
        ])->assertSessionHasErrors('software_version');
    }

    public function test_html_preview_endpoint_only_allows_canonical_youtube_embeds(): void
    {
        $creator=$this->member('freelancer');
        $this->actingAs($creator);
        $html='<h2>Professional complete application documentation and validated deliverables</h2>'
            .'<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" onload="alert(1)"></iframe>'
            .'<iframe src="https://www.youtube.com.evil.test/embed/dQw4w9WgXcQ"></iframe>'
            .'<iframe src="javascript:alert(1)"></iframe>'
            .'<img src=x onerror=alert(1)><script>alert(1)</script>';
        $response=$this->postJson('/builder/content/preview',['html'=>$html])->assertOk();
        $safe=$response->json('html');
        $this->assertStringContainsString('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',$safe);
        $this->assertStringContainsString('sandbox="allow-scripts allow-same-origin allow-presentation"',$safe);
        $this->assertSame(1,substr_count($safe,'<iframe'));
        $this->assertStringNotContainsString('evil.test',$safe);
        $this->assertStringNotContainsString('<script',$safe);
        $this->assertStringNotContainsString('onload',$safe);
        $this->assertStringNotContainsString('<img',$safe);
        $this->assertStringContainsString('Professional complete application',$response->json('text'));

        $this->postJson('/builder/content/preview',['html'=>'<iframe src="http://youtube.com/embed/dQw4w9WgXcQ"></iframe><p>Only text remains</p>'])
            ->assertOk()->assertJsonPath('html','<p>Only text remains</p>');
        $this->actingAs($this->member('staff'))->postJson('/builder/content/preview',['html'=>'<p>Restricted</p>'])
            ->assertForbidden();
        $this->postJson('/builder/content/preview',['html'=>''])->assertForbidden();
    }

    public function test_typed_license_definitions_link_to_builder_and_reject_wrong_tier(): void
    {
        $admin=$this->member('admin');
        $creator=$this->member('freelancer');
        $base=[
            'version'=>'1.0.0',
            'permissions'=>str_repeat('Non-exclusive product use subject to published conditions. ',2),
            'restrictions'=>str_repeat('Original source file redistribution is prohibited. ',2),
            'status'=>'active','confirm_review'=>'1',
        ];
        $this->actingAs($admin)->post('/dashboard/license-manager', [
            ...$base,'code'=>'BAM_REGULAR','name'=>'Regular License',
        ])->assertSessionHasErrors('tier');
        $this->post('/dashboard/license-manager', [
            ...$base,'code'=>'BAM_REGULAR','tier'=>'regular','name'=>'Regular License',
        ])->assertRedirect();
        $this->post('/dashboard/license-manager', [
            ...$base,'code'=>'BAM_EXTENDED','tier'=>'extended','name'=>'Extended License',
        ])->assertRedirect();
        $regular=\App\Models\LicenseDefinition::where('code','BAM_REGULAR')->firstOrFail();
        $extended=\App\Models\LicenseDefinition::where('code','BAM_EXTENDED')->firstOrFail();
        $this->assertSame('regular',$regular->tier);
        $this->assertSame('extended',$extended->tier);
        $this->assertDatabaseCount('product_licenses',0);

        $this->actingAs($creator);
        $html=$this->get('/builder?type=product')->assertOk()
            ->assertSee('Read published Regular License terms')
            ->assertSee('Read published Extended License terms')
            ->getContent();
        preg_match('/<select[^>]*id="studio-license"[^>]*>(.*?)<\/select>/s',$html,$regularOptions);
        preg_match('/<select[^>]*id="studio-extended-license"[^>]*>(.*?)<\/select>/s',$html,$extendedOptions);
        $this->assertStringContainsString('value="'.$regular->id.'"',$regularOptions[1] ?? '');
        $this->assertStringNotContainsString('value="'.$extended->id.'"',$regularOptions[1] ?? '');
        $this->assertStringContainsString('value="'.$extended->id.'"',$extendedOptions[1] ?? '');
        $this->assertStringNotContainsString('value="'.$regular->id.'"',$extendedOptions[1] ?? '');
        $this->assertStringContainsString('/licenses?=',$regularOptions[1] ?? '');
        $this->assertStringContainsString('/licenses?=',$extendedOptions[1] ?? '');

        $payload=[
            ...$this->agreed(),
            'title'=>'A source-code template with licensed deliverables',
            'description'=>str_repeat('Original documentation for a reviewed software template. ',2),
            'status'=>'draft','regular_price'=>'25.00',
            'license_definition_id'=>$extended->id,
        ];
        $this->post('/studio/products',$payload)->assertSessionHasErrors('license_definition_id');
        $this->post('/studio/products',[
            ...$payload,
            'license_definition_id'=>$regular->id,'extended_license_definition_id'=>$regular->id,
            'extended_price'=>'120.00',
        ])->assertSessionHasErrors('extended_license_definition_id');
        $this->assertDatabaseCount('marketplace_listings',0);
        $this->post('/studio/products',[
            ...$payload,
            'license_definition_id'=>$regular->id,'extended_license_definition_id'=>$extended->id,
            'extended_price'=>'120.00',
        ])->assertRedirect();
        $this->assertDatabaseHas('marketplace_listings',[
            'type'=>'product',
            'license_definition_id'=>$regular->id,
            'extended_license_definition_id'=>$extended->id,
        ]);
    }


}
