@extends('layouts.main')
@section('title','Support · '.$title)
@section('content')
<div class="container inner-page detail-workspace">
  <div class="detail-workspace-head">
    <div>
      <span class="eyebrow">Bambodia Support</span>
      <h1>Private Support</h1>
      <p>Get assistance related to <strong>{{ $title }}</strong>. Only you and authorized support staff can read your tickets.</p>
    </div>
    <div class="detail-workspace-navigation">
      <a class="btn subtle" href="{{ $publicUrl }}"><x-icon name="arrow-left" size="16"/> Back to {{ ucfirst($kind) }}</a>
      <a class="btn subtle" href="{{ route('detail.comments',$params) }}"><x-icon name="message-square" size="16"/> Comments</a>
    </div>
  </div>
  <div class="detail-workspace-grid">
    <section class="panel detail-workspace-panel" aria-labelledby="detail-tickets-title">
      <div class="panel-head"><h2 id="detail-tickets-title">My requests</h2><span class="count">{{ $tickets->total() }}</span></div>
      @forelse($tickets as $ticket)
        <article class="detail-support-ticket">
          <div>
            <a href="{{ route('support.show',$ticket->public_id) }}"><strong>{{ $ticket->subject }}</strong></a>
            <span class="hint">#{{ $ticket->public_id }} · Updated {{ $ticket->updated_at->diffForHumans() }}</span>
          </div>
          <div>
            <span class="role-pill">{{ ucwords(str_replace('_',' ',$ticket->status)) }}</span>
            <a class="btn subtle compact" href="{{ route('support.show',$ticket->public_id) }}">View replies</a>
          </div>
        </article>
      @empty
        <div class="detail-workspace-empty">No requests for this {{ $kind }} yet. Create a new private ticket for the support team.</div>
      @endforelse
      <x-pagination :paginator="$tickets"/>
    </section>

    <aside class="panel detail-compose" aria-labelledby="detail-support-form-title">
      <h2 id="detail-support-form-title">New support request</h2>
      <p>Include enough detail for the team to help. Creating a ticket does not open a purchase, escrow or payment dispute.</p>
      <form method="post" action="{{ route('detail.support.store',$params) }}">
        @csrf
        <label for="detail-support-subject">Subject</label>
        <input id="detail-support-subject" name="subject" minlength="5" maxlength="180" required value="{{ old('subject') }}">
        @error('subject')<p class="field-error">{{ $message }}</p>@enderror
        <label for="detail-support-body">How can we help?</label>
        <textarea id="detail-support-body" name="message" rows="7" minlength="10" maxlength="10000" required>{{ old('message') }}</textarea>
        @error('message')<p class="field-error">{{ $message }}</p>@enderror
        <button type="submit" class="btn primary"><x-icon name="life-buoy" size="16"/> Create ticket</button>
      </form>
      <p class="hint">You can follow up and read support staff replies in your private ticket conversation.</p>
    </aside>
  </div>
</div>
@endsection
