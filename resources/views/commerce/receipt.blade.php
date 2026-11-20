@extends('layouts.workspace')
@section('workspace', $workspace)
@section('title', 'Order Receipt')
@section('content')
<article class="container inner-page commerce-receipt">
  @if($section === 'account.orders')
    <x-checkout-progress :order="$order"/>
  @endif
  <div class="page-intro">
    <h1>Order receipt</h1>
    <p class="mono">{{ $order->receipt_number }}</p>
  </div>
  <section class="panel">
    <div class="panel-head"><h2>{{ $order->listing_title }}</h2></div>
    <div class="table-scroll">
      <table class="data-table"><tbody>
        <tr><th scope="row">Order</th><td class="mono">{{ $order->number }}</td></tr>
        <tr><th scope="row">Date recorded</th><td>{{ $order->completed_at->format('d M Y H:i') }}</td></tr>
        <tr><th scope="row">Buyer</th><td>{{ $order->buyer_name }}</td></tr>
        <tr><th scope="row">Seller</th><td>{{ $order->seller_name }}</td></tr>
        @if($order->license_name)<tr><th scope="row">License selection</th><td>{{ $order->license_name }}</td></tr>@endif
        <tr><th scope="row">Payment</th><td>{{ $order->total === '0.00' ? 'No payment required' : $order->payment_method.' · Verified' }}</td></tr>
        @if($order->status === 'refunded')<tr><th scope="row">Refund</th><td>Full refund confirmed</td></tr>@endif
        <tr><th scope="row">Total</th><td class="mono"><strong>{{ $order->currency }} {{ $order->total }}</strong></td></tr>
      </tbody></table>
    </div>
    <div class="panel-head commerce-receipt-actions">
      <a class="btn subtle" href="{{ route($section.'.show', $order->number) }}">Back to order</a>
      <button type="button" class="btn primary" data-print-page>Print receipt</button>
    </div>
  </section>
</article>
@endsection
