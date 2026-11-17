@extends('layouts.workspace')
@section('workspace', $creator ? 'creator' : 'admin')
@section('title', $creator ? 'Sales Settlements' : 'Commerce Operations')
@section('content')
<div class="container inner-page">
  <section class="panel">
    <header class="panel-head">
      <h1>{{ $creator ? 'Sales Settlements' : 'Commerce Operations' }}</h1>
      <form class="table-search commerce-operation-toolbar" method="get">
        <input aria-label="Order number" name="order" value="{{ $number }}" placeholder="Order number" maxlength="36">
        <button class="btn primary compact">Find order</button>
      </form>
    </header>
    @error('order')<p class="commerce-operation-message" role="alert">{{ $message }}</p>@enderror
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr><th scope="col">Order</th><th scope="col">Amount collected</th><th scope="col">Statement</th><th scope="col">Refund</th><th scope="col">Seller payout</th></tr>
        </thead>
        <tbody>
        @forelse($orders as $order)
          @php($record = $order->operations)
          <tr>
            <td>
              <a class="listing-title-link" href="{{ route($creator ? 'studio.orders.show' : 'dashboard.commerce-operations.show', $order->number) }}">{{ $order->listing_title }}</a>
              <div class="text-muted mono">{{ $order->number }}</div>
            </td>
            <td>{{ $order->currency }} {{ $order->total }}</td>
            <td>{{ $record?->reconciled_at ? 'Matched' : 'Awaiting match' }}</td>
            <td>{{ $record?->refund_status ? ucfirst($record->refund_status) : '—' }}</td>
            <td>
              @if($record?->recoveryDue()) Recovery due
              @elseif($record?->recovered_at) Recovered
              @elseif($record?->paid_out_at) Paid out
              @elseif($order->status === 'refunded') Cancelled after refund
              @elseif($record?->refund_status === 'requested') On hold
              @elseif($record?->seller_payable === '0.00') Nothing payable
              @elseif($record?->seller_payable !== null) {{ $order->currency }} {{ $record->seller_payable }} pending
              @else Awaiting reconciliation
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="empty-cell">No verified orders found.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$orders"/>
  </section>
</div>
@endsection
