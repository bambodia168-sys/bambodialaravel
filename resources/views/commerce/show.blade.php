@extends('layouts.workspace')
@section('workspace', $workspace)
@section('title', 'Order Details')
@section('content')
<div class="container inner-page">
  <div class="page-intro">
    <a class="btn subtle compact" href="{{ route($section.'.index') }}">Back to orders</a>
    <h1>{{ $order->listing_title }}</h1>
    <p class="mono">{{ $order->number }}</p>
  </div>
  @if($order->relationLoaded('serviceInquiry') && $order->serviceInquiry)
    <section class="panel">
      <div class="panel-head">
        <div>
          <h2>Service workspace</h2>
          <p>Use the private conversation to coordinate delivery.</p>
        </div>
        <a class="btn primary" href="{{ route('inquiries.show', $order->serviceInquiry) }}">Open service workspace</a>
      </div>
    </section>
  @elseif($section === 'account.orders' && $order->relationLoaded('entitlement') && $order->entitlement?->isActive())
    <section class="panel">
      <div class="panel-head">
        <h2>Product access</h2>
        <div class="panel-actions">
          <a class="btn primary" href="{{ route('account.downloads.file', $order->entitlement) }}">Download product</a>
          <a class="btn subtle" href="{{ route('account.licenses.index') }}">My licenses</a>
        </div>
      </div>
    </section>
  @endif
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
