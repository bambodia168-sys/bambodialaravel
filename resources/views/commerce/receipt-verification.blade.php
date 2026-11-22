<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Receipt verification · Bambodia</title>
  <link rel="stylesheet" href="{{ asset('css/order-receipt.css') }}?v=1">
</head>
<body class="receipt-verification">
  <main>
    <img class="receipt-verification-logo" src="{{ \App\Models\SiteBrandAsset::urlFor('logo_light') }}" alt="{{ \App\Models\SiteSetting::setting('identity.name') }}" width="550" height="144">
    <section aria-labelledby="verification-title">
      <span class="receipt-status" data-status="{{ $status === 'Paid' ? 'paid' : 'recorded' }}">{{ $status }}</span>
      <h1 id="verification-title">Receipt verified</h1>
      <p>Match the order ID and amount below with your receipt.</p>
      <dl>
        <div><dt>Order ID</dt><dd class="receipt-reference">{{ $reference }}</dd></div>
        <div><dt>{{ $status === 'Refunded' ? 'Total refunded' : ($status === 'Paid' ? 'Total paid' : 'Order total') }}</dt><dd>{{ $amount }}</dd></div>
        <div><dt>Date recorded (ICT)</dt><dd>{{ $recorded }}</dd></div>
      </dl>
    </section>
    <a href="{{ route('home') }}">Visit Bambodia</a>
  </main>
</body>
</html>
