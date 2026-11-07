@extends('layouts.dashboard')
@section('title','Job Manager')
@section('content')
<div class="page-intro"><div class="eyebrow">Marketplace Governance</div><h1>Job Manager</h1><p>Moderate posted jobs before public visibility. Only real client-submitted briefs appear in this table.</p></div>
<section class="panel"><div class="panel-head"><h2>Job review <span class="count">{{ $jobs->total() }}</span></h2>
<form method="get" class="table-search"><select class="table-select" name="status" aria-label="Filter jobs">
@foreach(['pending'=>'Pending','published'=>'Published','rejected'=>'Rejected','closed'=>'Closed','all'=>'All'] as $value=>$name)<option value="{{ $value }}" @selected($status===$value)>{{ $name }}</option>@endforeach
</select><button class="btn subtle" type="submit">Filter</button></form></div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Job ID</th><th>Title</th><th>Client</th><th>Category</th><th>Budget</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($jobs as $job)<tr><td class="mono">{{ $job->public_id }}</td><td><strong>{{ $job->title }}</strong></td><td>{{ $job->client?->name }}</td><td>{{ ucfirst($job->category) }}</td><td>{{ $job->budget===null?'—':'$'.number_format((float)$job->budget,2) }}</td><td><span class="role-pill">{{ ucfirst($job->status) }}</span></td>
<td><x-row-action-menu :id="'job-'.$job->id" :label="$job->title">
<button type="button" data-modal-open="admin-job-{{ $job->id }}"><x-icon name="settings" size="16"/> Configure</button>
</x-row-action-menu>
<dialog class="config-dialog wide-dialog" id="admin-job-{{ $job->id }}"><h2>{{ $job->title }}</h2><p>{{ $job->client?->name }} · {{ $job->public_id }}</p><p class="review-text">{{ $job->description }}</p>
@if($job->status === 'pending')
<form method="post" action="{{ route('dashboard.jobs.decide',$job) }}">@csrf @method('PATCH')
<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn subtle" type="submit" name="decision" value="rejected">Reject</button><button class="btn primary" type="submit" name="decision" value="published">Publish job</button></div></form>
@else<div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Close</button></div>@endif
</dialog></td></tr>
@empty<tr><td colspan="7" class="empty-cell">No job posts match this status.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$jobs"/></section>
@endsection
