<?php

namespace Tests\Feature;

use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\ProductEntitlement;
use App\Models\ProductFileVersion;
use App\Models\ProductLicense;
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
            'product_entitlement_id'=>$this->entitlement->id,'tier'=>$tier,
            'reason'=>'Complimentary product licensing for authorized quality assurance.',
            'confirm_complimentary'=>'1',
        ])->assertRedirect();
        return ProductLicense::query()->firstOrFail();
    }

    public function test_only_super_admin_can_issue_and_only_with_real_approved_product_and_entitlement(): void
    {
        $request=[
            'product_entitlement_id'=>$this->entitlement->id,
            'tier'=>'regular','reason'=>'Complimentary verified product review allocation.',
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
            'license_key'=>$key,'domain'=>'example.com',
        ])->assertStatus(403)->assertJsonPath('valid',false);

        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'domain'=>'https://example.com',
        ])->assertOk()->assertJsonPath('domain','example.com')->assertJsonPath('tier','regular');
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'domain'=>'example.com',
        ])->assertOk();
        $this->assertDatabaseCount('product_license_activations',1);
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'domain'=>'another.example.com',
        ])->assertStatus(409);
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$key,'domain'=>'another.example.com',
        ])->assertStatus(403);
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$key,'domain'=>'EXAMPLE.COM',
        ])->assertOk()->assertJsonPath('status','valid');

        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'domain'=>'http://example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'domain'=>'127.0.0.1',
        ])->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'domain'=>'example.com.evil.test/path',
        ])->assertUnprocessable()->assertJsonValidationErrors('domain');
    }

    public function test_customer_can_transfer_and_reveal_only_own_license_after_password(): void
    {
        $license=$this->issue();
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$license->key_ciphertext,'domain'=>'original.example.com',
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
            'license_key'=>$license->key_ciphertext,'domain'=>'original.example.com',
        ])->assertStatus(403);
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$license->key_ciphertext,'domain'=>'replacement.example.com',
        ])->assertStatus(403);
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$license->key_ciphertext,'domain'=>'replacement.example.com',
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
            'license_key'=>$original,'domain'=>'example.com',
        ])->assertStatus(403);
        $this->patch('/dashboard/license-manager/issued/'.$license->id.'/status',[
            'action'=>'activate','reason'=>'Independent compliance review completed.','confirm_change'=>'1',
        ])->assertRedirect();

        $this->post('/dashboard/license-manager/issued/'.$license->id.'/rotate',[
            'reason'=>'The previous key was exposed in a public repository.','confirm_rotate'=>'1',
        ])->assertRedirect();
        $next=$license->fresh()->key_ciphertext;
        $this->assertNotSame($original,$next);
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$original,'domain'=>'example.com',
        ])->assertStatus(403);
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$next,'domain'=>'example.com',
        ])->assertOk();

        $this->patch('/dashboard/license-manager/issued/'.$license->id.'/status',[
            'action'=>'revoke','reason'=>'Permanent revocation following a confirmed rights violation.',
            'confirm_change'=>'1',
        ])->assertRedirect();
        $this->assertSame('revoked',$license->fresh()->status);
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$next,'domain'=>'example.com',
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
        $this->actingAs($this->customer)->get('/studio/licenses')->assertForbidden();
        $this->get('/dashboard/license-manager')->assertForbidden();
    }

    public function test_revoked_or_expired_entitlement_invalidates_online_license(): void
    {
        $license=$this->issue();
        $key=$license->key_ciphertext;
        $this->postJson('/api/v1/licenses/activate',[
            'license_key'=>$key,'domain'=>'example.com',
        ])->assertOk();
        $this->entitlement->update(['status'=>'revoked','revoked_at'=>now()]);
        $this->postJson('/api/v1/licenses/validate',[
            'license_key'=>$key,'domain'=>'example.com',
        ])->assertStatus(403);
        $this->assertDatabaseCount('product_licenses',1);
        $this->assertDatabaseHas('product_license_events',[
            'product_license_id'=>$license->id,'action'=>'issued',
        ]);
    }
}
