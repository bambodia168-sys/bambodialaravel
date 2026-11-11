@extends('layouts.dashboard')
@section('title', 'Preview Visibility Review')
@section('content')
<div class="page-intro">
  <div class="eyebrow">Marketplace governance</div>
  <h1>Preview Visibility Review</h1>
  <p>Review individually marked preview items, protect customer activity, and preserve all records. Withdrawal is disabled until independent hosting and backup acceptance.</p>
</div>

@if(! $writesEnabled)
  <section class="panel" aria-label="Release safety lock">
    <div class="panel-head"><h2><x-icon name="shield" size="18"/> Changes locked</h2></div>
    <p>Review and dependency checks are available. Publication changes are disabled by default. A separately approved release, verified database backup, owner confirmation and rights review are required before enabling this control.</p>
  </section>
@endif

<section class="panel">
  <div class="panel-head">
    <div><h2>Preview items <span class="count">{{ $records->total() }}</span></h2>
      <p>Only explicit test-marked records in published or withdrawn status appear here. Ten per page.</p>
    </div>
    <form class="table-search" method="get" action="{{ route('dashboard.preview-visibility.index') }}">
      <select name="kind" class="table-select" aria-label="Content type">
        <option value="listing" @selected($kind === 'listing')>Listings</option>
        <option value="job" @selected($kind === 'job')>Jobs</option>
      </select>
      <select name="status" class="table-select" aria-label="Visibility status">
        <option value="published" @selected($status === 'published')>Published</option>
        <option value="withdrawn" @selected($status === 'withdrawn')>Withdrawn</option>
        <option value="all" @selected($status === 'all')>All</option>
      </select>
      <button class="btn subtle compact" type="submit">Apply</button>
    </form>
  </div>

  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Public ID</th><th>Title</th><th>Status</th><th>Owner</th><th>Dependency assessment</th><th>Action</th></tr></thead>
    <tbody>
    @forelse($records as $record)
      @if(isset($reviews[$record->id]))
        @php($review = $reviews[$record->id])
        <tr>
          <td class="mono">{{ $record->public_id }}</td>
          <td><strong>{{ $record->title }}</strong></td>
          <td><span class="role-pill">{{ ucfirst($record->status) }}</span></td>
          <td>{{ $review['owner_exists'] ? ucfirst($review['owner_role']) : 'Owner missing' }}</td>
          <td>
            <strong>{{ str_replace('_', ' ', ucfirst($review['review_status'])) }}</strong>
            <div class="review-text">
              {{ $review['customer_activity_blocked'] ? 'Customer activity: review blocked' : 'No linked customer activity reported' }}
              · Media references: {{ $review['attached_media_references'] }}
            </div>
          </td>
          <td>
            <x-row-action-menu :id="'preview-'.$kind.'-'.$record->id" :label="$record->title">
              <button type="button" data-modal-open="preview-{{ $kind }}-{{ $record->id }}"><x-icon name="settings" size="16"/> Inspect</button>
            </x-row-action-menu>
          </td>
        </tr>
      @endif
    @empty
      <tr><td colspan="6" class="empty-cell">There are no matching preview records.</td></tr>
    @endforelse
    </tbody>
  </table></div>
  <x-pagination :paginator="$records"/>
</section>

{{-- Dialogs intentionally rendered OUTSIDE the table to avoid clipping action menus. --}}
@foreach($records as $record)
  @if(isset($reviews[$record->id]))
    @php($review = $reviews[$record->id])
    @php($withdraw = $record->status === 'published')
    <dialog class="config-dialog wide-dialog" id="preview-{{ $kind }}-{{ $record->id }}">
      <div class="dialog-heading">
        <h2>{{ $record->title }}</h2>
        <p>{{ ucfirst($kind) }} · Public ID {{ $record->public_id }} · {{ ucfirst($record->status) }}</p>
      </div>
      <h3>Dependency assessment</h3>
      <p class="review-text">Owner: {{ $review['owner_exists'] ? $review['owner_role'] : 'missing' }} · Media references: {{ $review['attached_media_references'] }} · Review status: {{ str_replace('_', ' ', $review['review_status']) }}</p>
      <div class="table-scroll"><table class="data-table"><thead><tr><th>Relationship</th><th>Count</th></tr></thead><tbody>
        @foreach($review['related_counts'] as $relationship => $count)
          <tr><td>{{ ucfirst(str_replace('_',' ',$relationship)) }}</td><td>{{ $count }}</td></tr>
        @endforeach
      </tbody></table></div>

      @if($review['customer_activity_blocked'] && $withdraw)
        <p><strong>Blocked:</strong> existing customer activity must be resolved separately. This form cannot override the server-side block.</p>
      @endif
      @if($review['attachments_present'] && $withdraw)
        <p>Media or file references exist. Confirm the owner's rights and keep the original files intact.</p>
      @endif

      <form method="post" action="{{ $withdraw
        ? route('dashboard.preview-visibility.withdraw', ['kind'=>$kind, 'publicId'=>$record->public_id])
        : route('dashboard.preview-visibility.restore', ['kind'=>$kind, 'publicId'=>$record->public_id]) }}">
        @csrf @method('PATCH')
        <input type="hidden" name="expected_fingerprint" value="{{ $review['review_fingerprint'] }}">

        <label for="preview-confirm-{{ $kind }}-{{ $record->id }}">Enter the exact nine-digit public ID</label>
        <input id="preview-confirm-{{ $kind }}-{{ $record->id }}" name="confirm_public_id" maxlength="9" pattern="[0-9]{9}" inputmode="numeric" placeholder="{{ $record->public_id }}" required autocomplete="off">

        <label for="preview-owner-{{ $kind }}-{{ $record->id }}">Verified owner-consent reference</label>
        <input id="preview-owner-{{ $kind }}-{{ $record->id }}" name="owner_confirmation_reference" minlength="12" maxlength="250" required placeholder="Restricted internal approval reference" autocomplete="off">

        <label for="preview-backup-{{ $kind }}-{{ $record->id }}">Verified database backup reference</label>
        <input id="preview-backup-{{ $kind }}-{{ $record->id }}" name="backup_reference" minlength="12" maxlength="350" required placeholder="Restricted backup manifest reference" autocomplete="off">

        @if($review['attachments_present'])
          <label for="preview-rights-{{ $kind }}-{{ $record->id }}">Media/rights review reference</label>
          <input id="preview-rights-{{ $kind }}-{{ $record->id }}" name="rights_review_reference" minlength="12" maxlength="250" required placeholder="Restricted rights-review reference" autocomplete="off">
        @endif

        <label for="preview-reason-{{ $kind }}-{{ $record->id }}">Individual decision reason (minimum 20 characters)</label>
        <textarea id="preview-reason-{{ $kind }}-{{ $record->id }}" name="reason" minlength="20" maxlength="2000" required rows="3" placeholder="Record the reason for this individual decision."></textarea>

        <label><input type="checkbox" name="acknowledge" value="1" required>
          I confirm this exact record, its owner, attached rights, dependencies, database backup and applicable approvals have been independently reviewed.
        </label>

        <div class="dialog-actions">
          <button class="btn subtle" type="button" data-modal-close>Close</button>
          <button type="submit" class="btn primary" @disabled(!$writesEnabled || ($withdraw && $review['customer_activity_blocked']))>
            {{ $withdraw ? 'Withdraw this record' : 'Restore to pending review' }}
          </button>
        </div>
      </form>
      <p class="review-text">A restoration does not republish content. Owner approval and backup references are operator attestations; verify them externally before enabling writes.</p>
    </dialog>
  @endif
@endforeach
@endsection
