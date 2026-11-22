@props(['order', 'payment'])
@inject('qrGateway', 'App\Support\CommerceQrGateway')
@php($recipient = $qrGateway->recipientName($payment->qr_payload))

<figure class="khqr-payment-card" data-order-payment-code>
  <figcaption>
    <div class="khqr-payment-card__header">
      <img src="{{ asset('images/payments/khqr-white.svg') }}" alt="KHQR" width="166" height="40">
    </div>
    <div class="khqr-payment-card__details">
      @if(filled($recipient))
        <span class="khqr-payment-card__recipient">{{ $recipient }}</span>
      @endif
      <span class="khqr-payment-card__total">
        <strong>{{ number_format((float) $order->total, 2) }}</strong>
        <span>{{ $order->currency }}</span>
      </span>
    </div>
  </figcaption>
  <div class="khqr-payment-card__code">
    <img data-order-payment-qr src="{{ route('account.orders.payment.order-qr', [$order->number, $payment->number]) }}"
         alt="{{ $payment->method_name }} payment QR for this order" width="288" height="288">
    <span class="khqr-payment-card__currency-mark" aria-hidden="true">$</span>
  </div>
</figure>
