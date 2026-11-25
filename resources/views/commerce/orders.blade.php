@extends('layouts.workspace')
@section('workspace', $workspace)
@section('title', $heading)
@section('content')
<div class="container inner-page">
  <div class="page-intro"><h1>{{ $heading }}</h1>
    @if(in_array($section, ['studio.orders','account.orders'], true) && config('commerce.enabled'))
      <p><a class="btn primary" href="{{ route($section === 'studio.orders' ? 'studio.tracking.index' : 'account.tracking.index') }}">Project Tracking</a>
      <span class="hint">Follow requirements, task progress, deliveries and client reviews.</span></p>
    @endif
  </div>
  <section class="panel">
    <div class="panel-head">
      <form method="get" class="table-search">
        <input name="q" value="{{ $search }}" aria-label="Search orders" placeholder="Order number or listing" maxlength="180">
        <select name="status" aria-label="Order status">
          <option value="">All statuses</option>
          @foreach(\App\Models\MarketplaceOrder::STATUSES as $value => $label)
            <option value="{{ $value }}" @selected($value === $status)>{{ $label }}</option>
          @endforeach
        </select>
        <button class="btn subtle compact">Filter</button>
      </form>
      <span class="count">{{ $orders->total() }} orders</span>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Listing</th><th>Placed</th><th>Total</th><th>Status</th><th>Receipt</th></tr></thead>
        <tbody>
        @forelse($orders as $order)
          <tr>
            <td><a class="listing-title-link" href="{{ route($section.'.show', $order->reference()) }}">{{ $order->listing_title }}</a>
              <div class="text-muted mono">{{ $order->reference() }}</div></td>
            <td>{{ $order->created_at->format('d M Y') }}</td>
            <td class="mono">{{ $order->currency }} {{ $order->total }}</td>
            <td><span class="role-pill">{{ \App\Models\MarketplaceOrder::STATUSES[$order->status] }}</span></td>
            <td>@if($order->receipt_number)
              <a class="btn subtle compact" href="{{ route($section.'.receipt', $order->reference()) }}">View receipt</a>
            @else — @endif</td>
          </tr>
        @empty
          <tr><td colspan="5" class="empty-cell">No orders found.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$orders"/>
  </section>
</div>
@endsection
