<?php

namespace Tests\Feature;

use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\ProductEntitlement;
use App\Models\ProductFileVersion;
use App\Models\ProductLicense;
use App\Models\ProductLicenseSupportRequest;
use App\Support\ProductLicenseSupport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductLicenseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $staff;
    private User $creator;
    private User $customer;
    private User $stranger;
    private MarketplaceListing $product;
    private ProductFileVersion $release;
    private ProductEntitlement $entitlement;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolate business authorization and lifecycle assertions from the
        // shared in-memory rate-limit bucket. Throttling remains enabled in
        // real environments and is tested separately.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Storage::fake('local');
        $this->admin=$this->member('super_admin');
        $this->staff=$this->member('admin');
        $this->creator=$this->member('freelancer');
        $this->customer=$this->member('customer');
        $this->stranger=$this->member('customer');
        $regular=$this->definition('BAM_REG','Regular License');
        $extended=$this->definition('BAM_EXT','Extended License');

        Storage::disk('local')->put('marketplace/previews/approved.png','approved image file');
        $cover=MediaAsset::query()->create([
            'user_id'=>$this->creator->id,'original_name'=>'approved.png',
            'storage_path'=>'marketplace/previews/approved.png',
            'mime_type'=>'image/png','bytes'=>20,'width'=>1,'height'=>1,
            'alt_text'=>'Approved original cover screenshot',
            'rights_basis'=>'owned','rights_holder'=>'Creator',
            'review_status'=>'approved',
        ]);
        $this->product=MarketplaceListing::query()->create([
            'user_id'=>$this->creator->id,'type'=>'product',
            'title'=>'Licensed Laravel Product',
            'description'=>str_repeat('A secure original Laravel PHP script suitable for installation. ',2),
            'license_definition_id'=>$regular->id,
            'extended_license_definition_id'=>$extended->id,
            'regular_price'=>'39.00','extended_price'=>'169.00',
            'cover_media_id'=>$cover->id,'status'=>'published',
        ]);

        $archive='marketplace/originals/'.$this->creator->id.'/'.$this->product->id.'/'.str_repeat('a',64).'.zip';
        // The test covers the immutable verified path. The production upload
        // controller separately validates actual ZIP structure before approval.
        $content='approved-private-digital-product-archive-content';
        Storage::disk('local')->put($archive,$content);
        $hash=hash('sha256',$content);
        $this->release=ProductFileVersion::query()->create([
            'marketplace_listing_id'=>$this->product->id,
            'uploaded_by'=>$this->creator->id,'version'=>'1.0.0',
            'release_notes'=>'A verified release with a complete source code archive.',
            'original_filename'=>'approved.zip','storage_path'=>$archive,'file_type'=>'zip',
            'byte_size'=>strlen($content),'sha256'=>$hash,'status'=>'approved',
            'rights_attested_at'=>now(),'inspected_at'=>now(),'inspected_by'=>$this->staff->id,
            'reviewed_at'=>now(),'reviewed_by'=>$this->staff->id,
            'rights_evidence'=>'The creator owns and controls redistribution rights.',
            'rights_review_status'=>'verified','rights_reviewed_by'=>$this->staff->id,
            'rights_reviewed_at'=>now(),'rights_review_note'=>'Verified original authorship and distribution permission.',
            'malware_scan_status'=>'clean','malware_scanned_sha256'=>$hash,
            'malware_scan_engine'=>'ClamAV verified CI fixture','malware_scanned_at'=>now(),
            'malware_scanned_by'=>$this->staff->id,
        ]);
        $this->entitlement=ProductEntitlement::query()->create([
            'user_id'=>$this->customer->id,
            'product_file_version_id'=>$this->release->id,
            'granted_by'=>$this->admin->id,
            'grant_reason'=>'Manual complimentary development acceptance test.',
            'status'=>'active','expires_at'=>now()->addDays(30),
        ]);
    }

    private function member(string $role): User
    {
        return User::query()->create([
            'name'=>'Account '.ucfirst($role),
            'email'=>uniqid($role,true).'@example.test',
            'password'=>'StrongSecret1234!','role'=>$role,
        ]);
    }

    private function definition(string $code,string $name): LicenseDefinition
    {
        return LicenseDefinition::query()->create([
            'code'=>$code,'name'=>$name,'version'=>'1.0.0',
            'permissions'=>str_repeat('Permits use on one registered approved domain. ',2),
            'restrictions'=>str_repeat('Does not permit redistribution or unapproved resale. ',2),
            'status'=>'active','created_by'=>$this->admin->id,
        ]);
    }

    private function issue(string $tier='regular'): ProductLicense
    {
        $this->actingAs($this->admin)->post('/dashboard/license-manager/issue',[
            'product_entitlement_id'=>$this->entitlement->id,'tier'=>$tier,'support_term'=>'six_months',
            'reason'=>'Complimentary product licensing for authorized quality assurance.',
            'confirm_complimentary'=>'1',
        ])->assertRedirect();
        return ProductLicense::query()->firstOrFail();
    }

    public function test_only_super_admin_can_issue_and_only_with_real_approved_product_and_entitlement(): void
    {
        $request=[
            'product_entitlement_id'=>$this->entitlement->id,
            'tier'=>'regular','support_term'=>'six_months','reason'=>'Complimentary verified product review allocation.',
            'confirm_complimentary'=>'1',
        ];
        $this->actingAs($this->creator)->post('/dashboard/license-manager/issue',$request)->assertForbidden();
        $this->actingAs($this->staff)->post('/dashboard/license-manager/issue',$request)->assertForbidden();
        $this->assertDatabaseCount('product_licenses',0);

        $this->release->update(['status'=>'pending']);
        $this->actingAs($this->admin)->post('/dashboard/license-manager/issue',$request)->assertStatus(409);
        $this->assertDatabaseCount('product_licenses',0);
        $this->release->update(['status'=>'approved']);

        $this->product->update(['status'=>'draft']);
        $this->post('/dashboard/license-manager/issue',$request)->assertStatus(409);
        $this->product->update(['status'=>'published']);
        $this->post('/dashboard/license-manager/issue',$request)->assertRedirect();
        $license=ProductLicense::firstOrFail();
        $this->assertSame('admin_complimentary',$license->source);
        $this->assertSame($this->entitlement->id,$license->product_entitlement_id);
        $this->assertNotSame($license->key_hash,$license->key_ciphertext);
        $this->assertStringStartsWith('BAM-',$license->key_ciphertext);
        $this->assertDatabaseCount('product_licenses',1);
        $this->post('/dashboard/license-manager/issue',$request)->assertStatus(409);
        $this->assertDatabaseCount('product_licenses',1);
    }

    public function test_installed_script_activates_once_and_validates_only_the_bound_domain(): void
    {
        $license=$this->issue();
        $key=$license->key_ciphertext;
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$key,'product_id'=>$this->product->public_id,'domain'=>'example.com',
        ])->assertStatus(403)->assertJsonPath('valid',false);

        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'product_id'=>$this->product->public_id,'domain'=>'https://example.com',
        ])->assertOk()->assertJsonPath('domain','example.com')->assertJsonPath('tier','regular');
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'product_id'=>$this->product->public_id,'domain'=>'example.com',
        ])->assertOk();
        $this->assertDatabaseCount('product_license_activations',1);
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'product_id'=>$this->product->public_id,'domain'=>'another.example.com',
        ])->assertStatus(409);
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$key,'product_id'=>$this->product->public_id,'domain'=>'another.example.com',
        ])->assertStatus(403);
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$key,'product_id'=>$this->product->public_id,'domain'=>'EXAMPLE.COM',
        ])->assertOk()->assertJsonPath('status','valid');
        $wrongProductId = $this->product->public_id === '123456789' ? '987654321' : '123456789';
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$key,'product_id'=>$wrongProductId,'domain'=>'example.com',
        ])->assertStatus(403);
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$key,'domain'=>'example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('product_id');

        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'product_id'=>$this->product->public_id,'domain'=>'http://example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'product_id'=>$this->product->public_id,'domain'=>'127.0.0.1',
        ])->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'product_id'=>$this->product->public_id,'domain'=>'example.com.evil.test/path',
        ])->assertUnprocessable()->assertJsonValidationErrors('domain');
    }

    public function test_customer_can_transfer_and_reveal_only_own_license_after_password(): void
    {
        $license=$this->issue();
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$license->key_ciphertext,'product_id'=>$this->product->public_id,'domain'=>'original.example.com',
        ])->assertOk();

        $this->actingAs($this->stranger)->get('/account/licenses')->assertOk()
            ->assertDontSee($license->public_id);
        $this->post('/account/licenses/'.$license->id.'/reveal',[
            'password'=>'StrongSecret1234!',
        ])->assertNotFound();
        $this->patch('/account/licenses/'.$license->id.'/domain',[
            'domain'=>'replacement.example.com','password'=>'StrongSecret1234!',
        ])->assertNotFound();

        $this->actingAs($this->customer)->post('/account/licenses/'.$license->id.'/reveal',[
            'password'=>'wrong-password',
        ])->assertStatus(403);
        $this->post('/account/licenses/'.$license->id.'/reveal',[
            'password'=>'StrongSecret1234!',
        ])->assertOk()->assertSee($license->key_ciphertext);

        $this->patch('/account/licenses/'.$license->id.'/domain',[
            'domain'=>'replacement.example.com','password'=>'StrongSecret1234!',
        ])->assertRedirect();
        $this->assertDatabaseHas('product_license_activations',[
            'product_license_id'=>$license->id,'domain'=>'original.example.com','status'=>'deactivated',
        ]);
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$license->key_ciphertext,'product_id'=>$this->product->public_id,'domain'=>'original.example.com',
        ])->assertStatus(403);
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$license->key_ciphertext,'product_id'=>$this->product->public_id,'domain'=>'replacement.example.com',
        ])->assertStatus(403);
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$license->key_ciphertext,'product_id'=>$this->product->public_id,'domain'=>'unapproved.example.com',
        ])->assertStatus(409);
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$license->key_ciphertext,'product_id'=>$this->product->public_id,'domain'=>'replacement.example.com',
        ])->assertOk();
        $this->assertDatabaseHas('product_license_events',[
            'product_license_id'=>$license->id,'action'=>'domain.changed','domain'=>'replacement.example.com',
        ]);
    }

    public function test_admin_can_upgrade_suspend_rotate_and_permanently_revoke(): void
    {
        $license=$this->issue();
        $original=$license->key_ciphertext;
        $this->actingAs($this->staff)->patch('/dashboard/license-manager/issued/'.$license->id.'/upgrade',[
            'reason'=>'A manual extended licensing permission was approved.','confirm_manual_upgrade'=>'1',
        ])->assertRedirect();
        $this->assertSame('extended',$license->fresh()->tier);
        $this->assertEquals($this->product->extended_license_definition_id,$license->fresh()->license_definition_id);

        $this->patch('/dashboard/license-manager/issued/'.$license->id.'/status',[
            'action'=>'suspend','reason'=>'Temporarily suspended for compliance review.','confirm_change'=>'1',
        ])->assertRedirect();
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$original,'product_id'=>$this->product->public_id,'domain'=>'example.com',
        ])->assertStatus(403);
        $this->release->update(['status'=>'disabled']);
        $this->patch('/dashboard/license-manager/issued/'.$license->id.'/status',[
            'action'=>'activate','reason'=>'Independent compliance review completed.','confirm_change'=>'1',
        ])->assertStatus(409);
        $this->assertSame('suspended',$license->fresh()->status);
        $this->release->update(['status'=>'approved']);
        $this->patch('/dashboard/license-manager/issued/'.$license->id.'/status',[
            'action'=>'activate','reason'=>'Independent compliance review completed.','confirm_change'=>'1',
        ])->assertRedirect();

        $this->post('/dashboard/license-manager/issued/'.$license->id.'/rotate',[
            'reason'=>'The previous key was exposed in a public repository.','confirm_rotate'=>'1',
        ])->assertRedirect();
        $next=$license->fresh()->key_ciphertext;
        $this->assertNotSame($original,$next);
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$original,'product_id'=>$this->product->public_id,'domain'=>'example.com',
        ])->assertStatus(403);
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$next,'product_id'=>$this->product->public_id,'domain'=>'example.com',
        ])->assertOk();

        $this->patch('/dashboard/license-manager/issued/'.$license->id.'/status',[
            'action'=>'revoke','reason'=>'Permanent revocation following a confirmed rights violation.',
            'confirm_change'=>'1',
        ])->assertRedirect();
        $this->assertSame('revoked',$license->fresh()->status);
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$next,'product_id'=>$this->product->public_id,'domain'=>'example.com',
        ])->assertStatus(403);
        $this->patch('/dashboard/license-manager/issued/'.$license->id.'/status',[
            'action'=>'activate','reason'=>'Attempted reactivation after permanent revocation.',
            'confirm_change'=>'1',
        ])->assertStatus(409);
    }

    public function test_creator_sees_only_own_product_licenses_without_secret_key(): void
    {
        $license=$this->issue();
        $this->actingAs($this->creator)->get('/studio/licenses')->assertOk()
            ->assertSee($this->product->title)->assertSee($license->public_id)
            ->assertDontSee($license->key_ciphertext);
        $another=$this->member('freelancer');
        $this->actingAs($another)->get('/studio/licenses')->assertOk()
            ->assertDontSee($license->public_id)->assertDontSee($this->customer->public_id);
        $this->post('/studio/licenses/'.$license->id.'/request-review',[
            'action'=>'revocation_review','reason'=>'Attempted modification by unrelated creator.',
        ])->assertNotFound();
        $this->actingAs($this->creator)->post('/studio/licenses/'.$license->id.'/request-review',[
            'action'=>'domain_review','reason'=>'Please help the buyer verify domain migration.',
        ])->assertRedirect();
        $this->assertDatabaseHas('product_license_events',[
            'product_license_id'=>$license->id,'actor_id'=>$this->creator->id,
            'action'=>'creator.review.requested',
        ]);
        $this->actingAs($this->customer)->get('/studio/licenses')->assertForbidden();
        $this->get('/dashboard/license-manager')->assertForbidden();
    }

    public function test_revoked_or_expired_entitlement_invalidates_online_license(): void
    {
        $license=$this->issue();
        $key=$license->key_ciphertext;
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'product_id'=>$this->product->public_id,'domain'=>'example.com',
        ])->assertOk();
        $this->entitlement->update(['status'=>'revoked','revoked_at'=>now()]);
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$key,'product_id'=>$this->product->public_id,'domain'=>'example.com',
        ])->assertStatus(403);
        $this->assertDatabaseCount('product_licenses',1);
        $this->assertDatabaseHas('product_license_events',[
            'product_license_id'=>$license->id,'action'=>'issued',
        ]);
    }

    public function test_customer_licenses_render_as_a_table_with_clear_empty_state_and_no_placeholder_notices(): void
    {
        $this->actingAs($this->customer)->get('/account/licenses')->assertOk()
            ->assertSee('license-account-table')
            ->assertSee('Product licenses')
            ->assertSee('Active domain')
            ->assertSee('No licenses yet')
            ->assertDontSee('Automatic purchase issuance awaits verified checkout integration.')
            ->assertDontSee('Uploads, unpaid orders and license definitions alone');

        $this->actingAs($this->admin)->get('/dashboard/license-manager')->assertOk()
            ->assertDontSee('Automatic paid-purchase issuance is disabled until verified payment events');

        $this->actingAs($this->creator)->get('/studio/licenses')->assertOk()
            ->assertDontSee('Creators cannot mint, revoke, rotate or upgrade customer keys.');

        $license = $this->issue();
        $response = $this->actingAs($this->customer)->get('/account/licenses')->assertOk()
            ->assertSee('license-account-table')
            ->assertSee($this->product->title)
            ->assertSee($license->maskedKey())
            ->assertSee($license->public_id)
            ->assertSee('data-modal-open="account-license-'.$license->id.'"', false)
            ->assertSee('Change installed domain')
            ->assertSee('Reveal installer license key')
            ->assertDontSee($license->key_ciphertext);

        $this->assertSame(1, substr_count($response->getContent(), 'id="account-license-'.$license->id.'"'));
    }

    public function test_lifetime_license_does_not_expire_with_support_or_original_download_expiry(): void
    {
        $license=$this->issue();
        $this->assertNull($license->expires_at);
        $this->assertSame('six_months',$license->support_term);
        $this->assertSame('Active',$license->supportStatus());
        $this->assertTrue($license->isActive());

        $license->forceFill([
            'support_expires_at'=>now()->subDay(),
        ])->save();
        $this->entitlement->forceFill(['expires_at'=>now()->subDay()])->save();

        $this->assertSame('Expired',$license->fresh()->supportStatus());
        $this->assertTrue($license->fresh()->isActive());
        $this->actingAs($this->customer)->get('/account/licenses')
            ->assertOk()->assertSee('Lifetime')->assertSee('Expired')
            ->assertSee('Request support renewal');
        $this->actingAs($this->customer)->get('/account/downloads')
            ->assertOk()->assertSee('Unavailable')->assertSee('Lifetime')->assertSee('Expired');
    }

    public function test_customer_renewal_is_pending_until_super_admin_reviews_it(): void
    {
        $license=$this->issue();
        $license->forceFill(['support_expires_at'=>now()->subDay()])->save();

        $url='/account/licenses/'.$license->id.'/support-renewals';
        $this->actingAs($this->stranger)->post($url,[
            'term'=>'twelve_months',
        ])->assertNotFound();
        $this->actingAs($this->customer)->post($url,[
            'term'=>'twelve_months',
        ])->assertRedirect();
        $renewal=ProductLicenseSupportRequest::query()->sole();
        $this->assertSame('pending',$renewal->status);
        $this->assertSame('Expired',$license->fresh()->supportStatus());
        $this->post($url,['term'=>'five_years'])->assertStatus(409);
        $this->post($url,['term'=>'999_years'])->assertSessionHasErrors('term');

        $review='/dashboard/license-manager/support-renewals/'.$renewal->id.'/review';
        $fields=['decision'=>'approve','reason'=>'Authorized complimentary support extension, reviewed by owner.',
            'confirm_manual'=>'1'];
        $this->actingAs($this->staff)->post($review,$fields)->assertForbidden();
        $this->assertSame('pending',$renewal->fresh()->status);
        $this->actingAs($this->admin)->post($review,$fields)->assertRedirect();
        $this->assertSame('approved',$renewal->fresh()->status);
        $this->assertSame('twelve_months',$license->fresh()->support_term);
        $this->assertSame('Active',$license->fresh()->supportStatus());
        $this->assertNull($license->fresh()->expires_at);
        $this->assertTrue($license->fresh()->isActive());
        $this->post($review,$fields)->assertStatus(409);
        $this->assertDatabaseHas('product_license_events',[
            'product_license_id'=>$license->id,'action'=>'support.approved',
        ]);
        $this->assertDatabaseHas('account_notifications',[
            'user_id'=>$this->customer->id,'event'=>'license.support.reviewed',
        ]);
        $this->actingAs($this->customer)->get('/account/licenses')->assertOk()
            ->assertSee('12-month support')->assertSee('Lifetime');
    }

    public function test_support_is_separate_from_lifetime_license_and_lifetime_support_needs_no_renewal(): void
    {
        $license=$this->issue();
        $this->actingAs($this->customer)->post('/account/licenses/'.$license->id.'/support-renewals',[
            'term'=>'lifetime',
        ])->assertRedirect();
        $renewal=ProductLicenseSupportRequest::query()->sole();
        $this->actingAs($this->admin)->post('/dashboard/license-manager/support-renewals/'.$renewal->id.'/review',[
            'decision'=>'approve','reason'=>'Complimentary permanent support approved after review.',
            'confirm_manual'=>'1',
        ])->assertRedirect();
        $this->assertSame('Lifetime',$license->fresh()->supportStatus());
        $this->assertNull($license->fresh()->support_expires_at);
        $this->assertNull($license->fresh()->expires_at);
        $this->actingAs($this->customer)->post('/account/licenses/'.$license->id.'/support-renewals',[
            'term'=>'six_months',
        ])->assertStatus(409);
        $this->get('/account/licenses')->assertOk()->assertSee('Lifetime support');
    }

    public function test_license_pagination_go_to_uses_the_respective_table_query_parameter(): void
    {
        // Distinct approved versions are required because a customer cannot
        // receive duplicate entitlements for the exact same downloadable version.
        for ($i=0;$i<11;$i++) {
            $version=$this->release->replicate();
            $version->version='1.'.($i+1).'.0';
            $version->storage_path='marketplace/originals/'.$this->creator->id.'/'.$this->product->id
                .'/'.str_pad(dechex($i+1),64,'0',STR_PAD_LEFT).'.zip';
            Storage::disk('local')->copy($this->release->storage_path,$version->storage_path);
            $version->save();
            $grant=ProductEntitlement::query()->create([
                'user_id'=>$this->customer->id,
                'product_file_version_id'=>$version->id,
                'granted_by'=>$this->admin->id,'grant_reason'=>'Complimentary QA entitlement for pagination test.',
                'status'=>'active','expires_at'=>now()->addDays(30),
            ]);
            \App\Support\ProductLicenseLifecycle::issueManual(
                $grant,'regular',$this->admin,'Approved manual issuance for paged license test.','six_months'
            );
        }
        $this->actingAs($this->customer)->get('/account/licenses')->assertOk()
            ->assertSee('Showing 1–10 of 11')->assertSee('name="page"',false);
        $this->get('/account/licenses?page=2')->assertOk()
            ->assertSee('Showing 11–11 of 11');

        $this->actingAs($this->creator)->get('/studio/licenses')->assertOk()
            ->assertSee('Showing 1–10 of 11')->assertSee('name="page"',false);
        $this->get('/studio/licenses?page=2')->assertOk()
            ->assertSee('Showing 11–11 of 11');

        $this->actingAs($this->admin)->get('/dashboard/license-manager')->assertOk()
            ->assertSee('name="issued_page"',false);
        $this->get('/dashboard/license-manager?issued_page=2')->assertOk()
            ->assertSee('Showing 11–11 of 11');
    }


    public function test_admin_license_actions_use_icon_dropdowns_with_existing_authorized_workflows(): void
    {
        $license=$this->issue();
        $regular=LicenseDefinition::query()->where('code','BAM_REG')->sole();
        $extended=LicenseDefinition::query()->where('code','BAM_EXT')->sole();
        $response=$this->actingAs($this->admin)->get('/dashboard/license-manager')->assertOk();
        $response->assertSee('popovertarget="license-actions-'.$regular->id.'"',false)
            ->assertSee('data-license-action-menu',false)
            ->assertSee('data-license-focus="issued-'.$license->id.'-history"',false)
            ->assertSee('data-license-select="suspend"',false)
            ->assertSee('data-license-select="revoke"',false)
            ->assertSee('data-license-focus="issued-'.$license->id.'-rotate"',false)
            ->assertSee('Published terms')
            ->assertDontSee('data-license-focus="license-code-'.$extended->id.'"',false);

        // A definition used as the Extended License must not expose
        // editing or activation actions, even with zero regular listings.
        $this->assertSame(0,$extended->listings()->count());
        $this->assertSame(1,$extended->extendedListings()->count());

        // The visual dropdown cannot bypass existing server-side checks.
        $this->actingAs($this->customer)->patch('/dashboard/license-manager/issued/'.$license->id.'/status', [
            'action'=>'suspend','reason'=>'Unauthorized customer action','confirm_change'=>'1',
        ])->assertForbidden();
        $this->assertSame('active',$license->fresh()->status);
    }



    public function test_manual_issuance_is_in_right_aligned_modal_and_keeps_default_six_month_support(): void
    {
        $response=$this->actingAs($this->admin)->get('/dashboard/license-manager');
        $response->assertOk()
            ->assertSee('id="license-issue-dialog"',false)
            ->assertSee('data-modal-open="license-issue-dialog"',false)
            ->assertSee('id="initial-support-term"',false)
            ->assertSee('value="six_months" selected',false)
            ->assertSee('name="confirm_complimentary"',false)
            ->assertSee('class="table-scroll license-issued-scroll"',false)
            ->assertDontSee('<details class="license-workflow-panel">',false);
        $this->assertDatabaseCount('product_licenses',0);
        // The manual modal is only rendered for super administrators.
        $this->actingAs($this->staff)->get('/dashboard/license-manager')
            ->assertOk()->assertDontSee('id="license-issue-dialog"',false);
    }

    public function test_admin_issued_license_search_sort_and_product_link_are_real_and_independent(): void
    {
        $license=$this->issue();
        $this->actingAs($this->admin)->get('/dashboard/license-manager?issued_search='.urlencode($this->product->title))
            ->assertOk()->assertSee($license->public_id)
            ->assertSee('data-license-copy-url="',false)
            ->assertSee('product?='.$this->product->public_id.'-'.$this->product->slug,false)
            ->assertSee('id="issued-product-'.$license->id.'"',false)
            ->assertSee('id="issued-'.$license->id.'"',false)
            ->assertSee('No</th><th scope="col">License',false);

        $this->get('/dashboard/license-manager?issued_search=missing-product-name')
            ->assertOk()->assertSee('No issued licenses match your search.')
            ->assertDontSee('aria-label="View license '.$license->public_id.'"',false);
        $this->get('/dashboard/license-manager?issued_search='.urlencode($this->customer->public_id).'&issued_sort=oldest')
            ->assertOk()->assertSee($license->public_id)->assertSee('selected>Oldest first',false);
        $this->get('/dashboard/license-manager?issued_sort=status')
            ->assertOk()->assertSee($license->public_id);
        $this->get('/dashboard/license-manager?issued_sort=not-valid')
            ->assertOk()->assertSee('selected>Recent (newest first)',false);
    }


}
