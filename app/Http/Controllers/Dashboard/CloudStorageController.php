<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CloudStorageConnection;
use App\Models\MediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class CloudStorageController extends Controller
{
    public function index(): View
    {
        return view('dashboard.cloud-storage', [
            'connection'=>CloudStorageConnection::query()->find(1),
            'currentDisk'=>config('filesystems.media_library_disk','local'),
            'envR2Configured'=>(bool)(config('filesystems.disks.r2.key') && config('filesystems.disks.r2.bucket')),
            'r2MediaCount'=>MediaAsset::query()->where('storage_disk','r2')->count(),
        ]);
    }

    private function checkEndpoint(string $endpoint): string
    {
        $normalized=rtrim(trim($endpoint),'/');
        // Strict provider allowlist. Never permit arbitrary endpoints or SSRF.
        abort_unless(preg_match('~\Ahttps://[a-f0-9]{32}(?:\.(?:eu|apac))?\.r2\.cloudflarestorage\.com\z~i',$normalized)===1,
            422,'Use the official HTTPS Cloudflare R2 account endpoint.');
        return $normalized;
    }

    public function save(Request $request): RedirectResponse
    {
        $values=$request->validate([
            'endpoint'=>['required','string','max:255'],
            'bucket'=>['required','string','min:3','max:63','regex:/\A[a-z0-9][a-z0-9.-]*[a-z0-9]\z/'],
            'access_key_id'=>['nullable','string','max:160'],
            'secret_access_key'=>['nullable','string','max:256'],
        ]);
        $existing=CloudStorageConnection::query()->find(1);
        $endpoint=$this->checkEndpoint($values['endpoint']);
        // Never repoint a disk containing stored media to another bucket.
        // Otherwise its database references would become orphaned without
        // any failure during the connection probe.
        if (MediaAsset::query()->where('storage_disk','r2')->exists()) {
            abort_unless(
                $existing
                && $existing->endpoint===$endpoint
                && $existing->bucket===$values['bucket'],
                409,
                'Existing R2 media depends on the current bucket. Migrate the files separately before changing the storage location.'
            );
        }
        $key=(string)($values['access_key_id']??'');
        $secret=(string)($values['secret_access_key']??'');
        if ($key==='') $key=$existing?->access_key_id??'';
        if ($secret==='') $secret=$existing?->secret_access_key??'';
        abort_unless($key!=='' && $secret!=='',422,'Access key and secret are required for a new connection.');

        $temporary=new CloudStorageConnection([
            'id'=>1,'provider'=>'cloudflare_r2','endpoint'=>$endpoint,
            'bucket'=>$values['bucket'],'access_key_id'=>$key,'secret_access_key'=>$secret,
        ]);
        $probe='bambodia-connection-probe/'.Str::uuid().'.txt';
        $payload=bin2hex(random_bytes(24));
        $wrote=false;
        $deleted=false;
        try {
            $disk=Storage::build($temporary->diskConfig());
            if (! $disk->put($probe,$payload)) {
                throw new \RuntimeException('Unable to write R2 probe');
            }
            $wrote=true;
            if ($disk->get($probe)!==$payload) {
                throw new \RuntimeException('R2 probe read verification failed');
            }
        } catch (\Throwable $error) {
            // Never echo provider credentials, raw exception messages or probe contents.
            return back()->withErrors([
                'connection'=>'Cloudflare R2 write/read test failed. Check bucket permissions, endpoint and credentials.',
            ]);
        } finally {
            if ($wrote && isset($disk)) {
                try { $deleted=(bool)$disk->delete($probe); } catch (\Throwable) {}
            }
        }
        if (! $deleted) {
            return back()->withErrors([
                'connection'=>'R2 probe cleanup failed. Verify bucket delete permissions before saving.',
            ]);
        }

        CloudStorageConnection::query()->updateOrCreate(['id'=>1],[
            'provider'=>'cloudflare_r2','endpoint'=>$endpoint,'bucket'=>$values['bucket'],
            'access_key_id'=>$key,'secret_access_key'=>$secret,
            'upload_mode'=>$existing?->upload_mode??'inherit',
            'verified_at'=>now(),'updated_by'=>$request->user()->id,
        ]);
        AuditEvent::record('cloud.connection.verified',$request->user(),$request);
        return back()->with('status','R2 connection verified and credentials saved encrypted. Upload mode remains unchanged.');
    }

    public function mode(Request $request): RedirectResponse
    {
        $values=$request->validate(['upload_mode'=>['required',Rule::in(['inherit','local','r2'])]]);
        $connection=CloudStorageConnection::query()->findOrFail(1);
        if ($values['upload_mode']==='r2') {
            abort_unless($connection->verified_at?->greaterThan(now()->subDay()),422,
                'Test the R2 connection successfully within the previous 24 hours before enabling uploads.');
        }
        $connection->update([
            'upload_mode'=>$values['upload_mode'],'updated_by'=>$request->user()->id,
        ]);
        AuditEvent::record('cloud.upload_mode.changed',$request->user(),$request);
        return back()->with('status','New Media Library uploads now follow the selected storage mode. Existing media stays on its original disk.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $connection=CloudStorageConnection::query()->findOrFail(1);
        abort_if(MediaAsset::query()->where('storage_disk','r2')->exists(),409,
            'Existing R2 media requires these credentials. Keep the connection until all media is migrated.');
        $connection->delete();
        AuditEvent::record('cloud.connection.deleted',$request->user(),$request);
        return back()->with('status','Stored R2 credentials removed. Environment-based configuration remains unchanged.');
    }
}
