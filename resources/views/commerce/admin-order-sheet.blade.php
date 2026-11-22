@php
  $isPaid = $receiptStatus === 'Paid';
  $attempt = in_array($receiptStatus, ['Paid', 'Refunded'], true)
      ? $payments->first(fn ($payment) => $payment->status === 'paid' && $payment->settlement_reference
          && ($payment->live_mode || !app()->isProduction()))
      : $payments->first();
  $orderState = \App\Models\MarketplaceOrder::STATUSES[$order->status];
  $amount = $order->currency.' '.$order->total;
  $totalLabel = match ($receiptStatus) {
      'Paid' => 'Total paid',
      'Refunded' => 'Total refunded',
      default => $order->status === 'awaiting_payment' ? 'Total due' : 'Order total',
  };
  $paymentStatus = match ($receiptStatus) {
      'Paid' => 'Verified',
      'Refunded' => 'Refunded',
      'No payment required' => 'No payment required',
      default => match ($attempt?->status) {
          'paid' => 'Payment recorded',
          null => 'No payment submitted',
          default => \App\Models\CommercePayment::LABELS[$attempt->status],
      },
  };
  $recordedLabel = match (true) {
      $order->cancelled_at !== null => 'Order cancelled (ICT)',
      $receiptStatus === 'No payment required' => 'Order completed (ICT)',
      default => 'Payment recorded (ICT)',
  };
@endphp
<div class="admin-order-toolbar">
  <a class="btn subtle compact" href="{{ route($section.'.index') }}"><x-icon name="chevron-left" size="16"/> Back to orders</a>
  <button type="button" class="btn subtle compact" data-print-page>Print order</button>
</div>
<article class="receipt-paper admin-order-sheet" aria-labelledby="admin-order-heading">
  <header class="admin-order-header">
    <div class="admin-order-brand">
      <a href="{{ route('home') }}">
        <img src="{{ \App\Models\SiteBrandAsset::urlFor('logo_light') }}" alt="{{ \App\Models\SiteSetting::setting('identity.name') }}" width="550" height="144">
      </a>
      <p>{{ parse_url(config('app.url'), PHP_URL_HOST) }}</p>
    </div>
    <div class="admin-order-heading">
      <h1 id="admin-order-heading">{{ $isPaid ? 'Official receipt' : 'Order details' }}</h1>
      <p class="receipt-reference">Order #{{ $order->reference() }}</p>
      <span class="receipt-status" data-status="{{ $isPaid ? 'paid' : 'recorded' }}">{{ $isPaid ? 'Completed / Paid' : $orderState }}</span>
    </div>
  </header>

  <dl class="admin-order-facts">
    <div><dt>Billed to (Buyer)</dt><dd>{{ $order->buyer_name }}</dd></div>
    <div><dt>Sold by (Seller)</dt><dd>{{ $order->seller_name }}</dd></div>
    <div><dt>Order placed (ICT)</dt><dd>{{ $order->created_at->setTimezone('Asia/Phnom_Penh')->format('d M Y, H:i') }}</dd></div>
    <div>
      <dt>{{ $order->status === 'awaiting_payment' && !$attempt ? 'Payment preference' : 'Payment method' }}</dt>
      <dd>{{ $attempt?->method_name ?? $order->payment_method ?? '—' }}</dd>
    </div>
    <div>
      <dt>{{ $recordedLabel }}</dt>
      <dd>{{ ($order->cancelled_at ?? $order->completed_at)?->setTimezone('Asia/Phnom_Penh')->format('d M Y, H:i') ?? '—' }}</dd>
    </div>
    <div><dt>Payment status</dt><dd>{{ $paymentStatus }}</dd></div>
  </dl>

  <table class="receipt-table admin-order-items" aria-label="Order item">
    <thead>
      <tr><th scope="col">Description</th><th scope="col">Seller</th><th scope="col">Qty</th><th scope="col">Unit price</th><th scope="col">Total</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>{{ $order->listing_title }}</strong>
          @if($order->license_name)<span class="receipt-item-detail">{{ $order->license_name }}</span>@endif
        </td>
        <td>{{ $order->seller_name }}</td><td>1</td><td>{{ $amount }}</td><td>{{ $amount }}</td>
      </tr>
    </tbody>
  </table>
  <dl class="admin-order-totals">
    <div><dt>Subtotal</dt><dd>{{ $amount }}</dd></div>
    <div><dt>{{ $totalLabel }}</dt><dd>{{ $amount }}</dd></div>
  </dl>

  @if($payments->isNotEmpty())
    <section class="admin-order-activity" aria-label="Payment activity">
      @include('commerce.payment-activity')
    </section>
  @endif

  @if($hasReceipt)
    <footer class="admin-order-footer">
      <div class="admin-order-receipt-actions">
        @if($verificationUrl)
          <a class="receipt-qr" href="{{ $verificationUrl }}" aria-label="Verify receipt for order {{ $order->reference() }}">
            <img src="{{ $verificationQr }}" width="112" height="112" alt="">
          </a>
          <div class="admin-order-verify-copy">
            <h2>Verify this receipt</h2>
            <p>Scan to confirm the order and payment status.</p>
          </div>
        @endif
        <a class="btn primary admin-order-receipt-link" href="{{ route($section.'.receipt', $order->reference()) }}">View receipt</a>
      </div>
      <p class="admin-order-issued">Issued electronically by {{ \App\Models\SiteSetting::setting('identity.name') }}.</p>
    </footer>
  @endif
</article>
