@extends('layouts.dashboard')
@section('title','Privacy Request Manager')
@section('content')
<div class="page-intro"><div class="eyebrow">Super Admin · Data subject requests</div>
<h1>Privacy Request Manager</h1><p>Review logged requests and document operator responses. No automated personal-data deletion or export is performed here.</p></div>
<section class="panel">
<div class="panel-head"><h2>Requests <span class="count">{{ $requests->total() }}</span></h2>
<form class="table-search" method="get">
<select class="table-select" name="status" aria-label="Filter request status">
@foreach(['all','submitted','in_review','responded','closed'] as $option)
<option value="{{ $option }}" @selected($option===$status)>{{ ucfirst(str_replace('_',' ',$option)) }}</option>
@endforeach
</select><button type="submit" class="btn subtle">Filter</button></form></div>
<div class="table-scroll"><table class="data-table">
<thead><tr><th>Reference</th><th>Account</th><th>Public ID</th><th>Category</th><th>State</th><th>Submitted</th><th>Action</th></tr></thead><tbody>
@forelse($requests as $record)
<tr><td class="mono">{{ $record->public_id }}</td><td><strong>{{ $record->owner?->name }}</strong></td><td class="mono">{{ $record->owner?->public_id }}</td>
<td>{{ ucfirst($record->category) }}</td><td><span class="role-pill">{{ ucfirst(str_replace('_',' ',$record->status)) }}</span></td>
<td>{{ $record->created_at->format('d M Y') }}</td>
<td>
@if((int)$record->user_id===(int)auth()->id())
<span class="text-muted">Self-review prohibited</span>
@else<a class="btn subtle compact" href="{{ route('dashboard.privacy.inspect',$record) }}">Open case</a>@endif
</td></tr>
@empty<tr><td colspan="7" class="empty-cell">No matching personal-data requests.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$requests"/>
</section>
@endsection
