@php
  $mine = (int) $message->sender_id === (int) auth()->id();
  $displayName = $message->sender?->name ?? 'Participant';
  $roleLabel = $mine ? 'You' : ((int) $message->sender_id === (int) $order->seller_id ? 'Creator' : 'Customer');
  $avatarUrl = $message->sender?->profileAvatarUrl();
@endphp
<article id="tracking-message-{{ $message->id }}" data-message-id="{{ $message->id }}"
  class="tracking-chat-item {{ $mine ? 'is-own' : 'is-other' }}"
  aria-label="{{ $mine ? 'Your message' : $roleLabel . ' message from ' . $displayName }}">
  <span class="tracking-avatar" aria-hidden="true">
    @if($avatarUrl)
      <img src="{{ $avatarUrl }}" alt="" loading="lazy" referrerpolicy="no-referrer">
    @else
      {{ mb_strtoupper(mb_substr($displayName, 0, 1)) }}
    @endif
  </span>
  <div class="tracking-chat-body">
    <div class="tracking-chat-meta">
      <span class="tracking-chat-sender">
        <strong>{{ $displayName }}</strong>
        <small class="tracking-chat-role">{{ $roleLabel }}</small>
      </span>
    </div>
    <div class="tracking-chat-content policy-formatted-content">
      @if(($message->content_format ?? 'plain') === 'html')
        {!! \App\Support\SupportMessageContent::display($message->body) !!}
      @else
        {!! nl2br(e($message->body)) !!}
      @endif
    </div>
    @if($message->trackingAttachments->isNotEmpty())
      <div class="tracking-chat-attachments" aria-label="Private message attachments">
        @foreach($message->trackingAttachments as $attachment)
          <a class="tracking-chat-file" href="{{ route('tracking.conversation.attachment', [$order->reference(), $attachment->public_id]) }}"
            download title="Download privately verified attachment">
            <x-icon name="paperclip" size="15"/>
            <span>{{ $attachment->original_name }}</span>
            <small>{{ number_format($attachment->bytes / 1024, 1) }} KB</small>
            <x-icon name="download" size="15"/>
          </a>
        @endforeach
      </div>
    @endif
    <div class="tracking-chat-footer">
      <time datetime="{{ $message->created_at->toAtomString() }}"
        title="{{ $message->created_at->format('d M Y · H:i T') }}">
        <x-icon name="clock" size="12"/>
        {{ $message->created_at->format('d M Y · H:i') }}
      </time>
    </div>
  </div>
</article>
