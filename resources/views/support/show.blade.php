@extends($isStaff ? 'layouts.dashboard' : 'layouts.workspace')
@section('workspace', $isStaff || match(auth()->user()->supportTicketRoutePrefixForRequest()) { 'dashboard.support-tickets' => 'admin', 'studio.support-tickets' => 'creator', default => 'account' })
@section('title', 'Support Ticket '.$ticket->public_id)
@push('head')
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=2">
<script defer src="{{ asset('js/support-ticket-editor.js') }}?v=1"></script>
@endpush
@section('content')
<div class="container inner-page support-workspace">
  <div class="support-workspace-heading">
    <div>
      <a class="support-back-link" href="{{ $isStaff ? route('dashboard.support') : route(auth()->user()->supportTicketRoutePrefixForRequest().'.index') }}">
        <x-icon name="chevron-left" size="16"/> {{ $isStaff ? 'Support administration' : 'My support tickets' }}
      </a>
      <div class="eyebrow">Private support · Ticket {{ $ticket->public_id }}</div>
      <h1>{{ $ticket->subject }}</h1>
      <p>
        <span class="support-status-pill support-status--{{ $ticket->status }}">{{ \App\Models\SupportTicket::STATUSES[$ticket->status] ?? ucfirst($ticket->status) }}</span>
        @if($isStaff) · Opened by {{ $ticket->owner?->name }} ({{ $ticket->owner?->email }}) @endif
        · Last updated {{ $ticket->updated_at?->format('d M Y H:i') }}
        · Assigned to {{ $ticket->assignee?->name ?? 'Unassigned' }}
      </p>
      @if($ticket->context_type === 'order' && ($order = $ticket->order))
        <p>Related order <span class="mono">{{ $order->reference() }}</span></p>
      @endif
    </div>
    @unless($isStaff)
      <a class="btn subtle" href="{{ route('support') }}"><x-icon name="book-open" size="17"/> Public Support Center</a>
    @endunless
  </div>
  <section class="panel support-thread-panel" aria-labelledby="support-thread-heading">
    <div class="panel-head">
      <div><h2 id="support-thread-heading">Conversation</h2><p class="text-muted">Private discussion between the account owner and authorized support staff.</p></div>
      <span class="role-pill">{{ ucwords(str_replace('_', ' ', $ticket->status)) }}</span>
    </div>
    <div class="support-thread-messages">
      @foreach($ticket->messages as $message)
        @php $mine = (int) $message->user_id === (int) auth()->id(); @endphp
        <article class="support-thread-message {{ $mine ? 'is-mine' : 'is-other' }}">
          <div class="support-thread-message-head">
            <div class="support-thread-author">
              <span class="support-thread-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($message->author?->name ?? 'U', 0, 1)) }}</span>
              <div><strong>{{ $message->author?->name ?? 'Former account' }}</strong>
                <small>{{ $mine ? 'You' : (($message->author?->role === 'staff' || $message->author?->isAdministrator()) ? 'Support team' : 'Member') }}</small></div>
            </div>
            <time datetime="{{ $message->created_at?->toIso8601String() }}">{{ $message->created_at?->format('d M Y · H:i') }}</time>
          </div>
          <div class="support-thread-content policy-formatted-content">{!! \App\Support\SupportMessageContent::display($message->body) !!}</div>
        </article>
      @endforeach
    </div>
  </section>
  @if($ticket->status === 'closed')
    <section class="panel support-reply-panel"><h2>Ticket closed</h2>
      <p class="text-muted">This conversation is closed. Contact support if you need to reopen the request.</p>
      @if($isStaff)
        <form method="post" action="{{ route('dashboard.support.status', $ticket->public_id) }}">
          @csrf @method('PATCH')
          <input type="hidden" name="status" value="open">
          <button class="btn subtle" type="submit">Reopen ticket</button>
        </form>
      @endif
    </section>
  @else
  <section class="panel support-ticket-panel support-reply-panel">
    <h2>Write a reply</h2>
    <p class="text-muted">Replies are added to this private ticket. You can format text, insert media and share code.</p>
    <form class="support-ticket-form" method="post" data-ticket-form
      action="{{ $isStaff ? route('dashboard.support.reply', $ticket->public_id) : route(auth()->user()->supportTicketRoutePrefixForRequest().'.reply', $ticket->public_id) }}">
      @csrf
      <label for="ticket-reply-message">Your response</label>
      @include('support.editor', ['editorId' => 'ticket-reply-message', 'minimum' => 2])
      @error('message')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror
      @if($isStaff)
        <label for="ticket-status">Status after reply</label>
        <select name="status" id="ticket-status">
          @foreach(\App\Models\SupportTicket::STATUSES as $value => $label)
            <option value="{{ $value }}" @selected(old('status', $ticket->status) === $value)>{{ $label }}</option>
          @endforeach
        </select>
      @endif
      <div class="support-form-actions">
        <a href="{{ $isStaff ? route('dashboard.support') : route(auth()->user()->supportTicketRoutePrefixForRequest().'.index') }}" class="btn subtle">Back to tickets</a>
        <button type="submit" class="btn primary"><x-icon name="send" size="17"/> Send response</button>
      </div>
    </form>
  </section>
  @endif
</div>
@endsection
