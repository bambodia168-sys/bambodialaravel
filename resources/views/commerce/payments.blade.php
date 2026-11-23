@php
  $buyerView = $section === 'account.orders';
  $activePayment = $payments->first(fn ($payment) => in_array($payment->status, \App\Models\CommercePayment::ACTIVE, true));
  $rejectedReceipt = $payments->firstWhere('status', 'rejected');
@endphp
@if($buyerView && $order->status === 'awaiting_payment' && $order->currency === 'USD' && ($collectingPayments || $activePayment))
  @php
    $availableMethods = $collectingPayments ? $methods->filter(fn ($method) => $method->availableForCheckout()) : collect();
    $selectedMethod = $availableMethods->first(fn ($method) => (string) $method->id === (string) old('method'))
        ?? $availableMethods->firstWhere('name', $order->payment_method)
        ?? ($availableMethods->count() === 1 ? $availableMethods->first() : null);
    $otherMethods = $availableMethods->reject(fn ($method) => $method->id === ($activePayment?->commerce_payment_method_id ?? $selectedMethod?->id));
  @endphp
  <div class="panel-head"><h2>Pay for this order</h2></div>
  @if(!$activePayment && $order->license_tier !== null && !$order->fulfillment_version_id)
    <div class="panel-head"><p>Return to the product page to place a new order with an approved download.</p></div>
  @else
    <div class="order-review-methods">
      @if($activePayment || $selectedMethod)
        <section class="order-review-method" aria-labelledby="order-payment-method-name">
          <div class="order-review-method-heading">
            <x-payment-method-icon :provider="$activePayment?->provider ?? $selectedMethod->provider"/>
            <h3 id="order-payment-method-name">{{ $activePayment?->method_name ?? $selectedMethod->name }}</h3>
            <span class="order-review-method-selected"><x-icon name="check" size="16"/> Selected</span>
          </div>
          <div class="order-review-method-body">
            @if($activePayment?->status === 'manual_review')
              <p>Your payment receipt is awaiting review. Purchase access opens after approval.</p>
            @elseif($activePayment?->dynamicQr() && $activePayment->remote_id)
              <div class="order-review-scan">
                @if($collectingPayments && $activePayment->qrVisible())
                  <x-khqr-payment-card :order="$order" :payment="$activePayment"/>
                @endif
                <div class="order-review-scan-status">
                  @if($collectingPayments && $activePayment->qrVisible())
                    <div data-payment-qr-hint>
                      <h4>Scan to pay</h4>
                      <p>Open Bakong or a KHQR-compatible banking app. The QR is valid for six minutes.</p>
                    </div>
                  @endif
                  <p role="status" data-order-payment-status>
                    @if(!$collectingPayments)
                      New payments are paused. Checking for a final payment result.
                    @elseif($activePayment->qrVisible())
                      Waiting for your bank to confirm payment.
                    @else
                      This QR has expired. Checking for a final payment result.
                    @endif
                  </p>
                  <form method="post" data-order-payment-poll action="{{ route('account.orders.payment.verify', [$order->number, $activePayment->number]) }}">
                    @csrf
                    <button class="btn subtle">Check payment status now</button>
                  </form>
                </div>
              </div>
              <script defer src="{{ asset('js/commerce-payment-poll.js') }}?v=2"></script>
            @elseif($activePayment)
              @if($collectingPayments)
                <form method="post" action="{{ route('account.orders.payment.start', $order->number) }}">
                  @csrf
                  <input type="hidden" name="method" value="{{ $activePayment->commerce_payment_method_id }}">
                  <button class="btn primary">{{ $activePayment->dynamicQr() ? 'Retry QR generation' : 'Continue '.$activePayment->method_name.' checkout' }}</button>
                </form>
              @endif
              @if($activePayment->provider === 'baray')
                <p>Baray will confirm payment after the bank verifies it. Returning from checkout does not mark an order paid.</p>
              @endif
              @if($activePayment->remote_id && $activePayment->provider !== 'baray')
                <form method="post" action="{{ route('account.orders.payment.verify', [$order->number, $activePayment->number]) }}">
                  @csrf
                  <button class="btn subtle">Verify payment status</button>
                </form>
              @endif
            @elseif($selectedMethod->usesGateway())
              @if($selectedMethod->dynamicQr() && $payments->firstWhere('status', 'expired'))
                <p>Your previous payment attempt has closed. Generate a new QR to pay with {{ $selectedMethod->name }}.</p>
              @endif
              <form method="post" action="{{ route('account.orders.payment.start', $order->number) }}">
                @csrf
                <input type="hidden" name="method" value="{{ $selectedMethod->id }}">
                <button class="btn primary">{{ $selectedMethod->dynamicQr() ? 'Generate QR for' : 'Pay with' }} {{ $selectedMethod->name }}</button>
              </form>
            @else
              @if($rejectedReceipt)
                <p>Your payment receipt was rejected. Check your transfer details and submit a corrected receipt.</p>
              @endif
              @php($method = $selectedMethod)
              <div class="order-review-transfer">
                <div>
                  <p class="order-review-transfer-total">Transfer exactly <strong>{{ $order->currency }} {{ $order->total }}</strong>.</p>
                  @if($method->instructions)
                    <details class="order-review-instructions" @unless($method->qr_path) open @endunless>
                      <summary>Transfer instructions</summary>
                      <p>{{ $method->instructions }}</p>
                    </details>
                  @endif
                </div>
                @if($method->qr_path)
                  <figure class="order-review-qr">
                    <img src="{{ route('account.orders.payment.qr', [$order->number, $method]) }}"
                         alt="{{ $method->name }} merchant QR" width="200" loading="lazy">
                  </figure>
                @endif
              </div>
              <form method="post" action="{{ route('account.orders.payment.submit', $order->number) }}"
                    enctype="multipart/form-data" class="order-receipt-upload">
                @csrf
                <input type="hidden" name="method" value="{{ $method->id }}">
                <h3>Upload your transfer receipt</h3>
                <label class="order-receipt-reference">
                  <span>Transfer reference</span>
                  <input name="reference" value="{{ (string) old('method') === (string) $method->id ? old('reference') : '' }}"
                         placeholder="Reference from your payment receipt" required maxlength="100">
                </label>
                <label class="order-receipt-file">
                  <span class="order-receipt-file-title"><x-icon name="upload" size="20"/> Payment receipt</span>
                  <span class="order-receipt-hint" id="receipt-format-{{ $method->id }}">PDF, PNG, JPEG or WebP, up to 5 MB.</span>
                  <input name="proof" type="file" accept="image/png,image/jpeg,image/webp,application/pdf"
                         aria-label="Payment receipt" aria-describedby="receipt-format-{{ $method->id }}" required>
                </label>
                <button class="btn primary">Submit receipt</button>
              </form>
            @endif
          </div>
        </section>
      @endif
      @if($otherMethods->isNotEmpty())
        <details class="order-review-method-picker" @if((!$selectedMethod && !$activePayment) || $errors->has('payment_method')) open @endif>
          <summary>
            {{ $selectedMethod || $activePayment ? 'Change payment method' : 'Choose a payment method' }}
            <x-icon name="chevron-down" size="16" class="order-review-picker-chevron"/>
          </summary>
          @if($activePayment)
            <p>{{ $activePayment->dynamicQr() && $activePayment->remote_id
                ? 'You can choose another method after this QR expires and Bambodia confirms no payment was made.'
                : 'You can choose another method once the current payment attempt closes.' }}</p>
          @else
            <form method="post" action="{{ route('account.orders.payment-method', $order->number) }}">
              @csrf @method('PATCH')
              <fieldset class="checkout-payment-methods" aria-label="Available payment methods">
                @foreach($otherMethods as $option)
                  <label class="checkout-payment-option">
                    <x-payment-method-icon :provider="$option->provider"/>
                    <span>{{ $option->name }}</span>
                    <input type="radio" name="payment_method" value="{{ $option->id }}" required>
                  </label>
                @endforeach
              </fieldset>
              <button class="btn primary">Use selected method</button>
            </form>
          @endif
        </details>
      @elseif(!$activePayment && !$selectedMethod)
        <p>There are currently no payment methods available for this order.</p>
      @endif
    </div>
  @endif
@endif
@include('commerce.payment-activity')
