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
            'builder_mode' => '1',
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
            'builder_mode' => '1',
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
            ...$this->jobData(), 'builder_mode'=>'1',
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
            ->assertOk()->assertSee('Direct editing is disabled');
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
        $this->get('/builder')->assertOk()->assertSee('Create service')->assertSee('Create job');
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
            'builder_mode'=>'1',
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
}
