@extends('layouts.dashboard')
@section('title','Creator Applications')
@section('content')
<div class="page-intro"><div class="eyebrow">Marketplace Governance</div><h1>Creator Applications</h1><p>Review applicant information before granting freelancer publishing permissions.</p></div>
<section class="panel">
  <div class="panel-head">
    <h2>Applications <span class="count">{{ $applications->total() }}</span></h2>
    <form class="table-search" method="get">
      <x-icon name="filter" size="17"/>
      <select name="status" aria-label="Filter creator applications" class="table-select">
        @foreach(['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All applications'] as $key=>$label)<option value="{{ $key }}" @selected($status===$key)>{{ $label }}</option>@endforeach
      </select>
      <button class="btn subtle" type="submit">Filter</button>
    </form>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Applicant ID</th><th>Applicant</th><th>Specialization</th><th>Status</th><th>Submitted</th><th>Action</th></tr></thead>
    <tbody>
      @forelse($applications as $application)
        <tr>
          <td class="mono">{{ $application->applicant?->public_id ?? '—' }}</td>
          <td><strong>{{ $application->applicant?->name ?? 'Account unavailable' }}</strong><div class="text-muted">{{ $application->applicant?->email }}</div></td>
          <td>{{ $application->specialization }}</td>
          <td><span class="role-pill">{{ ucfirst($application->status) }}</span></td>
          <td>{{ $application->updated_at->format('d M Y') }}</td>
          <td>
            <button class="btn subtle compact" type="button" data-modal-open="creator-review-{{ $application->id }}"><x-icon name="settings" size="15"/> Configure</button>
            <dialog class="config-dialog wide-dialog" id="creator-review-{{ $application->id }}">
              <div class="dialog-heading"><h2>{{ $application->applicant?->name }}</h2><p>Applicant ID {{ $application->applicant?->public_id }}</p></div>
              <p><strong>Specialization:</strong> {{ $application->specialization }}</p>
              <p><strong>Experience:</strong></p>
              <p class="review-text">{{ $application->experience }}</p>
              @if($application->portfolio_url)
                <p><a class="btn subtle compact" href="{{ $application->portfolio_url }}" target="_blank" rel="noopener noreferrer">Open portfolio</a></p>
              @endif
              @if($application->status === 'pending')
                <form method="post" action="{{ route('dashboard.creator-applications.decide',$application) }}">
                  @csrf @method('PATCH')
                  <label for="review-note-{{ $application->id }}">Review note (required if rejecting)</label>
                  <textarea name="review_note" id="review-note-{{ $application->id }}" rows="4" maxlength="3000"></textarea>
                  <div class="dialog-actions">
                    <button class="btn subtle" type="button" data-modal-close>Cancel</button>
                    <button class="btn subtle" type="submit" name="decision" value="rejected">Reject</button>
                    <button class="btn primary" type="submit" name="decision" value="approved">Approve creator</button>
                  </div>
                </form>
              @else
                <p>Reviewed {{ $application->reviewed_at?->format('d M Y H:i') ?? '—' }}</p>
                @if($application->review_note)<p class="review-text">{{ $application->review_note }}</p>@endif
                <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Close</button></div>
              @endif
            </dialog>
          </td>
        </tr>
      @empty<tr><td class="empty-cell" colspan="6">No creator applications match this filter.</td></tr>@endforelse
    </tbody>
  </table></div>
  <x-pagination :paginator="$applications"/>
</section>
@endsection
