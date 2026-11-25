@php
  $sentByMe = (int) $note->created_by === (int) auth()->id();
  $available = $note->isAvailable();
  $state = $note->opened_at ? 'Opened' : ($note->revoked_at ? 'Revoked' : ($available ? 'Available' : 'Expired'));
@endphp
<div class="tracking-once-item" data-once-id="{{ $note->public_id }}">
  <div class="tracking-once-item-top">
    <span class="tracking-once-icon"><x-icon name="lock-keyhole" size="17"/></span>
    <div>
      <strong>{{ $note->label }}</strong>
      <small>{{ $sentByMe ? 'Sent by you' : 'Sent to you' }} · {{ $state }}</small>
    </div>
  </div>
  @if($available)
    <p class="tracking-once-expiry">Expires {{ $note->expires_at->format('d M Y · H:i') }}</p>
    @if($sentByMe)
      <button type="button" class="btn subtle compact" data-once-revoke
        data-url="{{ route('tracking.one-time.revoke', [$order->reference(), $note->public_id]) }}">
        <x-icon name="x" size="14"/> Revoke access
      </button>
    @else
      <button type="button" class="btn primary compact" data-once-open
        data-url="{{ route('tracking.one-time.open', [$order->reference(), $note->public_id]) }}">
        <x-icon name="eye" size="14"/> Open once
      </button>
    @endif
  @endif
  <p class="tracking-once-reveal" data-once-reveal hidden aria-live="polite"></p>
</div>
