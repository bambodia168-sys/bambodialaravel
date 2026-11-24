@extends('layouts.dashboard')
@section('title', 'Support Management')
@push('head')
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=4">
@endpush
@section('content')
@php
  $management = true;
  $canManage = true;
  $action = route('dashboard.support');
@endphp
<div class="container inner-page support-workspace">
  <div class="support-workspace-heading support-list-heading">
    <div class="support-heading-copy">
      <div class="eyebrow">Administration · Customer care</div>
      <h1>Support Management</h1>
      <p>Review private support requests, assign authorized agents, manage ticket statuses and follow conversations.</p>
    </div>
    <div class="support-heading-actions">
      <a class="btn subtle" href="{{ route('dashboard.support-tickets.index') }}"><x-icon name="message-circle" size="17"/> My Support Tickets</a>
      <a class="btn subtle" href="{{ route('support') }}"><x-icon name="book-open" size="17"/> Public Support Center</a>
      <a class="btn primary" href="{{ route('dashboard.support-tickets.create') }}"><x-icon name="plus" size="17"/> Create support ticket</a>
    </div>
  </div>
  <section class="panel support-ticket-panel support-table-card" aria-labelledby="support-table-heading">
    <header class="support-table-head">
      <div>
        <h2 id="support-table-heading">Support requests <span class="count">{{ $tickets->total() }}</span></h2>
        <p>Manage active support tickets while respecting account ownership and staff permissions.</p>
      </div>
      <nav class="support-status-tabs" aria-label="Filter by status">
        <a href="{{ route('dashboard.support', request()->except('status','page')) }}"
          class="{{ $filters['status'] === '' ? 'is-active' : '' }}">All <span>{{ $counts->sum() }}</span></a>
        @foreach(\App\Models\SupportTicket::STATUSES as $value => $label)
          <a href="{{ route('dashboard.support', array_merge(request()->except('status','page'), ['status'=>$value])) }}"
            class="{{ $filters['status'] === $value ? 'is-active' : '' }}">{{ $label }} <span>{{ (int)$counts->get($value,0) }}</span></a>
        @endforeach
      </nav>
    </header>
    @include('support.filters')
    <div class="table-scroll support-table-scroll">
      <table class="data-table support-data-table">
        <thead><tr>
          <th scope="col">Ticket ID</th>
          <th scope="col">Subject</th>
          <th scope="col">Request type</th>
          <th scope="col">Customer</th>
          <th scope="col">Status</th>
          <th scope="col">Assigned To</th>
          <th scope="col">Updated</th>
          <th scope="col" class="support-action-col">Actions</th>
        </tr></thead>
        <tbody>
          @forelse($tickets as $ticket)
            <tr>
              <td class="mono"><strong>#{{ $ticket->public_id }}</strong></td>
              <td><a class="support-ticket-subject" href="{{ route('dashboard.support.show', $ticket->public_id) }}">{{ $ticket->subject }}</a></td>
              <td>{{ \App\Support\SupportTicketContext::ISSUES[$ticket->issue_type ?? 'general'] ?? 'General question' }}</td>
              <td>{{ $ticket->owner?->name ?? 'Former account' }}</td>
              <td><span class="support-status-pill support-status--{{ $ticket->status }}">{{ \App\Models\SupportTicket::STATUSES[$ticket->status] ?? ucfirst($ticket->status) }}</span></td>
              <td class="support-assignee-cell">{{ $ticket->assignee?->name ?? 'Unassigned' }}</td>
              <td>{{ $ticket->updated_at->format('d M Y') }}</td>
              <td>
                @include('support.row-actions', [
                  'detailUrl'=>route('dashboard.support.show', $ticket->public_id),
                ])
              </td>
            </tr>
          @empty
            <tr><td class="empty-cell" colspan="8"><div class="support-empty-private">
              <x-icon name="inbox" size="30"/>
              <h3>No matching support tickets</h3>
              <p>Try changing the filters to view available tickets.</p>
            </div></td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    @include('support.pager')
  </section>
</div>
@endsection
