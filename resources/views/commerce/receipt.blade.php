@extends('layouts.workspace')
@section('workspace', $workspace)
@section('title', 'Order Receipt '.$order->reference())
@push('head')
  <link rel="stylesheet" href="{{ asset('css/order-review.css') }}?v=1">
  <link rel="stylesheet" href="{{ asset('css/order-receipt.css') }}?v=4">
@endpush
@section('content')
<article class="container inner-page commerce-receipt">
  @if($section === 'account.orders')
    <x-checkout-progress :order="$order"/>
  @endif
  <div class="commerce-receipt-actions">
    <a class="btn subtle" href="{{ route($section.'.show', $order->reference()) }}">Back to order</a>
    <div class="receipt-action-buttons">
      @if($section === 'account.orders' && $receiptStatus === 'Paid')
        <form method="post" action="{{ route('account.orders.receipt.email', $order->reference()) }}">
          @csrf
          <button class="btn subtle">Email receipt</button>
        </form>
      @endif
      <button type="button" class="btn primary" data-print-page>Print receipt</button>
    </div>
  </div>
  <section class="receipt-paper" aria-labelledby="receipt-heading">
    <header class="receipt-heading">
      <img src="{{ \App\Models\SiteBrandAsset::urlFor('logo_light') }}" alt="{{ \App\Models\SiteSetting::setting('identity.name') }}" width="550" height="144">
      <div>
        <h1 id="receipt-heading">Order receipt</h1>
        <span class="receipt-status" data-status="{{ $receiptStatus === 'Paid' ? 'paid' : 'recorded' }}" data-payment-stamp="{{ match ($receiptStatus) {
          'Paid' => 'paid', 'Refunded' => 'refunded', 'No payment required' => 'free', default => 'recorded',
        } }}">{{ $receiptStatus ?? 'Recorded' }}</span>
      </div>
    </header>

    <div class="receipt-parties">
      @foreach(['buyer' => ['Billed to', $order->buyer_name], 'seller' => ['Seller', $order->seller_name]] as $party => [$label, $name])
        <div>
          <h2>{{ $label }}</h2>
          <p class="receipt-party-name">{{ $name }}</p>
          <dl class="receipt-contact">
            <div>
              <dt aria-label="Location"><x-icon name="map-pin" size="16"/></dt>
              <dd>{{ filled($contacts[$party]['location']) ? $contacts[$party]['location'] : 'Location unavailable' }}</dd>
            </div>
            @if(filled($contacts[$party]['email']))
              <div>
                <dt aria-label="Email"><x-icon name="mail" size="16"/></dt>
                <dd><a href="mailto:{{ $contacts[$party]['email'] }}">{{ $contacts[$party]['email'] }}</a></dd>
              </div>
            @endif
          </dl>
        </div>
      @endforeach
    </div>
    <dl class="receipt-metadata">
      <div><dt>Order ID</dt><dd class="receipt-reference">{{ $order->reference() }}</dd></div>
      <div><dt>Date recorded <span>(ICT)</span></dt><dd>{{ $order->completed_at?->setTimezone('Asia/Phnom_Penh')->format('d M Y, H:i') ?? 'Unavailable' }}</dd></div>
    </dl>

    <div class="receipt-table-wrap">
      <table class="receipt-table">
        <thead>
          <tr>
            <th scope="col">#</th>
            <th scope="col">Item description</th>
            <th scope="col">Qty</th>
            <th scope="col">Unit price</th>
            <th scope="col">Amount</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>1</td>
            <td><strong>{{ $order->listing_title }}</strong>
              @if($order->license_name)<span class="receipt-item-detail">{{ $order->license_name }}</span>@endif
            </td>
            <td>1</td>
            <td>{{ $order->total }}</td>
            <td>{{ $order->total }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="receipt-summary">
      <div>
        <h2>{{ match ($receiptStatus) {
          'Paid' => 'Payment confirmation',
          'Refunded' => 'Refund confirmation',
          default => 'Order confirmation',
        } }}</h2>
        <p>{{ match ($receiptStatus) {
          'Paid' => $order->payment_method ? $order->payment_method.' · Verified' : 'Payment verified',
          'Refunded' => 'Full refund confirmed.',
          'No payment required' => 'No payment required',
          default => 'Payment verification unavailable.',
        } }}</p>
      </div>
      <dl class="receipt-total">
        <div><dt>{{ match ($receiptStatus) {
          'Paid' => 'Total paid',
          'Refunded' => 'Total refunded',
          default => 'Order total',
        } }}</dt><dd>{{ $order->currency }} {{ $order->total }}</dd></div>
      </dl>
    </div>

    <footer class="order-receipt-footer">
      @if($verificationUrl)
        <div class="receipt-footer">
          <a class="receipt-qr" href="{{ $verificationUrl }}" aria-label="Verify receipt for order {{ $order->reference() }}">
            <img src="{{ $qr }}" width="112" height="112" alt="">
          </a>
          <div>
            <h2>Verify this receipt</h2>
            <p>Scan the QR code to check this receipt on Bambodia.</p>
            <a href="{{ $verificationUrl }}">Open verification page</a>
          </div>
        </div>
      @endif
      <div class="order-receipt-publisher">
        <div class="order-receipt-legal">
          <p>Issued electronically by {{ \App\Models\SiteSetting::setting('identity.name') }}.</p>
          @include('commerce.receipt-legal-links')
        </div>
        <a class="order-receipt-telegram" href="{{ $telegramUrl }}" target="_blank" rel="noopener noreferrer">
          Telegram group
          <img src="{{ $telegramQr }}" width="80" height="80" alt="">
        </a>
      </div>
    </footer>
  </section>
</article>
@endsection
