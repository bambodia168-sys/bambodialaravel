@extends('layouts.workspace')
@section('workspace', $workspace)
@section('title', 'Order Receipt')
@push('head')
  <link rel="stylesheet" href="{{ asset('css/order-review.css') }}?v=1">
@endpush
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
    <div class="order-review-summary-head">
      <div>
        <h2>{{ $order->listing_title }}</h2>
        <p class="order-review-total">{{ $order->currency }} {{ $order->total }}</p>
      </div>
      <x-order-payment-stamp :order="$order"/>
    </div>
    <dl class="order-review-facts">
      <div><dt>Order</dt><dd>{{ $order->number }}</dd></div>
      <div><dt>Date recorded</dt><dd>{{ $order->completed_at->format('d M Y H:i') }}</dd></div>
      <div><dt>Buyer</dt><dd>{{ $order->buyer_name }}</dd></div>
      <div><dt>Seller</dt><dd>{{ $order->seller_name }}</dd></div>
      @if($order->license_name)<div><dt>License selection</dt><dd>{{ $order->license_name }}</dd></div>@endif
      <div><dt>Payment</dt><dd>{{ $order->total === '0.00' ? 'No payment required' : $order->payment_method.' · Verified' }}</dd></div>
      @if($order->status === 'refunded')<div><dt>Refund</dt><dd>Full refund confirmed</dd></div>@endif
    </dl>
    <div class="panel-head commerce-receipt-actions">
      <a class="btn subtle" href="{{ route($section.'.show', $order->number) }}">Back to order</a>
      <button type="button" class="btn primary" data-print-page>Print receipt</button>
    </div>
  </section>
</article>
@endsection
