@extends('layouts.workspace')
@section('workspace', $workspace)
@section('title', 'Order Details')
@section('content')
<div class="container inner-page">
  <div class="page-intro panel-head">
    <div>
      <a href="{{ route($section.'.index') }}">Back to orders</a>
      <h1>{{ $order->listing_title }}</h1>
      <p class="mono">{{ $order->number }}</p>
    </div>
    <div class="commerce-operation-toolbar">
      @if($section === 'account.orders' && $order->entitlement?->isActive())
        <a class="btn primary compact" href="{{ route('account.downloads') }}">Downloads</a>
        <a class="btn subtle compact" href="{{ route('account.licenses.index') }}">My licenses</a>
      @endif
      @if($canOperate && $section === 'account.orders' && auth()->user()->hasVerifiedEmail())
        <a class="btn subtle compact" href="{{ route('account.orders.refund', $order->number) }}">Refunds</a>
      @elseif($canOperate && $section === 'dashboard.orders' && auth()->user()->role === 'super_admin')
        <a class="btn subtle compact" href="{{ route('dashboard.commerce-operations.show', $order->number) }}">Order settlement</a>
      @endif
      @if(config('commerce.operations_enabled') && $section === 'dashboard.orders' && auth()->user()->role === 'super_admin'
          && ! in_array(auth()->id(), [(int) $order->buyer_id, (int) $order->seller_id], true)
          && $order->status === 'completed' && $order->fulfillment_version_id && !$order->entitlement)
        <form method="post" action="{{ route('dashboard.orders.retry-delivery', $order->number) }}">
          @csrf <button class="btn subtle compact">Retry delivery</button>
        </form>
      @endif
    </div>
  </div>
  <section class="panel">
    <div class="panel-head">
      <h2>Order details</h2><span class="role-pill">{{ \App\Models\MarketplaceOrder::STATUSES[$order->status] }}</span>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <tbody>
          <tr><th scope="row">Buyer</th><td>{{ $order->buyer_name }}</td></tr>
          <tr><th scope="row">Seller</th><td>{{ $order->seller_name }}</td></tr>
          @if($order->license_name)<tr><th scope="row">License</th><td>{{ $order->license_name }}</td></tr>@endif
          <tr><th scope="row">Order total</th><td class="mono">{{ $order->currency }} {{ $order->total }}</td></tr>
          @if($order->payment_method)<tr><th scope="row">Preferred payment method</th><td>{{ $order->payment_method }}</td></tr>@endif
          <tr><th scope="row">Placed</th><td>{{ $order->created_at->format('d M Y H:i') }}</td></tr>
          @if($order->completed_at)<tr><th scope="row">Recorded</th><td>{{ $order->completed_at->format('d M Y H:i') }}</td></tr>@endif
          @if($order->fulfillment_version_id && in_array($order->status, ['completed', 'refunded'], true))
            <tr><th scope="row">Digital delivery</th><td>{{ $order->entitlement?->isActive() ? 'Available' : ($order->status === 'refunded' || $order->entitlement ? 'Access revoked' : 'Delivery pending') }}</td></tr>
          @endif
          @if(($settlement = $order->operations) && $settlement->refund_status)
            <tr><th scope="row">Refund</th><td>{{ ucfirst($settlement->refund_status) }}</td></tr>
          @endif
          @if($section === 'studio.orders' && $settlement?->seller_payable !== null)
            <tr><th scope="row">Seller payable</th><td>{{ $order->currency }} {{ $settlement->seller_payable }}</td></tr>
            @if($settlement->payout_reference)<tr><th scope="row">Payout reference</th><td>{{ $settlement->payout_reference }}</td></tr>@endif
            @if($settlement->recoveryDue())<tr><th scope="row">Payout recovery due</th><td>{{ $order->currency }} {{ $settlement->seller_payable }}</td></tr>@endif
          @endif
          @if($order->cancelled_at)<tr><th scope="row">Cancelled</th><td>{{ $order->cancelled_at->format('d M Y H:i') }}</td></tr>@endif
        </tbody>
      </table>
    </div>
    @if(config('commerce.payments_enabled'))
      @include('commerce.payments')
    @endif
    @php($paymentOpen = $payments->contains(fn ($payment) => in_array($payment->status, \App\Models\CommercePayment::ACTIVE, true)))
    @if(!config('commerce.payments_enabled') && !$paymentOpen && $section === 'account.orders' && $order->status === 'awaiting_payment' && ($methods->isNotEmpty() || $order->payment_method))
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
    @if($order->receipt_number || (!$paymentOpen && $section === 'account.orders' && $order->status === 'awaiting_payment'))
      <div class="panel-head">
        @if($order->receipt_number)
          <a class="btn primary" href="{{ route($section.'.receipt', $order->number) }}">View receipt</a>
        @else
          <form method="post" action="{{ route('account.orders.cancel', $order->number) }}">
            @csrf @method('PATCH')
            <button class="btn subtle">Cancel order</button>
          </form>
        @endif
      </div>
    @endif
  </section>
</div>
@endsection
