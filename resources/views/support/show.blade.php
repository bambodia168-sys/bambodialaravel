@extends($isStaff ? 'layouts.dashboard' : 'layouts.main')
@section('title','Ticket '.$ticket->public_id)
@section('content')
<div class="{{ $isStaff ? '' : 'container' }} inner-page">
  <div class="page-intro"><div class="eyebrow">Support Ticket {{ $ticket->public_id }}</div><h1>{{ $ticket->subject }}</h1>
    <p>Status: {{ ucwords(str_replace('_',' ',$ticket->status)) }} @if($isStaff) · Opened by {{ $ticket->owner?->name }} ({{ $ticket->owner?->email }}) @endif</p>
  </div>
  <section class="panel ticket-conversation">
    <div class="panel-head"><h2>Conversation</h2><span class="role-pill">{{ ucwords(str_replace('_',' ',$ticket->status)) }}</span></div>
    <div class="messages">
      @foreach($ticket->messages as $message)
      <article class="message"><div class="message-meta"><strong>{{ $message->author?->name ?? 'Former account' }}</strong><span>{{ $message->created_at?->format('d M Y H:i') }}</span></div><p>{{ $message->body }}</p></article>
      @endforeach
    </div>
    <form class="ticket-reply" method="post" action="{{ $isStaff ? route('dashboard.support.reply',$ticket->public_id) : route('support.reply',$ticket->public_id) }}">
      @csrf
      <label for="ticket-reply">Your response</label><textarea id="ticket-reply" name="message" rows="5" minlength="2" maxlength="10000" required></textarea>
      @if($isStaff)<label for="ticket-status">Status after reply</label><select name="status" id="ticket-status">@foreach(['open'=>'Open','in_progress'=>'In progress','resolved'=>'Resolved'] as $value=>$label)<option value="{{ $value }}" @selected($ticket->status===$value)>{{ $label }}</option>@endforeach</select>@endif
      <button type="submit" class="btn primary">Send response</button>
    </form>
  </section>
  <a class="btn subtle" href="{{ $isStaff ? route('dashboard.support') : route('support.tickets') }}">Back to tickets</a>
</div>
@endsection
