@extends('layouts.main')
@section('title',$job->title)
@section('content')
<div class="container inner-page listing-detail">
 <nav class="marketplace-breadcrumb" aria-label="Breadcrumb">
 <a href="{{ route('home') }}">Home</a><x-icon name="chevron-right" size="14"/>
 <a href="{{ route('jobs') }}">Jobs</a><x-icon name="chevron-right" size="14"/>
 <span>{{ $job->public_id }}</span></nav>
 <div class="eyebrow">Freelance job · {{ $job->public_id }}</div><h1>{{ $job->title }}</h1>
 <p class="text-muted">Published by {{ $job->client?->name }} · {{ ucfirst($job->category) }}</p>
 @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
 <div class="marketplace-detail-actions">
 @auth
   @if($isSaved)
     <form method="post" action="{{ route('saved-jobs.destroy',$job) }}">@csrf @method('DELETE')
       <button class="btn subtle" type="submit"><x-icon name="star" size="16"/> Saved — remove from list</button>
     </form>
   @else
     <form method="post" action="{{ route('saved-jobs.store',$job) }}">@csrf
       <button class="btn primary" type="submit"><x-icon name="star" size="16"/> Save job</button>
     </form>
   @endif
   <a class="btn subtle" href="{{ route('account.saved-jobs') }}">My saved jobs</a>
 @else
   <a class="btn subtle" href="{{ route('login') }}">Sign in to save this job</a>
 @endauth
 </div>
 <section class="panel"><div class="panel-head"><h2>Job brief</h2><span class="role-pill">Open</span></div>
 <div class="listing-body">{!! nl2br(e($job->description)) !!}</div>
 <div class="listing-body"><strong>Estimated client budget:</strong> {{ $job->budget===null?'Not specified':'$'.number_format((float)$job->budget,2).' USD' }}</div></section>
 @auth
   @if(auth()->user()->role==='freelancer')
     <section class="panel"><div class="panel-head"><h2>Submit a proposal</h2></div>
     <div class="onboarding-content">
     @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
     @if($applied)<p>You already submitted a proposal for this job. <a href="{{ route('jobs.applied') }}">View its current status</a>.</p>
     @else
       <form action="{{ route('jobs.apply',$job) }}" method="post" class="creator-application-form">
       @csrf
       <label for="proposal-message">Describe your relevant skills, scope, and approach</label>
       <textarea id="proposal-message" name="cover_message" minlength="50" maxlength="5000" rows="7" required></textarea>
       <label for="proposal-budget">Your proposed budget (USD, optional)</label>
       <input type="number" id="proposal-budget" name="proposed_budget" min="0" step="0.01">
       <p class="hint">A published resume is required. Submitting is an application, not a contract or payment commitment.</p>
       <a class="btn subtle" href="{{ route('resume.edit') }}">Resume Builder</a>
       <button class="btn primary" type="submit">Submit proposal</button></form>
     @endif
     </div></section>
   @elseif((int)auth()->user()->id === (int)$job->client_id)
     <p><a class="btn primary" href="{{ route('jobs.proposals',$job) }}">Review applications</a></p>
   @endif
 @else
 <p><a class="btn primary" href="{{ route('login') }}">Sign in to apply</a></p>
 @endauth
 <p class="hint">Job budgets are informational. Bambodia does not currently hold escrow, process payments, or create automatically enforceable contracts.</p>
 <a href="{{ route('jobs') }}" class="btn subtle">Back to job board</a>
</div>
@endsection
