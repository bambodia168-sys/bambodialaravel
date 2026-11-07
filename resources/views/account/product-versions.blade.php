@extends('layouts.workspace')
@section('workspace','creator')
@section('title','Product Releases')
@section('content')
<div class="container inner-page">
  <div class="page-intro">
    <div class="eyebrow">Creator Studio · Secure Digital File Vault</div>
    <h1>{{ $product->title }}</h1>
    <p>Product ID {{ $product->public_id }} · Upload immutable, privately stored originals. Each version is reviewed independently before it can be granted to an account.</p>
    <p><a class="btn subtle compact" href="{{ route('studio.products.index') }}">Back to Product Manager</a></p>
  </div>
  <section class="panel">
    <div class="panel-head">
      <h2>File versions <span class="count">{{ $versions->total() }}</span></h2>
      <button class="btn primary" type="button" data-modal-open="upload-release"><x-icon name="upload" size="16"/> Upload new version</button>
    </div>
    <nav class="workspace-queue-toolbar workspace-queue-tabs" aria-label="Your file version statuses">
      @foreach(['all'=>'All','pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','disabled'=>'Disabled'] as $value=>$label)
        <a href="{{ route('studio.vault.index',array_merge(['product'=>$product],$value==='all'?[]:['status'=>$value])) }}"
          class="{{ $status===$value?'is-active':'' }}" @if($status===$value)aria-current="page"@endif>
          {{ $label }} <span>{{ $value==='all'?$counts->sum():(int)$counts->get($value,0) }}</span>
        </a>
      @endforeach
    </nav>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Version</th><th>File</th><th>Size</th><th>Integrity</th><th>Review</th><th>Submitted</th><th>Action</th></tr></thead>
      <tbody>
        @forelse($versions as $version)
          <tr>
            <td><strong class="mono">{{ $version->version }}</strong></td>
            <td>{{ $version->original_filename }}<div class="text-muted">{{ strtoupper($version->file_type) }}</div></td>
            <td>{{ number_format($version->byte_size / 1048576, 2) }} MiB</td>
            <td class="mono"><small>{{ substr($version->sha256, 0, 12) }}…</small></td>
            <td><span class="role-pill">{{ ucfirst($version->status) }}</span>
              <div class="text-muted">Malware: {{ ucfirst($version->malware_scan_status) }}</div>
              <div class="text-muted">Rights: {{ ucfirst($version->rights_review_status) }}</div></td>
            <td>{{ $version->created_at->format('d M Y') }}</td>
            <td>
              <x-row-action-menu :id="'creator-release-'.$version->id" :label="'Release '.$version->version"><button type="button" data-modal-open="version-{{ $version->id }}"><x-icon name="settings" size="16"/> Configure</button></x-row-action-menu>
              <dialog class="config-dialog wide-dialog" id="version-{{ $version->id }}">
                <h2>Release {{ $version->version }}</h2>
                <p>{{ $version->original_filename }} · {{ ucfirst($version->status) }}</p>
                <h3>Release notes</h3><p class="review-text">{{ $version->release_notes }}</p>
                <p><strong>SHA-256:</strong> <span class="mono hash-line">{{ $version->sha256 }}</span></p>
                <p><strong>Rights attested:</strong> {{ $version->rights_attested_at->format('d M Y H:i') }}</p>
                @if($version->rights_evidence)<p><strong>Declared rights evidence:</strong> {{ $version->rights_evidence }}</p>@endif
                @if($version->rights_review_note)<p><strong>Rights review:</strong> {{ $version->rights_review_note }}</p>@endif
                <p><strong>Malware screening:</strong> {{ ucfirst($version->malware_scan_status) }} — {{ $version->malware_scan_engine ?? 'No verified scanner' }}</p>
                @if($version->malware_scanned_at)<p>Last checked: {{ $version->malware_scanned_at->format('d M Y H:i') }}</p>@endif
                @if(in_array($version->status,['pending','approved'],true))
                  <form method="post" action="{{ route('studio.vault.rights',[$product,$version]) }}">
                    @csrf @method('PATCH')
                    <h3>Distribution authorization evidence</h3>
                    <label for="release-rights-{{ $version->id }}">Ownership or distribution grant, rights holder and verification details</label>
                    <textarea id="release-rights-{{ $version->id }}" name="rights_evidence" minlength="30" maxlength="3000" rows="4" required>{{ $version->rights_evidence }}</textarea>
                    <label for="release-source-{{ $version->id }}">Evidence document URL (optional HTTPS)</label>
                    <input type="url" id="release-source-{{ $version->id }}" name="rights_source_url" value="{{ $version->rights_source_url }}" maxlength="500">
                    <p class="hint">Updating your declaration resets the prior rights review. It cannot silently replace the immutable binary.</p>
                    <button type="submit" class="btn subtle">Update rights declaration</button>
                  </form>
                @endif
                @if($version->review_note)<p><strong>Reviewer note:</strong> {{ $version->review_note }}</p>@endif
                <p class="hint">File versions cannot be replaced or deleted by creators. Approved releases become available only to accounts granted access by Super Admins; no checkout is connected.</p>
                <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Close</button></div>
              </dialog>
            </td>
          </tr>
        @empty<tr><td class="empty-cell" colspan="7">No source-file versions have been uploaded for this product.</td></tr>@endforelse
      </tbody>
    </table></div>
    <x-pagination :paginator="$versions"/>
  </section>
  <dialog class="config-dialog wide-dialog" id="upload-release">
    <form method="post" action="{{ route('studio.vault.store', $product) }}" enctype="multipart/form-data">
      @csrf
      <h2>Upload an original release</h2>
      <p>Accepted original archives: ZIP, PPTX and PDF. Maximum 25 MiB. Files stay in private storage and cannot be replaced under the same version number.</p>
      <label for="vault-version">Version number</label>
      <input id="vault-version" name="version" required pattern="[0-9]+\.[0-9]+\.[0-9]+" placeholder="1.0.0" maxlength="32">
      <label for="vault-notes">Release notes</label>
      <textarea id="vault-notes" name="release_notes" rows="5" minlength="20" maxlength="3000" required></textarea>
      <label for="vault-artifact">Original downloadable file</label>
      <input id="vault-artifact" type="file" name="artifact" accept=".zip,.pptx,.pdf" required>
      <label for="vault-rights-evidence">Ownership or license provenance and distribution authorization</label>
      <textarea name="rights_evidence" id="vault-rights-evidence" rows="4" minlength="30" maxlength="3000" required placeholder="Identify rights holder, copyright provenance, distribution license and reviewable proof."></textarea>
      <label for="vault-rights-source">Rights documentation URL (optional HTTPS)</label>
      <input type="url" name="rights_source_url" id="vault-rights-source" placeholder="https://" maxlength="500">
      <label class="check-label">
        <input type="checkbox" name="rights_attested" value="1" required>
        I own or am authorized to distribute all contents of this original file under this product's published license.
      </label>
      <p class="hint">New releases remain blocked until real ClamAV scanning, independent documented distribution-rights review and final administrator approval. Archive structure and SHA-256 checks are not evidence that a file is malware-free.</p>
      <div class="dialog-actions">
        <button type="button" class="btn subtle" data-modal-close>Cancel</button>
        <button type="submit" class="btn primary">Upload for review</button>
      </div>
    </form>
  </dialog>
</div>
@endsection
