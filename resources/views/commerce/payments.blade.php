@php
  $buyerView = $section === 'account.orders';
  $canReview = $section === 'dashboard.orders' && auth()->user()->role === 'super_admin';
  $activePayment = $payments->first(fn ($payment) => in_array($payment->status, \App\Models\CommercePayment::ACTIVE, true));
  $rejectedReceipt = $payments->firstWhere('status', 'rejected');
@endphp
@if($buyerView && $order->status === 'awaiting_payment' && $order->currency === 'USD' && ($collectingPayments || $activePayment))
  <div class="panel-head"><h2>Pay for this order</h2></div>
  @if(!$activePayment)
    @if($rejectedReceipt)
      <div class="panel-head"><p>Your payment receipt was rejected. Check your transfer details and submit a corrected receipt.</p></div>
    @endif
    @forelse($methods->filter(fn ($method) => $method->availableForCheckout()) as $method)
      <div class="panel-head">
        @if($method->usesGateway())
          <form method="post" action="{{ route('account.orders.payment.start', $order->number) }}">
            @csrf
            <input type="hidden" name="method" value="{{ $method->id }}">
            <button class="btn primary">{{ $method->dynamicQr() ? 'Generate QR for' : 'Pay with' }} {{ $method->name }}</button>
          </form>
        @elseif($order->license_tier !== null && !$order->fulfillment_version_id)
          <p>{{ $method->name }} is unavailable for this order. Return to the product page to place a new order with an approved download.</p>
        @else
          <details @if((string) old('method') === (string) $method->id) open @endif>
            <summary>{{ $method->name }} transfer</summary>
            <p>{!! nl2br(e($method->instructions)) !!}</p>
            <p>Transfer exactly {{ $order->currency }} {{ $order->total }}. Then upload your receipt and enter the transfer reference for review.</p>
            @if($method->qr_path)
              <img src="{{ route('account.orders.payment.qr', [$order->number, $method]) }}" alt="{{ $method->name }} merchant QR" width="200" height="200">
            @endif
            <form method="post" action="{{ route('account.orders.payment.submit', $order->number) }}" enctype="multipart/form-data" class="form-stack">
              @csrf
              <input type="hidden" name="method" value="{{ $method->id }}">
              <label>Transfer reference<input name="reference" value="{{ (string) old('method') === (string) $method->id ? old('reference') : '' }}" required maxlength="100"></label>
              <label>Payment receipt<input name="proof" type="file" accept="image/png,image/jpeg,image/webp,application/pdf" required></label>
              <p class="hint">PDF, PNG, JPEG or WebP, up to 5 MB.</p>
              <button class="btn primary">Submit receipt</button>
            </form>
          </details>
        @endif
      </div>
    @empty
      <div class="panel-head"><p>There are currently no payment methods available for this order.</p></div>
    @endforelse
  @elseif($activePayment->status === 'manual_review')
    <div class="panel-head"><p>Your payment receipt is awaiting review. Purchase access opens after approval.</p></div>
  @elseif($activePayment->dynamicQr() && $activePayment->remote_id)
    <div class="panel-head">
      <div>
        <h3>{{ $activePayment->method_name }} payment · USD {{ $order->total }}</h3>
        @if($collectingPayments && $activePayment->qrVisible())
          <p data-payment-qr-hint>Scan this code in your banking app. It expires in six minutes from creation.</p>
          <img data-order-payment-qr src="{{ route('account.orders.payment.order-qr', [$order->number, $activePayment->number]) }}"
               alt="{{ $activePayment->method_name }} payment QR for this order" width="288" height="288">
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
    <script defer src="{{ asset('js/commerce-payment-poll.js') }}"></script>
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
