<?php

namespace Tests\Feature;

use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketCredential;
use App\Models\User;
use App\Support\VaultMalwareScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportTicketSecureWorkflowsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'customer'): User
    {
        return User::query()->create([
            'name' => 'Secure Support '.ucfirst($role),
            'email' => Str::uuid().'@example.test',
            'password' => 'TestPassword123!',
            'role' => $role,
        ]);
    }

    private function ticket(User $user): SupportTicket
    {
        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => 'Please fix my license installation',
            'status' => 'open',
            'issue_type' => 'installation',
        ]);
        $ticket->messages()->create([
            'user_id' => $user->id, 'body' => '<p>Cannot install this product.</p>',
        ]);
        return $ticket;
    }

    private function cleanScanner(): void
    {
        $this->app->instance(VaultMalwareScanner::class, new class extends VaultMalwareScanner {
            public function scanFile(string $absoluteFile): array
            {
                return ['status' => 'clean', 'engine' => 'Test scanner', 'note' => 'CI only'];
            }
        });
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->image('ticket-screenshot.png', 640, 360);
    }

    public function test_create_form_has_categories_linked_items_attachment_upload_and_encrypted_access_controls(): void
    {
        $owner = $this->user('freelancer');
        $listing = MarketplaceListing::query()->create([
            'user_id' => $owner->id, 'type' => 'service',
            'title' => 'My Custom WordPress Service', 'status' => 'published',
            'description' => 'A professional website deployment service.',
        ]);

        $this->actingAs($owner)->get('/studio/support-tickets/create')->assertOk()
            ->assertSee('name="issue_type"', false)
            ->assertSee('name="related_item"', false)
            ->assertSee('My Custom WordPress Service')
            ->assertSee('name="attachments[]"', false)
            ->assertSee('name="credential_password"', false)
            ->assertSee('data-ticket-credentials', false)
            ->assertSee('data-workspace="creator"', false);

        $this->post('/studio/support-tickets', [
            'subject' => 'Help with published listing installation',
            'message' => '<p>My real service listing needs technical help.</p>',
            'issue_type' => 'installation',
            'related_item' => 'listing:'.$listing->id,
        ])->assertRedirect();

        $ticket = SupportTicket::query()->where('user_id', $owner->id)
            ->where('subject', 'Help with published listing installation')->firstOrFail();
        $this->assertSame('installation', $ticket->issue_type);
        $this->assertSame('listing', $ticket->context_type);
        $this->assertSame((int) $listing->id, (int) $ticket->context_id);
        $this->get('/studio/support-tickets/'.$ticket->public_id)->assertOk()
            ->assertSee('My Custom WordPress Service')
            ->assertSee('Installation &amp; setup', false);
    }

    public function test_member_cannot_forge_someone_elses_listing_or_job_context(): void
    {
        $owner = $this->user('freelancer');
        $stranger = $this->user('customer');
        $listing = MarketplaceListing::query()->create([
            'user_id' => $owner->id, 'type' => 'product',
            'title' => 'Private seller product', 'status' => 'published',
            'description' => 'Digital product.',
        ]);
        $job = MarketplaceJob::query()->create([
            'client_id' => $owner->id, 'title' => 'Secret company hiring brief',
            'description' => str_repeat('Experienced PHP developer. ', 4),
            'category' => 'development', 'status' => 'published',
        ]);
        foreach (['listing:'.$listing->id, 'job:'.$job->id] as $target) {
            $this->actingAs($stranger)->post('/account/support-tickets', [
                'subject' => 'Attempt to attach someone else listing',
                'message' => '<p>Please attach the resource without permission.</p>',
                'related_item' => $target,
            ])->assertSessionHasErrors('related_item');
        }
        $this->assertDatabaseCount('support_tickets', 0);
    }

    public function test_purchased_listing_can_be_linked_only_after_verified_payment(): void
    {
        $buyer = $this->user();
        $seller = $this->user('freelancer');
        $listing = MarketplaceListing::query()->create([
            'user_id' => $seller->id, 'type' => 'product',
            'title' => 'Purchased Laravel Script', 'status' => 'published',
            'description' => 'Original and licensed digital product.',
        ]);
        $order = MarketplaceOrder::query()->create([
            'number' => (string) Str::uuid(), 'request_key' => (string) Str::uuid(),
            'buyer_id' => $buyer->id, 'seller_id' => $seller->id,
            'marketplace_listing_id' => $listing->id,
            'listing_title' => $listing->title, 'buyer_name' => $buyer->name,
            'seller_name' => $seller->name, 'total' => '45.00',
            'currency' => 'USD', 'status' => 'completed',
        ]);
        $payload = [
            'subject' => 'Product license installation and setup',
            'message' => '<p>Help me install this purchased Laravel script.</p>',
            'issue_type' => 'license',
            'related_item' => 'listing:'.$listing->id,
        ];
        $this->actingAs($buyer)->post('/account/support-tickets', $payload)
            ->assertSessionHasErrors('related_item');

        $method = \App\Models\CommercePaymentMethod::query()->create(['name'=>'Test bank transfer']);
        $order->payments()->create([
            'number'=>(string) Str::uuid(),
            'commerce_payment_method_id'=>$method->id,
            'method_name'=>$method->name,
            'provider'=>'bank_transfer', 'status'=>'paid', 'live_mode'=>true,
            'settlement_reference'=>'test-verified-paid-order',
        ]);
        $this->post('/account/support-tickets', $payload)->assertRedirect();
        $ticket = SupportTicket::query()->where('user_id', $buyer->id)->firstOrFail();
        $this->assertSame('listing', $ticket->context_type);
        $this->assertSame((int) $listing->id, (int) $ticket->context_id);
    }

    public function test_raster_reference_is_private_scanned_and_authorized_download_has_no_store_headers(): void
    {
        Storage::fake('local');
        $this->cleanScanner();
        $owner = $this->user();
        $other = $this->user();
        $staff = $this->user('staff');

        $this->actingAs($owner)->post('/account/support-tickets', [
            'subject' => 'Bug report with screenshot evidence',
            'message' => '<p>The installation crashes on the settings screen.</p>',
            'issue_type' => 'bug_report',
            'attachments' => [$this->png()],
        ])->assertRedirect();

        $ticket = SupportTicket::query()->where('user_id',$owner->id)->firstOrFail();
        $file = SupportTicketAttachment::query()->firstOrFail();
        $this->assertSame('clean', $file->scan_status);
        $this->assertSame((int) $ticket->id, (int) $file->support_ticket_id);
        Storage::disk('local')->assertExists($file->storage_path);
        $url = route('support.attachments.download', $file->public_id);

        $this->actingAs($other)->get($url)->assertNotFound();
        $preview = route('support.attachments.preview', $file->public_id);
        $this->actingAs($other)->get($preview)->assertNotFound();
        $visible = $this->actingAs($owner)->get($preview)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', (string) $visible->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $visible->headers->get('Cache-Control'));
        $download = $this->actingAs($owner)->get($url)->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', (string) $download->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $download->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $download->headers->get('Cache-Control'));
        $this->actingAs($staff)->get($url)->assertOk();

        Storage::disk('local')->put($file->storage_path, 'tampered-data');
        $this->actingAs($owner)->get($url)->assertStatus(409);
    }

    public function test_attachment_scan_checks_original_upload_bytes_and_private_copy_hash_matches(): void
    {
        Storage::fake('local');
        $scanner = new class extends VaultMalwareScanner {
            public ?string $scannedPath = null;

            public function scanFile(string $absoluteFile): array
            {
                $this->scannedPath = $absoluteFile;
                return ['status' => 'clean', 'engine' => 'Test scanner', 'note' => 'CI only'];
            }
        };
        $this->app->instance(VaultMalwareScanner::class, $scanner);

        $owner = $this->user();
        $upload = $this->png();
        $source = $upload->getRealPath();
        $hashBefore = hash_file('sha256', $source);

        $this->actingAs($owner)->post('/account/support-tickets', [
            'subject' => 'Upload screenshot of license activation error',
            'message' => '<p>Here is my screenshot for the installation team.</p>',
            'attachments' => [$upload],
        ])->assertRedirect();

        $attachment = SupportTicketAttachment::query()->firstOrFail();
        $this->assertSame($source, $scanner->scannedPath);
        $this->assertSame($hashBefore, $attachment->sha256);
        $this->assertSame($hashBefore, hash_file(
            'sha256', Storage::disk('local')->path($attachment->storage_path)
        ));
    }

    public function test_attachment_and_credential_icons_render_real_svg_paths_not_circle_fallback(): void
    {
        $owner = $this->user();
        $response = $this->actingAs($owner)->get('/account/support-tickets/create')
            ->assertOk();
        $response->assertSee('Reference attachments (optional)')
            ->assertSee('d="m21.44 11.05-9.19 9.19', false)
            ->assertSee('d="M8 10V7a4 4 0 0 1 8 0v3', false);
    }

    public function test_infected_or_unscanned_upload_fails_closed_without_creating_a_ticket(): void
    {
        Storage::fake('local');
        $owner = $this->user();
        config(['vault.scanner_binary' => '/missing/clamscan']);
        $this->actingAs($owner)->post('/account/support-tickets', [
            'subject' => 'Screenshot needs malware screening',
            'message' => '<p>Please inspect the attached image as reference.</p>',
            'attachments' => [$this->png()],
        ])->assertSessionHasErrors('attachments');
        $this->assertDatabaseCount('support_tickets', 0);
        $this->assertDatabaseCount('support_ticket_attachments', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('support-ticket-attachments'));
    }

    public function test_encrypted_secret_is_never_saved_in_message_or_html_and_only_authorized_staff_can_reveal(): void
    {
        $owner = $this->user();
        $assignedStaff = $this->user('staff');
        $otherStaff = $this->user('staff');
        $admin = $this->user('admin');

        $this->actingAs($owner)->post('/account/support-tickets', [
            'subject' => 'Please configure my staging application',
            'message' => '<p>Use the separate secure access form for temporary staging.</p>',
            'credential_label' => 'Staging WordPress access',
            'credential_username' => 'staging-user-example',
            'credential_password' => 'temporary-secret-987654',
            'credential_key' => 'restricted-token-123456',
            'credential_days' => 3,
        ])->assertRedirect();

        $ticket = SupportTicket::query()->where('user_id', $owner->id)->firstOrFail();
        $share = SupportTicketCredential::query()->firstOrFail();
        $this->assertStringNotContainsString('temporary-secret-987654', $share->ciphertext);
        $this->assertStringNotContainsString('staging-user-example', $share->ciphertext);
        $this->assertDatabaseMissing('support_messages', ['body'=>'temporary-secret-987654']);
        $this->actingAs($owner)->get('/account/support-tickets/'.$ticket->public_id)
            ->assertOk()->assertDontSee('temporary-secret-987654');

        $url = route('support.credentials.reveal', $share->public_id);
        $this->postJson($url)->assertForbidden();
        $this->actingAs($otherStaff)->postJson($url)->assertForbidden();

        $ticket->update(['assigned_to' => $assignedStaff->id]);
        $revealResponse = $this->actingAs($assignedStaff)->postJson($url)->assertOk()
            ->assertJsonPath('details.username', 'staging-user-example')
            ->assertJsonPath('details.password', 'temporary-secret-987654');
        $this->assertStringContainsString('private', (string) $revealResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $revealResponse->headers->get('Cache-Control'));

        $this->actingAs($admin)->postJson($url)->assertOk();
        $this->assertDatabaseHas('audit_events', [
            'actor_id'=>$assignedStaff->id, 'event'=>'support.credential.revealed',
        ]);
        $this->assertSame(2, $share->fresh()->view_count);
    }

    public function test_revocation_expiry_and_closed_ticket_make_credentials_unreadable(): void
    {
        $owner = $this->user();
        $admin = $this->user('admin');
        $ticket = $this->ticket($owner);

        $this->actingAs($owner)->post(route('support.credentials.store', $ticket->public_id), [
            'credential_label' => 'Limited temporary access',
            'credential_username' => 'temporary-user',
            'credential_password' => 'limited-test-password',
            'credential_days' => 1,
        ])->assertRedirect();

        $secret = SupportTicketCredential::query()->firstOrFail();
        $reveal = route('support.credentials.reveal', $secret->public_id);
        $this->actingAs($admin)->postJson($reveal)->assertOk();
        $this->actingAs($owner)->delete(route('support.credentials.revoke', $secret->public_id))->assertRedirect();
        $this->assertNull($secret->fresh()->ciphertext);
        $this->actingAs($admin)->postJson($reveal)->assertStatus(410);

        $this->actingAs($owner)->post(route('support.credentials.store', $ticket->public_id), [
            'credential_label' => 'Expires today',
            'credential_key' => 'short-lived-token',
            'credential_days' => 1,
        ])->assertRedirect();
        $expired = SupportTicketCredential::query()->latest('id')->firstOrFail();
        $expired->update(['expires_at'=>now()->subMinute()]);
        $this->actingAs($admin)->postJson(route('support.credentials.reveal', $expired->public_id))
            ->assertStatus(410);
        $this->artisan('support:purge-credentials')->assertSuccessful();
        $this->assertNull($expired->fresh()->ciphertext);

        $this->actingAs($owner)->post(route('support.credentials.store', $ticket->public_id), [
            'credential_label' => 'Still active',
            'credential_password' => 'new-support-password',
        ])->assertRedirect();
        $active = SupportTicketCredential::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)->patch(route('dashboard.support.status', $ticket->public_id), [
            'status' => 'closed',
        ])->assertRedirect();

        $this->assertNull($active->fresh()->ciphertext);
        $this->postJson(route('support.credentials.reveal', $active->public_id))->assertStatus(410);
    }

    public function test_staff_cannot_self_assign_a_ticket_after_private_secrets_are_shared(): void
    {
        $owner = $this->user();
        $staff = $this->user('staff');
        $admin = $this->user('admin');
        $ticket = $this->ticket($owner);

        $this->actingAs($owner)->post(route('support.credentials.store', $ticket->public_id), [
            'credential_label' => 'Temporary support token',
            'credential_key' => 'scoped-token-should-not-leak',
        ])->assertRedirect();

        $this->actingAs($staff)->patch(route('dashboard.support.assign', $ticket->public_id), [
            'assigned_to' => $staff->id,
        ])->assertForbidden();

        $share = SupportTicketCredential::query()->firstOrFail();
        $this->postJson(route('support.credentials.reveal', $share->public_id))->assertForbidden();

        $this->actingAs($admin)->patch(route('dashboard.support.assign', $ticket->public_id), [
            'assigned_to' => $staff->id,
        ])->assertRedirect();
        $this->actingAs($staff)->postJson(route('support.credentials.reveal', $share->public_id))
            ->assertOk()->assertJsonPath('details.key', 'scoped-token-should-not-leak');
    }

    public function test_credential_inputs_are_not_flashed_into_old_session_input_on_validation_error(): void
    {
        $owner = $this->user();
        $this->actingAs($owner)->post('/account/support-tickets', [
            'subject' => 'bad',
            'message' => '<p>Missing subject length but includes secure input.</p>',
            'credential_label' => 'Private portal',
            'credential_url' => 'https://example.com/admin',
            'credential_username' => 'sensitive-username',
            'credential_password' => 'sensitive-password',
            'credential_key' => 'sensitive-api-key',
        ])->assertSessionHasErrors('subject');

        $old = session()->getOldInput();
        foreach ([
            'credential_label', 'credential_url', 'credential_username',
            'credential_password', 'credential_key',
        ] as $field) {
            $this->assertArrayNotHasKey($field, $old);
        }
    }

    public function test_staff_may_add_reference_files_to_replies_without_disclosing_other_tickets(): void
    {
        Storage::fake('local');
        $this->cleanScanner();
        $owner = $this->user();
        $staff = $this->user('staff');
        $ticket = $this->ticket($owner);

        $this->actingAs($staff)->post(route('dashboard.support.reply', $ticket->public_id), [
            'message' => '',
            'status' => 'pending',
            'attachments' => [$this->png()],
        ])->assertRedirect();

        $attachment = SupportTicketAttachment::query()->firstOrFail();
        $this->assertSame((int) $staff->id, (int) $attachment->uploaded_by);
        $this->actingAs($owner)->get(route('account.support.show', $ticket->public_id))
            ->assertOk()->assertSee($attachment->original_name);
        $this->actingAs($this->user())->get(route('support.attachments.download', $attachment->public_id))
            ->assertNotFound();
    }

    /**
     * Use synthetic fixture bytes and a fake private disk; never fetch any real
     * customer attachments or production support-ticket data in these tests.
     */
    private function isolationFixture(User $owner): array
    {
        Storage::fake('local');

        $ticket = $this->ticket($owner);
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGMUabrEwMDAxMDAwMDAAAAQTAFs/wbMlQAAAABJRU5ErkJggg==',
            true
        );
        $this->assertIsString($bytes);
        $path = 'support-ticket-attachments/'.$ticket->id.'/'.Str::uuid().'.png';
        $this->assertTrue(Storage::disk('local')->put($path, $bytes));

        $attachment = SupportTicketAttachment::query()->create([
            'support_ticket_id' => $ticket->id,
            'support_message_id' => $ticket->messages()->firstOrFail()->id,
            'uploaded_by' => $owner->id,
            'storage_disk' => 'local',
            'storage_path' => $path,
            'original_name' => 'synthetic-fixture.png',
            'mime_type' => 'image/png',
            'bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'scan_status' => 'clean',
        ]);

        return [$ticket, $attachment];
    }

    public function test_guests_and_other_accounts_cannot_view_or_download_private_ticket_attachments(): void
    {
        $owner = $this->user();
        [$ticket, $attachment] = $this->isolationFixture($owner);
        $download = route('support.attachments.download', $attachment->public_id);
        $preview = route('support.attachments.preview', $attachment->public_id);

        $this->get($download)->assertRedirect(route('login'));
        $this->get($preview)->assertRedirect(route('login'));
        $this->get(route('account.support.show', $ticket->public_id))->assertRedirect(route('login'));

        foreach (['customer', 'freelancer'] as $role) {
            $other = $this->user($role);

            $this->actingAs($other)->get($download)->assertNotFound();
            $this->get($preview)->assertNotFound();
            $this->get(route('account.support.show', $ticket->public_id))->assertNotFound();
            $this->post(route('account.support.reply', $ticket->public_id), [
                'message' => '<p>Attempt to reply to someone else ticket.</p>',
            ])->assertNotFound();
            $this->get(route('account.support.index'))
                ->assertOk()->assertDontSee((string) $ticket->public_id);
        }

        $this->actingAs($owner)->get($download)->assertOk();
        $this->get($preview)->assertOk();
    }

    public function test_staff_with_revoked_support_permission_cannot_view_or_download_private_ticket(): void
    {
        $owner = $this->user();
        [$ticket, $attachment] = $this->isolationFixture($owner);
        $staff = $this->user('staff');
        $admin = $this->user('admin');
        $download = route('support.attachments.download', $attachment->public_id);
        $preview = route('support.attachments.preview', $attachment->public_id);

        $this->actingAs($staff)->get($download)->assertOk();
        $this->get($preview)->assertOk();

        \Illuminate\Support\Facades\DB::table('user_permission_overrides')->insert([
            'user_id' => $staff->id,
            'permission' => 'support_center',
            'allowed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get($download)->assertNotFound();
        $this->get($preview)->assertNotFound();
        $this->get(route('dashboard.support.show', $ticket->public_id))->assertForbidden();

        $this->actingAs($admin)->get($download)->assertOk();
        $this->get($preview)->assertOk();
    }

    public function test_unsafe_or_archived_support_attachments_are_not_served_even_to_owner(): void
    {
        $owner = $this->user();
        [$ticket, $attachment] = $this->isolationFixture($owner);
        $download = route('support.attachments.download', $attachment->public_id);
        $preview = route('support.attachments.preview', $attachment->public_id);

        $this->actingAs($owner)->get($download)->assertOk();

        $attachment->update(['scan_status' => 'error']);
        $this->get($download)->assertNotFound();
        $this->get($preview)->assertNotFound();

        $attachment->update(['scan_status' => 'clean']);
        $ticket->delete();

        $this->get($download)->assertNotFound();
        $this->get($preview)->assertNotFound();
    }
}
