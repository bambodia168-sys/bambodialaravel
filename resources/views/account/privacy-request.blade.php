@extends('layouts.main')
@section('title','Privacy Request')
@section('content')
<div class="container inner-page creator-onboarding">
<div class="page-intro"><div class="eyebrow">Private request · {{ $privacyRequest->public_id }}</div>
<h1>{{ ucfirst($privacyRequest->category) }} request</h1>
<p><span class="role-pill">{{ ucfirst(str_replace('_',' ',$privacyRequest->status)) }}</span> · Submitted {{ $privacyRequest->created_at->format('d M Y H:i') }}</p>
</div>
@if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
<section class="panel">
<div class="panel-head"><h2>Submitted information</h2></div>
<div class="onboarding-content"><p class="review-text">{{ $privacyRequest->details }}</p></div>
</section>
<section class="panel">
<div class="panel-head"><h2>Administrator handling</h2></div>
<div class="onboarding-content">
@if($privacyRequest->review_started_at)
<p><strong>Review started:</strong> {{ $privacyRequest->review_started_at->format('d M Y H:i') }}</p>
@endif
@if($privacyRequest->responded_at)
<p><strong>Response recorded:</strong> {{ $privacyRequest->responded_at->format('d M Y H:i') }}</p>
<p class="review-text">{{ $privacyRequest->response_note }}</p>
@else
<p class="hint">An administrator response has not been recorded yet.</p>
@endif
@if($privacyRequest->status==='responded')
<form method="post" action="{{ route('privacy.close',$privacyRequest) }}">
@csrf @method('PATCH')
<label class="check-label"><input name="confirm_close" type="checkbox" value="1" required> I reviewed the response and want to close this request in my account.</label>
<button class="btn primary" type="submit">Close my request</button></form>
@endif
<p class="hint">Administrative response status does not itself prove the requested access, correction, portability or erasure has been lawfully completed. Additional external action may be required.</p>
</div>
</section>
<a class="btn subtle" href="{{ route('privacy.index') }}">Back to privacy requests</a>
</div>
@endsection
