@extends('layouts.workspace')
@section('workspace', $creator ? 'creator' : 'admin')
@section('title', $creator ? 'Sales Settlements' : 'Commerce Operations')
@section('content')
@php
  $indexRoute = $creator ? 'studio.commerce-operations' : 'dashboard.commerce-operations';
  $statementRoute = $indexRoute.'.statement';
@endphp
<div class="container inner-page">
  <section class="panel">
    <header class="panel-head">
      <div>
        <h1>{{ $creator ? 'Sales Settlements' : 'Commerce Operations' }}</h1>
        @if($orders->total())
          <p>Showing {{ $orders->firstItem() ?? 0 }}–{{ $orders->lastItem() ?? 0 }} of {{ $orders->total() }}</p>
        @endif
      </div>
      <div class="commerce-operation-toolbar">
        @if($filters)
          <a class="btn subtle compact" href="{{ route($indexRoute) }}">Clear filters</a>
        @endif
        @if($orders->total())
          <a class="btn subtle compact" href="{{ route($statementRoute, $filters) }}">Download CSV</a>
        @endif
        <button class="btn primary compact" type="submit" form="settlement-filters">Apply filters</button>
        @if($orders->previousPageUrl())
          <a class="btn subtle compact" href="{{ $orders->previousPageUrl() }}">Previous</a>
        @endif
        @if($orders->nextPageUrl())
          <a class="btn subtle compact" href="{{ $orders->nextPageUrl() }}">Next</a>
        @endif
      </div>
    </header>
    <form id="settlement-filters" class="settings-form grid gap-x-4 sm:grid-cols-2 xl:grid-cols-4" method="get" action="{{ route($indexRoute) }}">
      <div>
        <label for="settlement-order">Order number</label>
        <input id="settlement-order" name="order" value="{{ old('order', $filters['order'] ?? '') }}" maxlength="36">
      </div>
      <div>
        <label for="settlement-queue">Settlement status</label>
        <select id="settlement-queue" name="queue">
          <option value="">All statuses</option>
          @foreach(\App\Support\CommerceSettlementReport::QUEUES as $key => $label)
            <option value="{{ $key }}" @selected(old('queue', $filters['queue'] ?? '') === $key)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="settlement-from">Payment verified from (UTC)</label>
        <input id="settlement-from" type="date" name="from" value="{{ old('from', $filters['from'] ?? '') }}">
      </div>
      <div>
        <label for="settlement-to">Payment verified through (UTC)</label>
        <input id="settlement-to" type="date" name="to" value="{{ old('to', $filters['to'] ?? '') }}">
      </div>
    </form>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr><th scope="col">Order</th><th scope="col">Payment verified</th><th scope="col">Amount collected</th><th scope="col">Statement</th><th scope="col">Refund</th><th scope="col">Seller payout</th></tr>
        </thead>
        <tbody>
        @forelse($orders as $order)
          @php($record = $order->operations)
          <tr>
            <td>
              <a class="listing-title-link" href="{{ route($creator ? 'studio.orders.show' : 'dashboard.commerce-operations.show', $order->number) }}">{{ $order->listing_title }}</a>
              <div class="text-muted mono">{{ $order->number }}</div>
            </td>
            <td>{{ $order->completed_at?->toDateString() ?? '—' }}</td>
            <td>{{ $order->currency }} {{ $order->total }}</td>
            <td>{{ $record?->reconciled_at ? 'Matched' : 'Awaiting match' }}</td>
            <td>{{ $record?->refund_status ? ucfirst($record->refund_status) : '—' }}</td>
            <td>
              @php($payoutStatus = \App\Support\CommerceSettlementReport::payoutStatus($order))
              {{ $payoutStatus }}
              @if($payoutStatus === 'Payout pending')
                <div class="text-muted">{{ $order->currency }} {{ $record->seller_payable }}</div>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty-cell">{{ $filters ? 'No verified orders match your filters.' : 'No verified orders found.' }}</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </section>
</div>
@endsection
