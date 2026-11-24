@extends('layouts.workspace')
@section('workspace', match(auth()->user()->supportTicketRoutePrefixForRequest()) { 'dashboard.support-tickets' => 'admin', 'studio.support-tickets' => 'creator', default => 'account' })
@section('title', 'My Support Tickets')
@push('head')
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=3">
@endpush
@section('content')
@php
  $workspace = auth()->user()->supportTicketRoutePrefixForRequest();
  $management = false;
  $action = route($workspace.'.index');
@endphp
<div class="container inner-page support-workspace">
  <div class="support-workspace-heading support-list-heading">
    <div class="support-heading-copy">
      <div class="eyebrow">Your account · Private assistance</div>
      <h1>My support tickets</h1>
      <p>Track private conversations with the Bambodia support team. Only you and authorized support personnel can view your requests.</p>
    </div>
    <div class="support-heading-actions">
      @if($canManage)
        <a class="btn subtle" href="{{ route('dashboard.support') }}"><x-icon name="inbox" size="17"/> Support Management</a>
      @endif
      <a class="btn subtle" href="{{ route('support') }}"><x-icon name="book-open" size="17"/> Public Support Center</a>
      <a class="btn primary" href="{{ route($workspace.'.create') }}"><x-icon name="plus" size="17"/> Create support ticket</a>
    </div>
  </div>
  <section class="panel support-ticket-panel support-table-card" aria-labelledby="support-table-heading">
    <header class="support-table-head">
      <div>
        <h2 id="support-table-heading">Your requests <span class="count">{{ $tickets->total() }}</span></h2>
        <p>Browse, search and manage tickets opened from this account.</p>
      </div>
    </header>
    @include('support.filters', ['management'=>false])
    <div class="table-scroll support-table-scroll">
      <table class="data-table support-data-table">
        <thead><tr>
          <th scope="col">Ticket ID</th>
          <th scope="col">Subject</th>
          <th scope="col">Request type</th>
          <th scope="col">Status</th>
          <th scope="col">Assigned To</th>
          <th scope="col">Updated</th>
          <th scope="col" class="support-action-col">Actions</th>
        </tr></thead>
        <tbody>
          @forelse($tickets as $ticket)
            <tr>
              <td class="mono"><strong>#{{ $ticket->public_id }}</strong></td>
              <td><a class="support-ticket-subject" href="{{ route($workspace.'.show', $ticket->public_id) }}">{{ $ticket->subject }}</a></td>
              <td>{{ \App\Support\SupportTicketContext::ISSUES[$ticket->issue_type ?? 'general'] ?? 'General question' }}</td>
              <td><span class="support-status-pill support-status--{{ $ticket->status }}">{{ \App\Models\SupportTicket::STATUSES[$ticket->status] ?? ucfirst($ticket->status) }}</span></td>
              <td class="support-assignee-cell">{{ $ticket->assignee?->name ?? 'Unassigned' }}</td>
              <td>{{ $ticket->updated_at->format('d M Y') }}</td>
              <td>
                @include('support.row-actions', [
                  'management'=>false,
                  'detailUrl'=>route($workspace.'.show', $ticket->public_id),
                ])
              </td>
            </tr>
          @empty
            <tr><td class="empty-cell" colspan="7">
              <div class="support-empty-private">
                <x-icon name="life-buoy" size="30"/>
                <h3>No tickets found</h3>
                <p>Adjust the filters or open a new request for assistance.</p>
                <a class="btn primary" href="{{ route($workspace.'.create') }}">Create support ticket</a>
              </div>
            </td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    @include('support.pager', ['management'=>false])
  </section>
</div>
@endsection
