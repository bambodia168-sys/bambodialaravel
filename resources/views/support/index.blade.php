@extends('layouts.workspace')
@section('workspace', auth()->user()->isAdministrator() ? 'admin' : (auth()->user()->isCreator() ? 'creator' : 'account'))
@section('title', 'My Support Tickets')
@push('head')
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=1">
@endpush
@section('content')
<div class="container inner-page support-workspace">
  <div class="support-workspace-heading">
    <div>
      <div class="eyebrow">Your account · Private assistance</div>
      <h1>My support tickets</h1>
      <p>Track your private conversations with the Bambodia support team. Tickets are visible only to your account and authorized support staff.</p>
    </div>
    <div class="support-heading-actions">
      <a href="{{ route('support') }}" class="btn subtle"><x-icon name="book-open" size="17"/> Public Support Center</a>
      <a href="{{ route(auth()->user()->supportTicketRoutePrefix().'.create') }}" class="btn primary"><x-icon name="plus" size="17"/> Create support ticket</a>
    </div>
  </div>
  <section class="panel support-ticket-panel">
    <div class="panel-head">
      <h2>Your requests <span class="count">{{ $tickets->total() }}</span></h2>
      <span class="text-muted">Only your account's tickets</span>
    </div>
    <div class="table-scroll"><table class="data-table"><thead><tr><th>Ticket ID</th><th>Subject</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead><tbody>
      @forelse($tickets as $ticket)
        <tr>
          <td class="mono">{{ $ticket->public_id }}</td>
          <td><strong>{{ $ticket->subject }}</strong></td>
          <td><span class="role-pill">{{ ucwords(str_replace('_', ' ', $ticket->status)) }}</span></td>
          <td>{{ $ticket->updated_at->format('d M Y') }}</td>
          <td><a href="{{ route(auth()->user()->supportTicketRoutePrefix().'.show', $ticket->public_id) }}" class="btn subtle compact">View conversation</a></td>
        </tr>
      @empty
        <tr><td class="empty-cell" colspan="5">
          <div class="support-empty-private"><x-icon name="life-buoy" size="30"/><h3>No tickets yet</h3>
            <p>Need account, order, license, or marketplace assistance? Create a private support request.</p>
            <a class="btn primary" href="{{ route(auth()->user()->supportTicketRoutePrefix().'.create') }}">Create your first ticket</a>
          </div>
        </td></tr>
      @endforelse
    </tbody></table></div>
    <x-pagination :paginator="$tickets"/>
  </section>
</div>
@endsection
