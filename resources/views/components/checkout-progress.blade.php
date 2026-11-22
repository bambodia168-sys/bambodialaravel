@props(['step' => 'cart', 'order' => null, 'paymentPending' => false])

@php
  $steps = ['cart' => 'Cart', 'checkout' => 'Checkout', 'payment' => 'Payment', 'review' => 'Review'];
  $orderCompleted = $order && in_array($order->status, ['completed', 'refunded'], true);
  $paymentSkipped = $orderCompleted && $order->total === '0.00';
  if ($order) {
    $step = $order->status === 'awaiting_payment' ? 'payment' : 'review';
  }
  $activePosition = array_search($step, array_keys($steps), true);
@endphp

<link rel="stylesheet" href="{{ asset('css/checkout-progress.css') }}?v=2">
<nav class="checkout-progress" aria-label="Checkout progress">
  <ol class="checkout-progress-track">
    @foreach($steps as $key => $label)
      @php
        $state = match (true) {
            $key === $step => 'current',
            $key === 'payment' && $paymentSkipped => 'skipped',
            $key === 'payment' && $order && !$orderCompleted => 'upcoming',
            $loop->index < $activePosition => 'completed',
            default => 'upcoming',
        };
        $editCart = $key === 'cart' && $step === 'checkout';
      @endphp
      <li data-step="{{ $key }}" data-state="{{ $state }}" @if($state === 'current') aria-current="step" @endif>
        @if($editCart)<a class="checkout-progress-step" href="{{ route('cart') }}">
        @else<span class="checkout-progress-step">
        @endif
          <span class="checkout-progress-marker" aria-hidden="true">
            @if($state === 'completed')
              <x-icon name="check" size="22"/>
            @elseif($state === 'skipped')
              &ndash;
            @else
              {{ $loop->iteration }}
            @endif
          </span>
          <span>{{ $label }}</span>
          @if($state === 'completed' || $state === 'skipped')
            <span class="checkout-progress-sr">{{ $state === 'completed' ? 'completed' : 'not required' }}</span>
          @endif
        @if($editCart)</a>@else</span>@endif
      </li>
    @endforeach
  </ol>
  @if($step === 'payment' && $paymentPending)
    <p class="checkout-progress-status">Payment pending</p>
  @elseif($order?->status === 'cancelled')
    <p class="checkout-progress-status">Order cancelled</p>
  @endif
</nav>
