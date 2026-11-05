@extends('layouts.dashboard')
@section('title','Digital File Vault')
@section('content')
<div class="page-intro"><div class="eyebrow">Marketplace · Secure Fulfillment</div><h1>Digital File Vault</h1>
  <p>Fail-closed release governance: scan original bytes with ClamAV, inspect the screened file, review ownership evidence, then approve. A missing, failed, outdated or infected scan blocks customer access.</p></div>
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
          <td><span class="role-pill">{{ ucfirst($version->status) }}</span>
            <div class="text-muted">AV: {{ ucfirst($version->malware_scan_status) }}</div>
            <div class="text-muted">Rights: {{ ucfirst($version->rights_review_status) }}</div></td>
          <td><button class="btn subtle compact" type="button" data-modal-open="vault-{{ $version->id }}"><x-icon name="settings" size="15"/> Configure</button>
            <dialog class="config-dialog wide-dialog" id="vault-{{ $version->id }}">
              <h2>{{ $version->product?->title }}</h2><p>Release {{ $version->version }} · {{ $version->original_filename }}</p>
              <p><strong>Uploader:</strong> {{ $version->uploader?->name }}</p>
              <p><strong>SHA-256:</strong> <span class="mono hash-line">{{ $version->sha256 }}</span></p>
              <p><strong>Declared rights:</strong> {{ $version->rights_attested_at?->format('d M Y H:i') }}</p>
              <p><strong>Distribution provenance:</strong> {{ $version->rights_evidence ?: 'Missing (legacy file: creator must submit evidence)' }}</p>
              @if($version->rights_source_url)
                <a class="btn subtle compact" href="{{ $version->rights_source_url }}" target="_blank" rel="noopener noreferrer">Open creator-supplied rights reference</a>
              @endif
              <p><strong>Rights review:</strong> {{ ucfirst($version->rights_review_status) }} · {{ $version->rights_review_note ?? 'Not reviewed' }}</p>
              <p><strong>Malware screening:</strong> {{ ucfirst($version->malware_scan_status) }} · {{ $version->malware_scan_engine ?? 'Scanner unavailable' }}</p>
              <p><strong>Scan date:</strong> {{ $version->malware_scanned_at?->format('d M Y H:i') ?? 'Not screened' }}</p>
              <p><strong>Scan note:</strong> {{ $version->malware_scan_note ?? 'No reliable verdict yet' }}</p>
              @if(in_array($version->status,['pending','approved'],true))
                <form method="post" action="{{ route('dashboard.vault.scan',$version) }}">
                  @csrf
                  <p class="hint">Runs actual ClamAV on the original private file. Missing scanner, old signatures or a failed scan cannot authorize download. Large files may take time to inspect.</p>
                  <button class="btn subtle" type="submit"><x-icon name="shield" size="16"/> Run antivirus screening</button>
                </form>
              @endif
              @if(in_array($version->status,['pending','approved'],true) && $version->inspected_at!==null)
                <form method="post" action="{{ route('dashboard.vault.rights',$version) }}">
                  @csrf
                  <h3>Independent distribution rights review</h3>
                  <label for="rights-note-{{ $version->id }}">Actual evidence checked and specific limitations (required)</label>
                  <textarea name="rights_review_note" id="rights-note-{{ $version->id }}" minlength="30" maxlength="2000" rows="4" required>{{ $version->rights_review_note }}</textarea>
                  <label class="check-label"><input type="checkbox" name="confirm_rights" value="1"> I examined the release and its source rights independently of the uploading account.</label>
                  <div class="dialog-actions">
                    <button class="btn subtle" type="submit" name="decision" value="rejected">Reject rights claim</button>
                    <button class="btn primary" type="submit" name="decision" value="verified">Record rights review</button>
                  </div>
                </form>
              @endif
              <h3>Release notes</h3><p class="review-text">{{ $version->release_notes }}</p>
              @if($version->review_note)<p><strong>Review note:</strong> {{ $version->review_note }}</p>@endif
              @if(in_array($version->status,['pending','approved'],true))
                <form method="post" action="{{ route('dashboard.vault.inspect',$version) }}">
                  @csrf
                  <p class="hint">Requires a current clean antivirus result. Use an isolated review workstation. File inspection is logged.</p>
                  <button class="btn subtle" type="submit"><x-icon name="download" size="16"/> Inspect original source file</button>
                </form>
              @endif
              @if($version->status === 'pending')
                <form method="post" action="{{ route('dashboard.vault.decide',$version) }}">
                  @csrf @method('PATCH')
                  <label for="vault-note-{{ $version->id }}">Reviewer note (required if rejecting)</label>
                  <textarea id="vault-note-{{ $version->id }}" name="review_note" maxlength="2000" rows="3"></textarea>
                  <label class="check-label"><input type="checkbox" name="confirm_review" value="1"> I confirm the stored binary matches the screened SHA-256, the ClamAV verdict is current, and distribution rights have an independent recorded review.</label>
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
