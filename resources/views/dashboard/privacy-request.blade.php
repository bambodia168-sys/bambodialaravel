@extends('layouts.dashboard')
@section('title','Privacy Request Review')
@section('content')
<div class="page-intro"><div class="eyebrow">Restricted case · {{ $privacyRequest->public_id }}</div>
<h1>Privacy Request Review</h1>
<p>{{ $privacyRequest->owner?->name }} · Public ID {{ $privacyRequest->owner?->public_id }}</p>
<span class="role-pill">{{ ucfirst(str_replace('_',' ',$privacyRequest->status)) }}</span>
</div>
<section class="panel"><div class="panel-head"><h2>{{ ucfirst($privacyRequest->category) }} request</h2></div>
<div class="onboarding-content">
<p><strong>Submitted:</strong> {{ $privacyRequest->created_at->format('d M Y H:i') }}</p>
<p class="review-text">{{ $privacyRequest->details }}</p>
@if($privacyRequest->response_note)
<h3>Recorded operator response</h3><p class="review-text">{{ $privacyRequest->response_note }}</p>
@endif
<p class="hint">Review applicability, identification requirements, retention obligations and available evidence before issuing a response. This register alone does not fulfill any country's privacy requirements.</p>
</div></section>
@if($privacyRequest->status==='submitted')
<section class="panel"><div class="onboarding-content">
<form method="post" action="{{ route('dashboard.privacy.update',$privacyRequest) }}">
@csrf @method('PATCH')<input type="hidden" name="decision" value="in_review">
<button type="submit" class="btn primary">Mark as under review</button></form>
</div></section>
@elseif($privacyRequest->status==='in_review')
<section class="panel"><div class="panel-head"><h2>Record response</h2></div>
<div class="onboarding-content">
<form method="post" action="{{ route('dashboard.privacy.update',$privacyRequest) }}" class="creator-application-form">
@csrf @method('PATCH')<input type="hidden" name="decision" value="responded">
<label for="privacy-response">Specific actions, findings and follow-up instructions</label>
<textarea id="privacy-response" name="response_note" minlength="30" maxlength="6000" rows="8" required></textarea>
<p class="hint">Use clear language. Do not claim a deletion, export or correction was completed unless that action was independently verified.</p>
<button type="submit" class="btn primary">Record response and notify user</button>
</form></div></section>
@endif
<a class="btn subtle" href="{{ route('dashboard.privacy.index') }}">Back to requests</a>
@endsection
