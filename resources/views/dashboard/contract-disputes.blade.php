@extends('layouts.dashboard')
@section('title','Contract Disputes')
@section('content')
<div class="page-intro"><div class="eyebrow">Administrative case review</div><h1>Contract Disputes</h1>
  <p>Only disputes explicitly reported by a client or freelancer enter this queue. Review actions are audited and cannot be performed by an administrator who is a party to the agreement.</p>
</div>
<section class="panel">
<div class="panel-head"><h2>Reported cases <span class="count">{{ $disputes->total() }}</span></h2>
<form class="table-search" method="get">
  <select class="table-select" name="status" aria-label="Filter dispute status">
    @foreach(['all','open','under_review','resolved'] as $option)
      <option value="{{ $option }}" @selected($status===$option)>{{ ucfirst(str_replace('_',' ',$option)) }}</option>
    @endforeach
  </select><button class="btn subtle" type="submit">Filter</button>
</form></div>
<div class="table-scroll"><table class="data-table">
<thead><tr><th>Contract ID</th><th>Client</th><th>Freelancer</th><th>Reported by</th><th>Category</th><th>Status</th><th>Action</th></tr></thead>
<tbody>
@forelse($disputes as $dispute)
<tr>
<td class="mono">{{ $dispute->contract?->public_id }}</td><td>{{ $dispute->contract?->client?->name }}</td>
<td>{{ $dispute->contract?->freelancer?->name }}</td><td>{{ $dispute->reporter?->name }}</td>
<td>{{ ucfirst($dispute->category) }}</td><td><span class="role-pill">{{ ucfirst(str_replace('_',' ',$dispute->status)) }}</span></td>
<td>
@if($dispute->contract && ! $dispute->contract->isParticipant((int)auth()->id()))
<a class="btn subtle compact" href="{{ route('dashboard.contracts.inspect',$dispute) }}">Open case</a>
@else<span class="text-muted">Conflict of interest</span>@endif
</td>
</tr>
@empty<tr><td colspan="7" class="empty-cell">No disputes match this filter.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$disputes"/></section>
@endsection
