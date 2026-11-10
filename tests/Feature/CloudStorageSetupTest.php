<?php

namespace Tests\Feature;

use App\Models\CloudStorageConnection;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CloudStorageSetupTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role='super_admin'): User
    {
        return User::query()->create([
            'name'=>'Storage test '.$role,
            'email'=>uniqid($role,true).'@example.test',
            'password'=>'SafeTestingPassword12345!',
            'role'=>$role,
        ]);
    }

    private function valid(): array
    {
        return [
            'endpoint'=>'https://0123456789abcdef0123456789abcdef.r2.cloudflarestorage.com',
            'bucket'=>'bambodia-media',
            'access_key_id'=>'r2-mock-key-1234567',
            'secret_access_key'=>'r2-mock-secret-value-123456',
        ];
    }

    private function verified(): CloudStorageConnection
    {
        return CloudStorageConnection::query()->create([
            'id'=>1,
            'provider'=>'cloudflare_r2',
            'endpoint'=>$this->valid()['endpoint'],
            'bucket'=>'bambodia-media',
            'access_key_id'=>'fixture-secret-key',
            'secret_access_key'=>'fixture-secret-access',
            'upload_mode'=>'inherit',
            'verified_at'=>now(),
        ]);
    }

    public function test_super_admin_can_open_wizard_without_secrets_being_rendered(): void
    {
        $this->actingAs($this->member())->get(route('dashboard.cloud-storage.index'))
            ->assertOk()->assertSee('Cloud Storage')->assertSee('Test connection');
        $connection=$this->verified();
        $this->get(route('dashboard.cloud-storage.index'))->assertOk()
            ->assertSee('bambodia-media')
            ->assertDontSee('fixture-secret-key')
            ->assertDontSee('fixture-secret-access');
        $raw=$connection->getRawOriginal('secret_access_key');
        $this->assertNotEquals('fixture-secret-access',$raw);
        $this->assertNotEquals('fixture-secret-key',$connection->getRawOriginal('access_key_id'));
    }

    public function test_wizard_probes_real_filesystem_contract_before_saving_encrypted_credentials(): void
    {
        $admin=$this->member();
        $disk=Storage::fake('r2-fixture');
        Storage::shouldReceive('build')
            ->once()
            ->withArgs(fn (array $settings) =>
                $settings['key']==='r2-mock-key-1234567'
                && $settings['secret']==='r2-mock-secret-value-123456'
                && $settings['bucket']==='bambodia-media'
                && $settings['visibility']==='private')
            ->andReturn($disk);
        $this->actingAs($admin)->post(route('dashboard.cloud-storage.verify'),$this->valid())
            ->assertRedirect()->assertSessionHas('status');

        $connection=CloudStorageConnection::query()->findOrFail(1);
        $this->assertNotNull($connection->verified_at);
        $this->assertSame('inherit',$connection->upload_mode);
        $this->assertNotEquals($this->valid()['secret_access_key'],$connection->getRawOriginal('secret_access_key'));
        $this->assertDatabaseHas('audit_events',['event'=>'cloud.connection.verified']);
        $this->assertCount(0,$disk->allFiles('bambodia-connection-probe'));
    }

    public function test_r2_cannot_be_enabled_before_verification_and_wrong_host_is_rejected(): void
    {
        $user=$this->member();
        $connection=$this->verified();
        $connection->update(['verified_at'=>now()->subDays(3)]);
        $this->actingAs($user)->patch(route('dashboard.cloud-storage.mode'),[
            'upload_mode'=>'r2',
        ])->assertStatus(422);
        $this->assertSame('inherit',$connection->fresh()->upload_mode);

        $this->post(route('dashboard.cloud-storage.verify'),array_merge($this->valid(),[
            'endpoint'=>'https://127.0.0.1/r2',
        ]))->assertStatus(422);

        $connection->update(['verified_at'=>now()]);
        $this->patch(route('dashboard.cloud-storage.mode'),[
            'upload_mode'=>'r2',
        ])->assertRedirect();
        $this->assertSame('r2',$connection->fresh()->upload_mode);
        $this->assertDatabaseHas('audit_events',['event'=>'cloud.upload_mode.changed']);
        $this->patch(route('dashboard.cloud-storage.mode'),[
            'upload_mode'=>'local',
        ])->assertRedirect();
        $this->assertSame('local',$connection->fresh()->upload_mode);
    }

    public function test_failed_probe_never_saves_credentials(): void
    {
        $admin=$this->member();
        Storage::shouldReceive('build')->once()->andThrow(new \RuntimeException('fake access key is invalid'));
        $this->actingAs($admin)->post(route('dashboard.cloud-storage.verify'),$this->valid())
            ->assertRedirect()->assertSessionHasErrors('connection');
        $this->assertDatabaseCount('cloud_storage_connections',0);
        $this->assertDatabaseMissing('audit_events',['event'=>'cloud.connection.verified']);
        $this->get(route('dashboard.cloud-storage.index'))->assertDontSee($this->valid()['secret_access_key']);
    }

    public function test_only_super_admin_can_change_cloud_storage_and_credentials(): void
    {
        foreach (['customer','staff','admin'] as $role) {
            $this->actingAs($this->member($role))
                ->get(route('dashboard.cloud-storage.index'))->assertForbidden();
            $this->post(route('dashboard.cloud-storage.verify'),$this->valid())->assertForbidden();
            $this->patch(route('dashboard.cloud-storage.mode'),['upload_mode'=>'r2'])->assertForbidden();
            $this->delete(route('dashboard.cloud-storage.destroy'))->assertForbidden();
        }
    }

    public function test_connection_deletion_requires_no_r2_media(): void
    {
        $this->actingAs($this->member());
        $connection=$this->verified();
        $this->delete(route('dashboard.cloud-storage.destroy'))->assertRedirect();
        $this->assertDatabaseMissing('cloud_storage_connections',['id'=>1]);
    }
}
