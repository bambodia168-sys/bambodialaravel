@php
  $buyerView = $section === 'account.orders';
  $canReview = $section === 'dashboard.orders' && auth()->user()->role === 'super_admin';
  $activePayment = $payments->first(fn ($payment) => in_array($payment->status, \App\Models\CommercePayment::ACTIVE, true));
  $rejectedReceipt = $payments->firstWhere('status', 'rejected');
@endphp
@if($buyerView && $order->status === 'awaiting_payment' && $order->currency === 'USD' && ($collectingPayments || $activePayment))
  <div class="panel-head"><h2>Pay for this order</h2></div>
  @if(!$activePayment && $order->license_tier !== null && !$order->fulfillment_version_id)
    <div class="panel-head"><p>Return to the product page to place a new order with an approved download.</p></div>
  @elseif(!$activePayment)
    @if($rejectedReceipt)
      <div class="panel-head"><p>Your payment receipt was rejected. Check your transfer details and submit a corrected receipt.</p></div>
    @endif
    @php
      $availableMethods = $methods->filter(fn ($method) => $method->availableForCheckout());
      $preferredMethod = old('method') !== null
          ? $availableMethods->first(fn ($method) => (string) old('method') === (string) $method->id)
          : $availableMethods->firstWhere('name', $order->payment_method);
    @endphp
    @if($availableMethods->isNotEmpty())
      <div class="order-review-methods">
        @foreach($availableMethods as $method)
          @php($selected = $preferredMethod?->id === $method->id || $availableMethods->count() === 1)
          <details class="order-review-method" name="order-payment-choice" @if($selected) open @endif>
            <summary>
              <x-payment-method-icon :provider="$method->provider"/>
              <span class="order-review-method-name">{{ $method->name }}</span>
              <span class="order-review-method-selected"><x-icon name="check" size="16"/> Selected</span>
              <x-icon name="chevron-down" size="16" class="order-review-method-chevron"/>
            </summary>
            <div class="order-review-method-body">
              @if($method->usesGateway())
                <form method="post" action="{{ route('account.orders.payment.start', $order->number) }}">
                  @csrf
                  <input type="hidden" name="method" value="{{ $method->id }}">
                  <button class="btn primary">{{ $method->dynamicQr() ? 'Generate QR for' : 'Pay with' }} {{ $method->name }}</button>
                </form>
              @else
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
          </details>
        @endforeach
      </div>
    @else
      <div class="panel-head"><p>There are currently no payment methods available for this order.</p></div>
    @endif
  @elseif($activePayment->status === 'manual_review')
    <div class="panel-head"><p>Your payment receipt is awaiting review. Purchase access opens after approval.</p></div>
  @elseif($activePayment->dynamicQr() && $activePayment->remote_id)
    <div class="panel-head">
      <div>
        <h3>{{ $activePayment->method_name }} payment · USD {{ $order->total }}</h3>
        @if($collectingPayments && $activePayment->qrVisible())
          <x-khqr-payment-card :order="$order" :payment="$activePayment"/>
          <p data-payment-qr-hint>Scan with Bakong or a KHQR-compatible banking app. The code expires six minutes after creation.</p>
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
  @else
    <div class="panel-head">
      @if($collectingPayments)
        <form method="post" action="{{ route('account.orders.payment.start', $order->number) }}">
          @csrf
          <input type="hidden" name="method" value="{{ $activePayment->commerce_payment_method_id }}">
          <button class="btn primary">Continue {{ $activePayment->method_name }} checkout</button>
        </form>
      @endif
      @if($activePayment->remote_id)
        <form method="post" action="{{ route('account.orders.payment.verify', [$order->number, $activePayment->number]) }}">
          @csrf
          <button class="btn subtle">Verify payment status</button>
        </form>
      @endif
    </div>
  @endif
@endif
@if($payments->isNotEmpty())
  <div class="panel-head"><h2>Payment activity</h2></div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Method</th><th>Status</th><th>Submitted</th>@if($canReview)<th>Review</th>@endif</tr></thead>
      <tbody>
      @foreach($payments as $payment)
        <tr>
          <td>{{ $payment->method_name }}</td>
          <td>{{ \App\Models\CommercePayment::LABELS[$payment->status] }}</td>
          <td>{{ $payment->created_at->format('d M Y H:i') }}</td>
          @if($canReview)
            <td>
              @if($payment->submitted_reference)<p class="mono">{{ $payment->submitted_reference }}</p>@endif
              @if($payment->proof_path)<a class="btn subtle compact" href="{{ route('dashboard.orders.payment.proof', [$order->number, $payment->number]) }}">Download payment receipt</a>@endif
              @if($payment->review_note)<p>{{ $payment->review_note }}</p>@endif
              @if($payment->status === 'manual_review' && !in_array((int) auth()->id(), [(int) $order->buyer_id, (int) $order->seller_id], true))
                <details>
                  <summary>Review transfer</summary>
                  <p>Verify the amount, currency, recipient, and reference against your bank or wallet records before approving.</p>
                  <form method="post" action="{{ route('dashboard.orders.payment.review', [$order->number, $payment->number]) }}" class="form-stack">
                    @csrf @method('PATCH')
                    <label>Verified reference<input name="verified_reference" maxlength="100"></label>
                    <label>Verified USD amount<input name="verified_amount" inputmode="decimal" placeholder="0.00"></label>
                    <label>Review note<input name="note" maxlength="300" required></label>
                    <button class="btn primary" name="decision" value="approve">Approve transfer</button>
                    <button class="btn subtle" name="decision" value="reject">Reject transfer</button>
                  </form>
                </details>
              @endif
            </td>
          @endif
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
@endif
