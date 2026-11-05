@extends('layouts.main')
@section('title','My Job Proposals')
@section('content')
<div class="container inner-page">
<div class="page-intro"><div class="eyebrow">Freelancer Workspace</div><h1>My Proposals</h1><p>Track submitted applications and job-owner decisions without exposing private information to other users.</p></div>
<section class="panel">
<div class="panel-head"><h2>Submitted proposals <span class="count">{{ $proposals->total() }}</span></h2><a class="btn subtle" href="{{ route('jobs') }}">Find more jobs</a></div>
<div class="table-scroll"><table class="data-table">
<thead><tr><th>Job</th><th>Job status</th><th>Proposed budget</th><th>Decision</th><th>Submitted</th><th>Action</th></tr></thead><tbody>
@forelse($proposals as $proposal)<tr>
<td><strong>{{ $proposal->job?->title ?? 'Unavailable job' }}</strong></td>
<td><span class="role-pill">{{ ucfirst($proposal->job?->status ?? 'unavailable') }}</span></td>
<td>{{ $proposal->proposed_budget===null?'—':'$'.number_format((float)$proposal->proposed_budget,2) }}</td>
<td><span class="role-pill">{{ ucfirst($proposal->status) }}</span></td>
<td>{{ $proposal->created_at->format('d M Y') }}</td>
<td>
<button type="button" class="btn subtle compact" data-modal-open="submitted-{{ $proposal->id }}"><x-icon name="settings" size="15"/> Configure</button>
<dialog class="config-dialog wide-dialog" id="submitted-{{ $proposal->id }}"><h2>My proposal</h2>
<p class="review-text">{{ $proposal->cover_message }}</p><p><strong>Decision:</strong> {{ ucfirst($proposal->status) }}</p>
<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Close</button>
@if($proposal->job?->status === 'published')<a class="btn primary" href="{{ $proposal->job->publicUrl() }}">Open job</a>@endif</div>
</dialog></td></tr>
@empty<tr><td colspan="6" class="empty-cell">You have not applied for any jobs.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$proposals"/>
</section></div>
@endsection
