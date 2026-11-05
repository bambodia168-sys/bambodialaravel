@extends('layouts.main')
@section('title','Product Releases')
@section('content')
<div class="container inner-page">
  <div class="page-intro">
    <div class="eyebrow">Creator Studio · Secure Digital File Vault</div>
    <h1>{{ $product->title }}</h1>
    <p>Product ID {{ $product->public_id }} · Upload immutable, privately stored originals. Each version is reviewed independently before it can be granted to an account.</p>
    <p><a class="btn subtle compact" href="{{ route('studio.products.index') }}">Back to Product Manager</a></p>
  </div>
  @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
  <section class="panel">
    <div class="panel-head">
      <h2>File versions <span class="count">{{ $versions->total() }}</span></h2>
      <button class="btn primary" type="button" data-modal-open="upload-release"><x-icon name="upload" size="16"/> Upload new version</button>
    </div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Version</th><th>File</th><th>Size</th><th>Integrity</th><th>Review</th><th>Submitted</th><th>Action</th></tr></thead>
      <tbody>
        @forelse($versions as $version)
          <tr>
            <td><strong class="mono">{{ $version->version }}</strong></td>
            <td>{{ $version->original_filename }}<div class="text-muted">{{ strtoupper($version->file_type) }}</div></td>
            <td>{{ number_format($version->byte_size / 1048576, 2) }} MiB</td>
            <td class="mono"><small>{{ substr($version->sha256, 0, 12) }}…</small></td>
            <td><span class="role-pill">{{ ucfirst($version->status) }}</span></td>
            <td>{{ $version->created_at->format('d M Y') }}</td>
            <td>
              <button type="button" class="btn subtle compact" data-modal-open="version-{{ $version->id }}"><x-icon name="settings" size="15"/> Configure</button>
              <dialog class="config-dialog wide-dialog" id="version-{{ $version->id }}">
                <h2>Release {{ $version->version }}</h2>
                <p>{{ $version->original_filename }} · {{ ucfirst($version->status) }}</p>
                <h3>Release notes</h3><p class="review-text">{{ $version->release_notes }}</p>
                <p><strong>SHA-256:</strong> <span class="mono hash-line">{{ $version->sha256 }}</span></p>
                <p><strong>Rights attested:</strong> {{ $version->rights_attested_at->format('d M Y H:i') }}</p>
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
      <label class="check-label">
        <input type="checkbox" name="rights_attested" value="1" required>
        I own or am authorized to distribute all contents of this original file under this product's published license.
      </label>
      <p class="hint">The platform inspects archive structure and integrity, but this does not certify a file as malware-free. Release approval is a separate administrator action.</p>
      <div class="dialog-actions">
        <button type="button" class="btn subtle" data-modal-close>Cancel</button>
        <button type="submit" class="btn primary">Upload for review</button>
      </div>
    </form>
  </dialog>
</div>
@endsection
