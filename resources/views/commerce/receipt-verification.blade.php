<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Receipt verification · {{ $platform }}</title>
  <link rel="stylesheet" href="{{ asset('css/order-receipt.css') }}?v=2">
  <script src="{{ asset('js/receipt-verification.js') }}" defer></script>
</head>
<body class="receipt-verification">
  <main class="verification-page">
    <div class="verification-toolbar">
      <a href="{{ route('home') }}">Visit {{ $platform }}</a>
      <button type="button" data-print-receipt hidden>Print A4</button>
    </div>
    <article class="receipt-paper verification-paper" aria-labelledby="verification-title">
      <header class="verification-header">
        <a href="{{ route('home') }}" aria-label="{{ $platform }} home">
          <img src="{{ $logo }}" alt="{{ $platform }}" width="550" height="144">
        </a>
        <div>
          <span class="receipt-status" data-status="{{ $status === 'Paid' ? 'paid' : 'recorded' }}">{{ $status }}</span>
          <h1 id="verification-title">Receipt verified</h1>
        </div>
      </header>
      <dl class="verification-facts">
        <div><dt>Order ID</dt><dd class="receipt-reference">{{ $reference }}</dd></div>
        <div><dt>{{ $status === 'Refunded' ? 'Total refunded' : ($status === 'Paid' ? 'Total paid' : 'Order total') }}</dt><dd>{{ $amount }}</dd></div>
        <div><dt>Date recorded (ICT)</dt><dd>{{ $recorded }}</dd></div>
      </dl>
      <section class="verification-listing" aria-labelledby="verification-listing">
        <h2 id="verification-listing">Marketplace listing</h2>
        @if($listingUrl)
          <a class="verification-listing-link" href="{{ $listingUrl }}">{{ $listing }}</a>
        @else
          <p>{{ $listing ?? 'Listing unavailable' }}</p>
        @endif
      </section>
      <div class="verification-parties">
        @foreach(['Customer' => $customer, 'Seller' => $seller] as $label => $party)
          <section class="verification-card" aria-label="{{ $label }} information">
            <h2>{{ $label }} information</h2>
            @if($party)
              <dl>
                <div><dt>Name</dt><dd>{{ $party['name'] }}</dd></div>
                <div><dt>Location</dt><dd>{{ filled($party['location']) ? $party['location'] : 'Location unavailable' }}</dd></div>
              </dl>
            @elseif($signInUrl)
              <a class="verification-sign-in" href="{{ $signInUrl }}">Sign in to view order details</a>
            @else
              <p>Available to authorized order viewers.</p>
            @endif
          </section>
        @endforeach
      </div>
      <section class="verification-platform" aria-labelledby="verification-platform">
        <div>
          <h2 id="verification-platform">{{ $platform }}</h2>
          @if($platformAddress !== '')
            <address>{{ $platformAddress }}</address>
          @endif
          <div class="verification-contact">
            <a href="{{ route('support') }}">Support Center</a>
            <a href="mailto:support@bambodia.com">support@bambodia.com</a>
          </div>
        </div>
        <div class="verification-telegram">
          <a href="{{ $telegramUrl }}" target="_blank" rel="noopener noreferrer">Join the Telegram group</a>
          <img src="{{ $telegramQr }}" width="88" height="88" alt="QR code for the Bambodia Telegram group">
        </div>
      </section>
      <footer class="verification-footer">
        <div class="receipt-footer">
          <a class="receipt-qr" href="{{ $verificationUrl }}" aria-label="Verify receipt for order {{ $reference }}">
            <img src="{{ $receiptQr }}" width="112" height="112" alt="">
          </a>
          <div>
            <h2>Verify this receipt</h2>
            <p>Scan to confirm the order ID, amount and payment status on {{ $platform }}.</p>
          </div>
        </div>
        <div class="verification-legal">
          <span>Issued electronically by {{ $platform }}</span>
          @include('commerce.receipt-legal-links')
        </div>
      </footer>
    </article>
  </main>
</body>
</html>
