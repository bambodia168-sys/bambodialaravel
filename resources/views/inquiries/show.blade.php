@extends('layouts.main')
@section('title','Private Service Inquiry')
@section('content')
<div class="container inner-page creator-onboarding">
<div class="page-intro"><div class="eyebrow">Private inquiry · {{ $inquiry->public_id }}</div><h1>{{ $inquiry->subject }}</h1>
<p>{{ $inquiry->listing?->title }} · Client {{ $inquiry->client?->name }} · Creator {{ $inquiry->creator?->name }}</p>
@if($inquiry->proposed_budget!==null)<p><strong>Proposed budget:</strong> USD {{ number_format((float)$inquiry->proposed_budget,2) }} (informational)</p>@endif
</div>
@if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
@if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
<section class="panel"><div class="panel-head"><h2>Conversation <span class="count">{{ $messages->total() }}</span></h2><span class="role-pill">{{ ucfirst($inquiry->status) }}</span></div>
<div class="conversation-thread">
@forelse($messages as $message)<article class="conversation-message {{ (int)$message->sender_id===(int)auth()->id()?'own':'' }}">
<div class="conversation-byline"><strong>{{ $message->sender?->name }}</strong><span>{{ $message->created_at->format('d M Y H:i') }}</span></div>
<p class="review-text">{{ $message->body }}</p></article>
@empty<p>No messages have been sent.</p>@endforelse
</div><x-pagination :paginator="$messages"/>
@if($inquiry->status==='open')
<div class="onboarding-content"><form method="post" action="{{ route('inquiries.reply',$inquiry) }}" class="creator-application-form">
@csrf<label for="inquiry-reply">Reply to the conversation</label><textarea id="inquiry-reply" name="body" rows="5" minlength="2" maxlength="5000" required></textarea>
<button class="btn primary" type="submit">Send reply</button></form>
<form action="{{ route('inquiries.close',$inquiry) }}" method="post" class="inquiry-close-form">@csrf @method('PATCH')
<button class="btn subtle" type="submit">Close conversation</button></form>
</div>
@else<p class="hint">This conversation is closed to further replies.</p>@endif
</section>
<p class="hint">Messages support pre-contract conversations only. Neither sending a message nor agreeing to a budget transfers funds, funds escrow, or creates an automated platform contract.</p>
<a class="btn subtle" href="{{ (int)auth()->id()===(int)$inquiry->creator_id ? route('studio.inquiries') : route('account.inquiries') }}">Back to inquiries</a>
</div>
@endsection
