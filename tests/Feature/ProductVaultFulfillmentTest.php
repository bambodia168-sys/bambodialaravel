<?php

namespace Tests\Feature;

use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\ProductDownloadEvent;
use App\Models\ProductEntitlement;
use App\Models\ProductFileVersion;
use App\Models\User;
use App\Support\VaultMalwareScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class ProductVaultFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private User $reviewer;
    private User $superAdmin;
    private User $customer;
    private User $stranger;
    private MarketplaceListing $product;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // Test-only scanner double: production always requires real ClamAV.
        $this->app->instance(VaultMalwareScanner::class,new class extends VaultMalwareScanner {
            public function scanFile(string $absoluteFile): array
            {
                return [
                    'status'=>'clean','engine'=>'ClamAV 1.4.0 (unit-test double)',
                    'note'=>'Test-only deterministic clean result, not external malware acceptance.',
                ];
            }
        });
        $this->creator = $this->account('freelancer');
        $this->reviewer = $this->account('admin');
        $this->superAdmin = $this->account('super_admin');
        $this->customer = $this->account('customer');
        $this->stranger = $this->account('customer');
        $this->product = $this->publishedProduct();
    }

    private function account(string $role): User
    {
        return User::query()->create([
            'name' => 'Vault '.$role,
            'email' => uniqid('vault'.$role, true).'@example.test',
            'password' => 'AComplexPassword123!',
            'role' => $role,
        ]);
    }

    private function publishedProduct(): MarketplaceListing
    {
        $license = LicenseDefinition::query()->create([
            'code' => 'VAULT_REGULAR',
            'name' => 'Original Artifact License',
            'version' => '1.0.0',
            'permissions' => str_repeat('Permission for individual internal use. ', 2),
            'restrictions' => str_repeat('No redistribution or sublicensing permitted. ', 2),
            'status' => 'active',
            'published_at' => now(),
            'created_by' => $this->superAdmin->id,
        ]);

        $imagePath = 'marketplace/previews/'.$this->creator->id.'/cover.png';
        Storage::disk('local')->put($imagePath, 'fake image fixture in private storage');
        $image = MediaAsset::query()->create([
            'user_id' => $this->creator->id,
            'original_name' => 'cover.png',
            'storage_path' => $imagePath,
            'mime_type' => 'image/png',
            'bytes' => 36,
            'width' => 1,
            'height' => 1,
            'alt_text' => 'Original creator cover preview',
            'rights_basis' => 'owned',
            'rights_holder' => $this->creator->name,
            'review_status' => 'approved',
            'reviewed_by' => $this->reviewer->id,
            'reviewed_at' => now(),
        ]);

        return MarketplaceListing::query()->create([
            'user_id' => $this->creator->id,
            'type' => 'product',
            'title' => 'Licensed Software Template',
            'description' => str_repeat('An original licensed application template. ', 2),
            'license_definition_id' => $license->id,
            'cover_media_id' => $image->id,
            'status' => 'published',
        ]);
    }

    private function zip(array $entries = ['README.txt' => 'Original source and instructions.']): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'bambodia-zip-');
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true);
        foreach ($entries as $name => $content) {
            $this->assertTrue($zip->addFromString($name, $content));
        }
        $this->assertTrue($zip->close());
        $content = file_get_contents($temp);
        unlink($temp);

        return UploadedFile::fake()->createWithContent('source.zip', $content);
    }

    private function upload(string $version = '1.0.0'): ProductFileVersion
    {
        $this->actingAs($this->creator)->post('/studio/products/'.$this->product->id.'/releases', [
            'version' => $version,
            'release_notes' => 'A complete release containing licensed source files.',
            'artifact' => $this->zip(),
            'rights_evidence'=>'I created this release and hold original redistribution rights under the active product license.',
            'rights_attested' => '1',
        ])->assertRedirect(route('studio.vault.index', $this->product));

        return ProductFileVersion::query()->where('version', $version)->firstOrFail();
    }

    private function approve(ProductFileVersion $version): void
    {
        $this->actingAs($this->reviewer)->post('/dashboard/file-vault/'.$version->id.'/scan')
            ->assertRedirect();
        $this->post('/dashboard/file-vault/'.$version->id.'/inspect')
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->post('/dashboard/file-vault/'.$version->id.'/rights', [
            'decision'=>'verified',
            'rights_review_note'=>'I checked the uploader evidence, ownership statement and distribution license for this release.',
            'confirm_rights'=>'1',
        ])->assertRedirect();
        $this->patch('/dashboard/file-vault/'.$version->id, [
            'decision' => 'approved',
            'confirm_review' => '1',
        ])->assertRedirect();
        $this->assertSame('approved', $version->fresh()->status);
    }

    private function grant(ProductFileVersion $version): ProductEntitlement
    {
        $this->actingAs($this->superAdmin)->post('/dashboard/entitlements', [
            'recipient_public_id' => $this->customer->public_id,
            'product_file_version_id' => $version->id,
            'grant_reason' => 'Complimentary review access approved by the project owner.',
            'expires_days' => '30',
            'confirm_no_payment' => '1',
        ])->assertRedirect();

        return ProductEntitlement::query()->where('user_id', $this->customer->id)
            ->where('product_file_version_id', $version->id)->firstOrFail();
    }

    public function test_creator_file_is_private_pending_review_and_immutable(): void
    {
        $version = $this->upload();
        $this->assertSame('pending', $version->status);
        $this->assertSame('zip', $version->file_type);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $version->sha256);
        $this->assertStringStartsWith('marketplace/originals/'.$this->creator->id.'/', $version->storage_path);
        Storage::disk('local')->assertExists($version->storage_path);
        $this->assertSame(hash_file('sha256', Storage::disk('local')->path($version->storage_path)), $version->sha256);

        $this->actingAs($this->creator)->post('/studio/products/'.$this->product->id.'/releases', [
            'version' => '1.0.0',
            'release_notes' => 'An attempt to overwrite an original immutable release.',
            'artifact' => $this->zip(),
            'rights_evidence'=>'I created this release and hold original redistribution rights under the active product license.',
            'rights_attested' => '1',
        ])->assertSessionHasErrors('version');
        $this->assertSame(1, ProductFileVersion::count());
        $this->get('/account/downloads')->assertOk()->assertDontSee('source.zip');
    }

    public function test_creator_cannot_upload_to_another_persons_product(): void
    {
        $this->actingAs($this->stranger)->post('/studio/products/'.$this->product->id.'/releases', [
            'version' => '1.0.0',
            'release_notes' => 'An unauthorized attempt to replace a licensed file.',
            'artifact' => $this->zip(),
            'rights_evidence'=>'I created this release and hold original redistribution rights under the active product license.',
            'rights_attested' => '1',
        ])->assertForbidden();

        $other = $this->account('freelancer');
        $this->actingAs($other)->post('/studio/products/'.$this->product->id.'/releases', [
            'version' => '1.0.0',
            'release_notes' => 'An unauthorized attempt to upload to another creator.',
            'artifact' => $this->zip(),
            'rights_evidence'=>'I created this release and hold original redistribution rights under the active product license.',
            'rights_attested' => '1',
        ])->assertNotFound();
        $this->assertDatabaseCount('product_file_versions', 0);
    }

    public function test_archive_structure_validation_blocks_traversal_and_zip_bombs(): void
    {
        $this->actingAs($this->creator)->post('/studio/products/'.$this->product->id.'/releases', [
            'version' => '1.0.0',
            'release_notes' => 'An archive with unsafe traversal path elements.',
            'artifact' => $this->zip(['../unsafe.php' => '<?php echo 1;']),
            'rights_evidence'=>'I created this release and hold original redistribution rights under the active product license.',
            'rights_attested' => '1',
        ])->assertSessionHasErrors('artifact');

        $this->post('/studio/products/'.$this->product->id.'/releases', [
            'version' => '1.0.0',
            'release_notes' => 'An archive with excessive expansion from compression.',
            'artifact' => $this->zip(['large.txt' => str_repeat('A', 2 * 1024 * 1024)]),
            'rights_evidence'=>'I created this release and hold original redistribution rights under the active product license.',
            'rights_attested' => '1',
        ])->assertSessionHasErrors('artifact');

        $this->post('/studio/products/'.$this->product->id.'/releases', [
            'version' => '1.0.0',
            'release_notes' => 'A disguised script is not an accepted upload type.',
            'artifact' => UploadedFile::fake()->createWithContent('script.php', '<?php echo 1;'),
            'rights_evidence'=>'I created this release and hold original redistribution rights under the active product license.',
            'rights_attested' => '1',
        ])->assertSessionHasErrors('artifact');

        $this->assertDatabaseCount('product_file_versions', 0);
    }

    public function test_release_review_requires_actual_inspection_and_confirmation(): void
    {
        $version = $this->upload();
        $this->actingAs($this->reviewer)->patch('/dashboard/file-vault/'.$version->id, [
            'decision' => 'approved', 'confirm_review' => '1',
        ])->assertStatus(409);
        $this->post('/dashboard/file-vault/'.$version->id.'/inspect')->assertStatus(409);
        $this->post('/dashboard/file-vault/'.$version->id.'/scan')->assertRedirect();
        $this->post('/dashboard/file-vault/'.$version->id.'/inspect')->assertOk();
        $this->post('/dashboard/file-vault/'.$version->id.'/rights', [
            'decision'=>'verified',
            'rights_review_note'=>'Reviewed original source provenance and ownership rights against distribution policy.',
            'confirm_rights'=>'1',
        ])->assertRedirect();
        $this->patch('/dashboard/file-vault/'.$version->id, ['decision' => 'approved'])
            ->assertStatus(409);

        $this->patch('/dashboard/file-vault/'.$version->id, [
            'decision' => 'approved', 'confirm_review' => '1',
        ])->assertRedirect();
        $this->assertSame('approved', $version->fresh()->status);
    }

    public function test_only_super_admin_can_issue_non_payment_entitlements(): void
    {
        $version = $this->upload();
        $this->approve($version);

        $this->actingAs($this->reviewer)->post('/dashboard/entitlements', [
            'recipient_public_id' => $this->customer->public_id,
            'product_file_version_id' => $version->id,
            'grant_reason' => 'Manual complimentary license entitlement.',
            'expires_days' => '30',
            'confirm_no_payment' => '1',
        ])->assertForbidden();

        $this->actingAs($this->superAdmin)->post('/dashboard/entitlements', [
            'recipient_public_id' => $this->customer->public_id,
            'product_file_version_id' => $version->id,
            'grant_reason' => 'Manual complimentary license entitlement.',
            'expires_days' => '30',
        ])->assertSessionHasErrors('confirm_no_payment');
        $this->assertDatabaseCount('product_entitlements', 0);

        $entitlement = $this->grant($version);
        $this->assertTrue($entitlement->isActive());
        $this->assertSame($this->superAdmin->id, $entitlement->granted_by);
        $this->assertDatabaseCount('product_entitlements', 1);
    }

    public function test_entitled_customer_downloads_only_their_specific_approved_version(): void
    {
        $version = $this->upload();
        $this->approve($version);
        $entitlement = $this->grant($version);

        $url = route('account.downloads.file', $entitlement);
        $this->actingAs($this->stranger)->get($url)->assertNotFound();
        $this->actingAs($this->creator)->get($url)->assertNotFound();

        $this->actingAs($this->customer)->get($url)
            ->assertOk()
            ->assertDownload('bambodia-'.$this->product->public_id.'-1.0.0.zip')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertDatabaseCount('product_download_events', 1);
        $this->assertDatabaseHas('product_download_events', [
            'user_id' => $this->customer->id,
            'product_entitlement_id' => $entitlement->id,
            'product_file_version_id' => $version->id,
            'sha256' => $version->sha256,
        ]);
        $this->get('/account/downloads')->assertOk()->assertSee('Licensed Software Template');

        $second = $this->upload('1.1.0');
        $this->approve($second);
        $this->actingAs($this->customer)->get('/account/downloads')->assertDontSee('1.1.0');
    }

    public function test_revocation_expiry_and_disabled_release_block_download(): void
    {
        $version = $this->upload();
        $this->approve($version);
        $entitlement = $this->grant($version);

        $url = route('account.downloads.file', $entitlement);
        $this->actingAs($this->superAdmin)->patch('/dashboard/entitlements/'.$entitlement->id.'/revoke', [
            'revocation_reason' => 'Recipient access revoked after manual review.',
        ])->assertRedirect();
        $this->actingAs($this->customer)->get($url)->assertForbidden();

        $entitlement->update(['status' => 'active', 'revoked_at' => null, 'expires_at' => now()->subMinute()]);
        $this->get($url)->assertForbidden();

        $entitlement->update(['expires_at' => now()->addWeek()]);
        $this->actingAs($this->reviewer)->patch('/dashboard/file-vault/'.$version->id, [
            'decision' => 'disabled',
            'review_note' => 'Source file withdrawn due to a rights review concern.',
        ])->assertRedirect();
        $this->actingAs($this->customer)->get($url)->assertForbidden();
        $this->assertSame(0, ProductDownloadEvent::count());
    }

    public function test_file_tampering_blocks_access_without_recording_a_download(): void
    {
        $version = $this->upload();
        $this->approve($version);
        $entitlement = $this->grant($version);

        Storage::disk('local')->put($version->storage_path, 'corrupted-file-content');
        $this->actingAs($this->customer)->get(route('account.downloads.file', $entitlement))
            ->assertStatus(409);
        $this->assertDatabaseCount('product_download_events', 0);
    }

    public function test_regrant_has_permanent_history_without_duplicate_entitlement(): void
    {
        $version = $this->upload();
        $this->approve($version);
        $entitlement = $this->grant($version);

        $this->actingAs($this->superAdmin)->patch('/dashboard/entitlements/'.$entitlement->id.'/revoke', [
            'revocation_reason' => 'Revocation requested after a manual support review.',
        ])->assertRedirect();

        $this->post('/dashboard/entitlements', [
            'recipient_public_id' => $this->customer->public_id,
            'product_file_version_id' => $version->id,
            'grant_reason' => 'Renewed complimentary access after support confirmation.',
            'expires_days' => '7',
            'confirm_no_payment' => '1',
        ])->assertRedirect();

        $this->assertDatabaseCount('product_entitlements', 1);
        $this->assertDatabaseCount('product_entitlement_actions', 3);
        $this->assertSame(['granted', 'revoked', 'regranted'],
            $entitlement->actions()->reorder('id', 'asc')->pluck('action')->all());
        $this->assertTrue($entitlement->fresh()->isActive());
        $this->actingAs($this->customer)->get(route('account.downloads.file', $entitlement))->assertOk();
        $this->assertDatabaseCount('product_download_events', 1);
    }

    public function test_pdf_and_pptx_uploads_are_private_and_versioned(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('presentation.pdf', "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $this->actingAs($this->creator)->post('/studio/products/'.$this->product->id.'/releases', [
            'version' => '2.0.0',
            'release_notes' => 'A PDF presentation document licensed with this product.',
            'artifact' => $pdf,
            'rights_evidence'=>'I created this release and hold original redistribution rights under the active product license.',
            'rights_attested' => '1',
        ])->assertRedirect();
        $this->assertSame('pdf', ProductFileVersion::query()->where('version','2.0.0')->firstOrFail()->file_type);

        $pptxSource = $this->zip([
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>',
            'ppt/presentation.xml' => '<p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>',
        ]);
        $pptx = UploadedFile::fake()->createWithContent('presentation.pptx',
            file_get_contents($pptxSource->getRealPath()));
        $this->post('/studio/products/'.$this->product->id.'/releases', [
            'version' => '2.1.0',
            'release_notes' => 'A presentation package with standard OpenXML file entries.',
            'artifact' => $pptx,
            'rights_evidence'=>'I created this release and hold original redistribution rights under the active product license.',
            'rights_attested' => '1',
        ])->assertRedirect();
        $this->assertSame('pptx', ProductFileVersion::query()->where('version','2.1.0')->firstOrFail()->file_type);
        $this->assertSame(2, ProductFileVersion::query()->where('status','pending')->count());

        $this->post('/studio/products/'.$this->product->id.'/releases', [
            'version' => '2.2.0',
            'release_notes' => 'A spoofed file that has no proper PDF header.',
            'artifact' => UploadedFile::fake()->createWithContent('spoof.pdf', 'NOT_A_PDF'),
            'rights_evidence'=>'I created this release and hold original redistribution rights under the active product license.',
            'rights_attested' => '1',
        ])->assertSessionHasErrors('artifact');
    }

    public function test_customer_cannot_manually_grant_access_or_inspect_admin_originals(): void
    {
        $version = $this->upload();

        $this->actingAs($this->customer)->get('/dashboard/file-vault')->assertForbidden();
        $this->post('/dashboard/file-vault/'.$version->id.'/inspect')->assertForbidden();
        $this->post('/dashboard/entitlements', [
            'recipient_public_id' => $this->customer->public_id,
            'product_file_version_id' => $version->id,
        ])->assertForbidden();

        $this->actingAs($this->superAdmin)->post('/dashboard/entitlements', [
            'recipient_public_id' => $this->customer->public_id,
            'product_file_version_id' => $version->id,
            'grant_reason' => 'Attempted grant before verification.',
            'expires_days' => '30',
            'confirm_no_payment' => '1',
        ])->assertStatus(409);
        $this->assertDatabaseCount('product_entitlements', 0);
    }
}
