@props(['order', 'paymentOpen' => false, 'hasAttempts' => false])

@php
  [$state, $label] = match (true) {
      $order->status === 'refunded' => ['refunded', 'Refunded'],
      $order->status === 'cancelled' => ['cancelled', 'Cancelled'],
      $order->status === 'completed' && $order->total === '0.00' => ['free', 'No payment required'],
      $order->status === 'completed' => ['paid', 'Paid'],
      $paymentOpen => ['pending', 'Pending'],
      $hasAttempts => ['unpaid', 'Unpaid'],
      default => ['awaiting', 'Awaiting Payment'],
  };
@endphp

<span class="order-payment-stamp" data-payment-stamp="{{ $state }}">{{ $label }}</span>
