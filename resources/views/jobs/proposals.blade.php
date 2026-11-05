@extends('layouts.main')
@section('title','Job Proposals')
@section('content')
<div class="container inner-page"><div class="page-intro"><div class="eyebrow">Client Account</div><h1>Applications for {{ $job->title }}</h1><p>Review each proposal privately. Shortlisting does not create a contract or financial obligation.</p></div>
@if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
<section class="panel"><div class="panel-head"><h2>Received proposals <span class="count">{{ $proposals->total() }}</span></h2><a class="btn subtle" href="{{ route('jobs.mine') }}">Back to jobs</a></div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Freelancer</th><th>Public ID</th><th>Budget</th><th>Status</th><th>Submitted</th><th>Action</th></tr></thead><tbody>
@forelse($proposals as $proposal)
<tr><td><strong>{{ $proposal->freelancer?->name }}</strong></td><td class="mono">{{ $proposal->freelancer?->public_id }}</td><td>{{ $proposal->proposed_budget===null?'—':'$'.number_format((float)$proposal->proposed_budget,2) }}</td><td><span class="role-pill">{{ ucfirst($proposal->status) }}</span></td><td>{{ $proposal->created_at->format('d M Y') }}</td><td>
<button type="button" class="btn subtle compact" data-modal-open="proposal-{{ $proposal->id }}"><x-icon name="settings" size="15"/> Configure</button>
<dialog class="config-dialog wide-dialog" id="proposal-{{ $proposal->id }}"><h2>Proposal from {{ $proposal->freelancer?->name }}</h2><p class="review-text">{{ $proposal->cover_message }}</p>
@if($proposal->freelancer?->resume?->is_visible)
 <h3>{{ $proposal->freelancer->resume->headline }}</h3><p class="review-text">{{ $proposal->freelancer->resume->summary }}</p>
 <strong>Skills</strong><p class="review-text">{{ $proposal->freelancer->resume->skills }}</p>
 <strong>Experience</strong><p class="review-text">{{ $proposal->freelancer->resume->experience }}</p>
 @if($proposal->freelancer->resume->portfolio_url)<a href="{{ $proposal->freelancer->resume->portfolio_url }}" rel="noopener noreferrer" target="_blank" class="btn subtle">Professional portfolio</a>@endif
@endif
@if($proposal->status === 'submitted')
<form method="post" action="{{ route('jobs.proposals.decide',$proposal) }}">@csrf @method('PATCH')
<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn subtle" type="submit" name="decision" value="declined">Decline</button><button class="btn primary" type="submit" name="decision" value="shortlisted">Shortlist</button></div>
</form>
@else<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Close</button></div>@endif
</dialog></td></tr>
@empty<tr><td colspan="6" class="empty-cell">No applications have been submitted for this job.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$proposals"/></section></div>
@endsection
