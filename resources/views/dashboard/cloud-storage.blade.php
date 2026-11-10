@extends('layouts.dashboard')
@section('title','Cloud Storage')
@section('content')
<div class="page-intro">
  <div class="eyebrow">Assets &amp; Communication / Media Infrastructure</div>
  <h1>Cloud Storage</h1>
  <p>Connect your existing Media Library to Cloudflare R2 in one place. Verify access before enabling new uploads. Previously uploaded media never moves automatically.</p>
</div>

<div class="metrics">
  <div class="metric"><span><x-icon name="shield-check" size="18"/> Connection</span>
    <strong style="font-size:20px">{{ $connection?->verified_at ? 'Verified' : ($envR2Configured ? 'Environment R2' : 'Not configured') }}</strong>
    <small>{{ $connection?->verified_at?->format('d M Y H:i').' UTC' ?? 'No new credential test completed' }}</small>
  </div>
  <div class="metric"><span><x-icon name="archive" size="18"/> New uploads</span>
    <strong style="font-size:20px">{{ strtoupper($currentDisk) }}</strong>
    <small>Current Media Library disk</small>
  </div>
  <div class="metric"><span><x-icon name="image" size="18"/> Stored R2 assets</span>
    <strong>{{ number_format($r2MediaCount) }}</strong>
    <small>Existing assets keep their original storage disk</small>
  </div>
</div>

<section class="panel" style="margin-top:20px">
  <div class="panel-head"><div><h2>Cloudflare R2 setup</h2>
    <p>Credentials are encrypted at rest, never displayed after saving, and tested with a private temporary object.</p></div></div>
  <form class="settings-form" method="post" action="{{ route('dashboard.cloud-storage.verify') }}" autocomplete="off">
    @csrf
    <label for="r2-endpoint">R2 account endpoint (HTTPS)</label>
    <input id="r2-endpoint" name="endpoint" type="url" required maxlength="255"
      placeholder="https://ACCOUNTID.r2.cloudflarestorage.com"
      value="{{ $connection?->endpoint ?? '' }}" autocomplete="off">
    <label for="r2-bucket">Bucket name</label>
    <input id="r2-bucket" name="bucket" required maxlength="63"
      placeholder="bambodia-media" value="{{ $connection?->bucket ?? '' }}" autocomplete="off">
    <label for="r2-key">Access key ID</label>
    <input id="r2-key" name="access_key_id" type="password" maxlength="160"
      placeholder="{{ $connection ? 'Leave blank to retain encrypted key' : 'Enter Cloudflare R2 access key ID' }}" autocomplete="new-password">
    <label for="r2-secret">Secret access key</label>
    <input id="r2-secret" name="secret_access_key" type="password" maxlength="256"
      placeholder="{{ $connection ? 'Leave blank to retain encrypted secret' : 'Enter R2 secret access key' }}" autocomplete="new-password">
    <p class="hint">Cloudflare R2 credentials require read, write and delete permissions for the selected bucket. A private probe is written, read back and deleted before configuration is saved. Existing object references are never modified.</p>
    <div class="dialog-actions"><button class="btn primary" type="submit"><x-icon name="shield-check" size="16"/> Test connection &amp; save</button></div>
  </form>
</section>

<section class="panel" style="margin-top:20px">
  <div class="panel-head"><div><h2>Media Library upload destination</h2>
    <p>Only new uploads are affected. Default preserves the existing environment setting.</p></div></div>
  <form class="settings-form" method="post" action="{{ route('dashboard.cloud-storage.mode') }}">
    @csrf @method('PATCH')
    <label for="upload-mode">Storage for new assets</label>
    <select id="upload-mode" name="upload_mode" @disabled(!$connection)>
      <option value="inherit" @selected(($connection?->upload_mode??'inherit')==='inherit')>Follow current server setting</option>
      <option value="local" @selected($connection?->upload_mode==='local')>Private local storage</option>
      <option value="r2" @selected($connection?->upload_mode==='r2')>Cloudflare R2 (requires recent connection test)</option>
    </select>
    <div class="dialog-actions"><button class="btn primary" type="submit" @disabled(!$connection)>Save upload mode</button></div>
  </form>
</section>

@if($connection)
  <section class="panel" style="margin-top:20px">
    <div class="panel-head"><div><h2>Disconnect credentials</h2><p>Cannot remove credentials while existing R2 assets rely on this connection.</p></div>
      <form method="post" action="{{ route('dashboard.cloud-storage.destroy') }}">
        @csrf @method('DELETE')
        <button class="btn subtle compact" type="submit" @disabled($r2MediaCount>0)>Remove connection</button>
      </form>
    </div>
  </section>
@endif
@endsection
