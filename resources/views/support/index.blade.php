@extends('layouts.main')
@section('title','Support Center')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Customer Care</div><h1>Support Center</h1><p>Open a support request and follow its progress in your account.</p></div>
  @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
  <section class="panel">
    <div class="panel-head"><h2>My tickets <span class="count">{{ $tickets->total() }}</span></h2><button class="btn primary" type="button" data-modal-open="ticket-new">Create ticket</button></div>
    <div class="table-scroll"><table class="data-table"><thead><tr><th>Ticket ID</th><th>Subject</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead><tbody>
    @forelse($tickets as $ticket)
      <tr><td class="mono">{{ $ticket->public_id }}</td><td><strong>{{ $ticket->subject }}</strong></td><td><span class="role-pill">{{ str_replace('_',' ',ucfirst($ticket->status)) }}</span></td><td>{{ $ticket->updated_at->format('d M Y') }}</td><td><a class="btn subtle compact" href="{{ route('support.show',$ticket->public_id) }}">View conversation</a></td></tr>
    @empty<tr><td class="empty-cell" colspan="5">You have not opened any support tickets.</td></tr>@endforelse
    </tbody></table></div>
    <x-pagination :paginator="$tickets"/>
  </section>
  <dialog id="ticket-new" class="config-dialog wide-dialog">
    <form method="post" action="{{ route('support.store') }}">@csrf
      <h2>Create support ticket</h2>
      <label for="support-subject">Subject</label><input id="support-subject" name="subject" required maxlength="180" minlength="5">
      <label for="support-message">Describe your question</label><textarea id="support-message" name="message" minlength="10" maxlength="10000" rows="8" required></textarea>
      <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn primary" type="submit">Submit ticket</button></div>
    </form>
  </dialog>
</div>
@endsection
