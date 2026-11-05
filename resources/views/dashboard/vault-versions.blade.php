@extends('layouts.dashboard')
@section('title','Digital File Vault')
@section('content')
<div class="page-intro"><div class="eyebrow">Marketplace · Secure Fulfillment</div><h1>Digital File Vault</h1>
  <p>Inspect immutable source archives and verify release metadata before approval. Only approved, intact versions can be granted to customers.</p></div>
@if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
<section class="panel">
  <div class="panel-head"><h2>Product releases <span class="count">{{ $versions->total() }}</span></h2>
    <form method="get" class="table-search"><x-icon name="filter" size="17"/>
      <select name="status" class="table-select" aria-label="Filter release status">
        @foreach(['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','disabled'=>'Disabled','all'=>'All releases'] as $value=>$label)
          <option value="{{ $value }}" @selected($status===$value)>{{ $label }}</option>
        @endforeach
      </select><button class="btn subtle" type="submit">Filter</button>
    </form>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Product ID</th><th>Title</th><th>Version</th><th>Uploader</th><th>File</th><th>Status</th><th>Action</th></tr></thead>
    <tbody>
      @forelse($versions as $version)
        <tr>
          <td class="mono">{{ $version->product?->public_id }}</td>
          <td><strong>{{ $version->product?->title }}</strong></td>
          <td class="mono">{{ $version->version }}</td>
          <td>{{ $version->uploader?->name }}</td>
          <td>{{ strtoupper($version->file_type) }} · {{ number_format($version->byte_size / 1048576,2) }} MiB</td>
          <td><span class="role-pill">{{ ucfirst($version->status) }}</span></td>
          <td><button class="btn subtle compact" type="button" data-modal-open="vault-{{ $version->id }}"><x-icon name="settings" size="15"/> Configure</button>
            <dialog class="config-dialog wide-dialog" id="vault-{{ $version->id }}">
              <h2>{{ $version->product?->title }}</h2><p>Release {{ $version->version }} · {{ $version->original_filename }}</p>
              <p><strong>Uploader:</strong> {{ $version->uploader?->name }}</p>
              <p><strong>SHA-256:</strong> <span class="mono hash-line">{{ $version->sha256 }}</span></p>
              <p><strong>Declared rights:</strong> {{ $version->rights_attested_at?->format('d M Y H:i') }}</p>
              <h3>Release notes</h3><p class="review-text">{{ $version->release_notes }}</p>
              @if($version->review_note)<p><strong>Review note:</strong> {{ $version->review_note }}</p>@endif
              @if(in_array($version->status,['pending','approved'],true))
                <form method="post" action="{{ route('dashboard.vault.inspect',$version) }}">
                  @csrf
                  <p class="hint">Inspect the original safely and verify that you have permission to publish its contents. Downloading an original is logged.</p>
                  <button class="btn subtle" type="submit"><x-icon name="download" size="16"/> Inspect original source file</button>
                </form>
              @endif
              @if($version->status === 'pending')
                <form method="post" action="{{ route('dashboard.vault.decide',$version) }}">
                  @csrf @method('PATCH')
                  <label for="vault-note-{{ $version->id }}">Reviewer note (required if rejecting)</label>
                  <textarea id="vault-note-{{ $version->id }}" name="review_note" maxlength="2000" rows="3"></textarea>
                  <label class="check-label"><input type="checkbox" name="confirm_review" value="1"> I inspected the release, confirmed its source rights and authorize it for controlled download access.</label>
                  <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn subtle" type="submit" name="decision" value="rejected">Reject</button><button class="btn primary" type="submit" name="decision" value="approved">Approve release</button></div>
                </form>
              @elseif($version->status === 'approved')
                <form method="post" action="{{ route('dashboard.vault.decide',$version) }}">
                  @csrf @method('PATCH')
                  <label for="disable-note-{{ $version->id }}">Reason to disable this release immediately</label>
                  <textarea name="review_note" id="disable-note-{{ $version->id }}" minlength="10" maxlength="2000" rows="3" required></textarea>
                  <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn danger" type="submit" name="decision" value="disabled">Disable downloads</button></div>
                </form>
              @else<div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Close</button></div>@endif
            </dialog>
          </td>
        </tr>
      @empty<tr><td colspan="7" class="empty-cell">No file releases match the selected status.</td></tr>@endforelse
    </tbody>
  </table></div><x-pagination :paginator="$versions"/>
</section>
@endsection
