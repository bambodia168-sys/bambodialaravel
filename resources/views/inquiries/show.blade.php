@extends('layouts.main')
@section('title', $inquiry->marketplace_order_id ? 'Service Workspace' : 'Private Service Inquiry')
@section('content')
<div class="container inner-page creator-onboarding">
<div class="page-intro"><div class="eyebrow">{{ $inquiry->marketplace_order_id ? 'Service workspace' : 'Private inquiry' }} · {{ $inquiry->public_id }}</div><h1>{{ $inquiry->subject }}</h1>
<p>{{ $inquiry->listing?->title }} · Client {{ $inquiry->client?->name }} · Creator {{ $inquiry->creator?->name }}</p>
@if($inquiry->proposed_budget!==null)<p><strong>Proposed budget:</strong> USD {{ number_format((float)$inquiry->proposed_budget,2) }} (informational)</p>@endif
</div>
@if($deliveries !== null)
  @include('inquiries.delivery')
@endif
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
@if(!$inquiry->marketplace_order_id)
<form action="{{ route('inquiries.close',$inquiry) }}" method="post" class="inquiry-close-form">@csrf @method('PATCH')
<button class="btn subtle" type="submit">Close conversation</button></form>
@endif
</div>
@else<p class="hint">This conversation is closed to further replies.</p>@endif
</section>
@if($inquiry->marketplace_order_id)
  <section class="panel">
    <div class="panel-head"><h2>Order support</h2></div>
    <div class="onboarding-content">
      <details @if($errors->hasAny(['subject', 'message'])) open @endif>
        <summary>Open a private support ticket</summary>
        <form method="post" action="{{ route('support.store') }}" class="creator-application-form">
          @csrf
          <input type="hidden" name="order_number" value="{{ $inquiry->order->number }}">
          <label for="order-support-subject">Subject</label>
          <input id="order-support-subject" name="subject" maxlength="180" minlength="5" required value="{{ old('subject') }}">
          @error('subject')<p class="hint" role="alert">{{ $message }}</p>@enderror
          <label for="order-support-message">Describe the issue</label>
          <textarea id="order-support-message" name="message" rows="4" maxlength="10000" minlength="10" required>{{ old('message') }}</textarea>
          @error('message')<p class="hint" role="alert">{{ $message }}</p>@enderror
          <button class="btn subtle" type="submit">Create ticket</button>
        </form>
      </details>
    </div>
  </section>
  <a class="btn subtle" href="{{ route((int) auth()->id() === (int) $inquiry->creator_id ? 'studio.orders.show' : 'account.orders.show', $inquiry->order->number) }}">Back to order</a>
@else
  <p class="hint">Messages support pre-contract conversations only. Neither sending a message nor agreeing to a budget transfers funds, funds escrow, or creates an automated platform contract.</p>
  <a class="btn subtle" href="{{ (int)auth()->id()===(int)$inquiry->creator_id ? route('studio.inquiries') : route('account.inquiries') }}">Back to inquiries</a>
@endif
</div>
@endsection
