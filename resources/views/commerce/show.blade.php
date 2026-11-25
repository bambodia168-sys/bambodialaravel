@extends('layouts.workspace')
@section('workspace', $workspace)
@section('title', 'Order Details')
@php
  $buyerView = $section === 'account.orders';
  $adminView = $section === 'dashboard.orders';
  $paymentOpen = $payments->contains(fn ($payment) => in_array($payment->status, \App\Models\CommercePayment::ACTIVE, true));
@endphp
@if($buyerView)
  @push('head')
    <link rel="stylesheet" href="{{ asset('css/checkout-payment-methods.css') }}?v=2">
    <link rel="stylesheet" href="{{ asset('css/order-review.css') }}?v=4">
  @endpush
@endif
@if($showOrderSheet)
  @push('head')
    <link rel="stylesheet" href="{{ asset('css/order-receipt.css') }}?v=4">
    <link rel="stylesheet" href="{{ asset('css/admin-order-detail.css') }}?v=2">
  @endpush
@endif
@section('content')
<div @class(['container', 'inner-page', 'order-review' => $buyerView, 'order-sheet-page' => $showOrderSheet])>
  @if($buyerView)
    <x-checkout-progress :order="$order" :payment-pending="$paymentOpen"/>
  @endif
  @if($adminView)
    @include('commerce.admin-order-sheet')
  @elseif(!$showOrderSheet)
    <div class="page-intro">
      <a class="btn subtle compact" href="{{ route($section.'.index') }}">Back to orders</a>
      <h1>{{ $order->listing_title }}</h1>
      <p class="mono">{{ $order->reference() }}</p>
    </div>
  @endif
  @if($buyerView && !$showOrderSheet)
    <section class="panel order-review-summary">
      <div class="order-review-summary-head">
        <div>
          <h2>Order summary</h2>
          <p class="order-review-total">{{ $order->currency }} {{ $order->total }}</p>
        </div>
        <x-order-payment-stamp :order="$order" :payment-open="$paymentOpen" :has-attempts="$payments->isNotEmpty()"/>
      </div>
      <dl class="order-review-facts">
        <div><dt>Buyer</dt><dd>{{ $order->buyer_name }}</dd></div>
        <div><dt>Seller</dt><dd>{{ $order->seller_name }}</dd></div>
        <div><dt>Placed</dt><dd>{{ $order->created_at->format('d M Y H:i') }}</dd></div>
        @if($order->license_name)<div><dt>License</dt><dd>{{ $order->license_name }}</dd></div>@endif
        @if($order->payment_method)<div><dt>{{ $order->status === 'awaiting_payment' ? 'Payment preference' : 'Payment method' }}</dt><dd>{{ $order->payment_method }}</dd></div>@endif
        @if($order->completed_at)<div><dt>Recorded</dt><dd>{{ $order->completed_at->format('d M Y H:i') }}</dd></div>@endif
        @if($order->cancelled_at)<div><dt>Cancelled</dt><dd>{{ $order->cancelled_at->format('d M Y H:i') }}</dd></div>@endif
      </dl>
    </section>
  @endif
  @if($order->status === 'refunded')
    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Full refund confirmed</h2>
          @if($order->relationLoaded('operations') && $order->operations?->refund_access === 'revoke')
            <p>Download and license access have ended for this order.</p>
          @elseif($order->relationLoaded('operations') && $order->operations?->refund_access === 'retain')
            <p>The recorded refund decision retained existing product access.</p>
          @endif
          @if($section === 'dashboard.orders' && auth()->user()->role === 'super_admin' && $order->relationLoaded('operations') && $order->operations?->refund_reference)
            <p>Stripe reference <span class="mono">{{ $order->operations->refund_reference }}</span></p>
            <p>{{ $order->operations->refund_note }}</p>
          @endif
        </div>
        @if($section === 'account.orders')
          <a class="btn subtle" href="{{ route('support') }}">Get support</a>
        @endif
      </div>
    </section>
  @endif
  @if($order->relationLoaded('serviceInquiry') && $order->serviceInquiry)
    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Service workspace</h2>
          <p>{{ \App\Models\ServiceInquiry::DELIVERY_STATUSES[$order->serviceInquiry->deliveryStatus()] }}</p>
        </div>
        <div class="panel-actions">
          @if($order->status === 'completed' && $order->paidAttempt()->exists())
            <a class="btn primary" href="{{ route('tracking.orders.show', $order->reference()) }}">Track project &amp; delivery</a>
          @endif
          <a class="btn subtle" href="{{ route('inquiries.show', $order->serviceInquiry) }}">Open service workspace</a>
        </div>
      </div>
    </section>
  @elseif($section === 'account.orders' && $order->relationLoaded('entitlement') && $order->entitlement?->isActive()
      && ($order->license_tier === null || $order->entitlement->license_exists))
    @php($installationGuide = $order->license_tier && $order->entitlement->version?->file_type === 'zip')
    <section class="panel">
      <div class="panel-head">
        <h2>{{ $installationGuide ? 'Ready to install' : 'Product access' }}</h2>
        <div class="panel-actions">
          <a class="btn primary" href="{{ route('account.downloads.file', $order->entitlement) }}">Download product</a>
          <a class="btn subtle" href="{{ route('account.licenses.index') }}">My licenses</a>
          @if($installationGuide)
            <a class="btn subtle" href="#installation-guide">Installation guide</a>
          @endif
        </div>
      </div>
      @if($installationGuide)
        <div class="panel-head" id="installation-guide">
          <div>
            <h3>Installation guide</h3>
            <ol>
              <li>Download the package and open its INSTALL.txt or README for hosting requirements and setup instructions.</li>
              <li>Open My licenses and reveal the key for this product after confirming your password.</li>
              <li>Follow the package instructions and enter the key in the installer on your HTTPS domain if prompted.</li>
            </ol>
            <a href="{{ route('support') }}">Get installation support</a>
          </div>
        </div>
      @endif
    </section>
  @elseif($section === 'account.orders' && $order->status === 'completed'
      && (!$order->entitlement || ($order->entitlement->isActive() && !$order->entitlement->license_exists))
      && !$order->serviceInquiry
      && ($verifiedGateway = $payments->first(fn ($payment) => $payment->status === 'paid' && $payment->remote_id
          && $payment->settlement_reference && ($payment->live_mode || !app()->isProduction()))))
    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Purchase delivery pending</h2>
          <p>Your payment is verified. Check delivery again or contact support with your order number.</p>
        </div>
        <form method="post" action="{{ route('account.orders.payment.verify', [$order->number, $verifiedGateway->number]) }}">
          @csrf
          <button class="btn subtle">Check delivery</button>
        </form>
      </div>
    </section>
  @endif
  @if($canRecordStripeRefund)
    @include('commerce.operations.stripe-refund')
  @endif
  @if($showOrderSheet && !$adminView)
    @include('commerce.admin-order-sheet')
  @elseif(!$showOrderSheet && ($payments->isNotEmpty() || $order->status === 'awaiting_payment'))
  <section class="panel">
    @if($collectingPayments || $payments->isNotEmpty())
      @include('commerce.payments')
    @endif
    @if(!$collectingPayments && !$paymentOpen && $section === 'account.orders' && $order->status === 'awaiting_payment' && ($methods->isNotEmpty() || $order->payment_method))
      <div class="panel-head">
        <form method="post" action="{{ route('account.orders.payment-method', $order->number) }}" class="form-stack">
          @csrf @method('PATCH')
          <label>Preferred payment method
            <select name="payment_method">
              <option value="">No preference</option>
              @foreach($methods as $method)
                <option value="{{ $method->id }}" @selected($method->name === $order->payment_method)>{{ $method->name }}</option>
              @endforeach
            </select>
          </label>
          <button class="btn subtle compact">Update payment preference</button>
        </form>
      </div>
    @endif
    @if(!$paymentOpen && $order->status === 'awaiting_payment')
      <div class="panel-head">
        <form method="post" action="{{ route('account.orders.cancel', $order->number) }}">
          @csrf @method('PATCH')
          <button class="btn subtle">Cancel order</button>
        </form>
      </div>
    @endif
  </section>
  @endif
  @if($order->relationLoaded('review') && ($canReview || $order->review))
    @include('commerce.review')
  @endif
</div>
@endsection
