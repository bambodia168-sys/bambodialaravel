@extends('layouts.main')
@section('title','My Job Posts')
@section('content')
<div class="container inner-page">
<div class="page-intro"><div class="eyebrow">Client Account</div><h1>Job Manager</h1><p>Post genuine project briefs and review incoming freelancer proposals. All job posts require administrator approval.</p></div>
@if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
<section class="panel"><div class="panel-head"><h2>My posted jobs <span class="count">{{ $jobs->total() }}</span></h2><button type="button" class="btn primary" data-modal-open="new-job">Post a job</button></div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Budget</th><th>Status</th><th>Proposals</th><th>Configure</th></tr></thead><tbody>
@forelse($jobs as $job)<tr><td class="mono">{{ $job->public_id }}</td><td><strong>{{ $job->title }}</strong></td><td>{{ ucfirst($job->category) }}</td><td>{{ $job->budget===null?'—':'$'.number_format((float)$job->budget,2) }}</td><td><span class="role-pill">{{ ucfirst($job->status) }}</span></td><td>{{ $job->proposals_count }}</td>
<td><button type="button" class="btn subtle compact" data-modal-open="job-{{ $job->id }}"><x-icon name="settings" size="15"/> Configure</button>
<dialog class="config-dialog wide-dialog" id="job-{{ $job->id }}">
<h2>{{ $job->title }}</h2><p>ID {{ $job->public_id }} · {{ ucfirst($job->status) }}</p>
<p class="review-text">{{ $job->description }}</p>
<a class="btn subtle" href="{{ route('jobs.proposals',$job) }}">Review {{ $job->proposals_count }} proposals</a>
@if(in_array($job->status,['published','pending'],true))
<form method="post" action="{{ route('jobs.close',$job) }}">@csrf @method('PATCH')
<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn danger" type="submit">Close job to new proposals</button></div></form>
@else<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Close</button></div>@endif
</dialog></td></tr>
@empty<tr><td colspan="7" class="empty-cell">You have not posted a job yet.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$jobs"/></section>
<dialog class="config-dialog wide-dialog" id="new-job"><form method="post" action="{{ route('jobs.store') }}">@csrf
<h2>Post a freelance job</h2><p>Your project is submitted for administrator review before public discovery.</p>
<label for="job-title">Job title</label><input id="job-title" name="title" minlength="8" maxlength="180" required>
<label for="job-brief">Project brief and requirements</label><textarea id="job-brief" name="description" minlength="70" maxlength="10000" rows="8" required></textarea>
<label for="job-category">Category</label><select id="job-category" name="category">@foreach(['development','design','writing','marketing','business','other'] as $category)<option value="{{ $category }}">{{ ucfirst($category) }}</option>@endforeach</select>
<label for="job-budget">Budget (USD, optional)</label><input id="job-budget" name="budget" type="number" min="0" step="0.01">
<p class="hint">Posting a job does not fund escrow or authorize financial processing.</p>
<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Submit for review</button></div>
</form></dialog>
</div>
@endsection
