@php
  $buyerView = $section === 'account.orders';
  $canReview = $section === 'dashboard.orders' && auth()->user()->role === 'super_admin';
  $activePayment = $payments->first(fn ($payment) => in_array($payment->status, \App\Models\CommercePayment::ACTIVE, true));
  $live = (bool) config('commerce.live_gateways');
@endphp
@if($buyerView && $order->status === 'awaiting_payment' && $order->currency === 'USD')
  <div class="panel-head"><h2>Pay for this order</h2></div>
  @if(!$activePayment)
    @forelse($methods->filter(fn ($method) => $method->automatic()
      ? ($live || !app()->environment('production')) && \App\Support\CommerceGateway::ready($method->provider, $live)
      : filled($method->instructions)) as $method)
      <div class="panel-head">
        @if($method->automatic())
          <form method="post" action="{{ route('account.orders.payment.start', $order->number) }}">
            @csrf
            <input type="hidden" name="method" value="{{ $method->id }}">
            <button class="btn primary">Pay with {{ $method->name }}</button>
          </form>
        @else
          <details>
            <summary>{{ $method->name }} transfer</summary>
            <p>{!! nl2br(e($method->instructions)) !!}</p>
            <p>Transfer exactly {{ $order->currency }} {{ $order->total }}. Then submit the transaction reference and proof.</p>
            @if($method->qr_path)
              <img src="{{ route('account.orders.payment.qr', [$order->number, $method]) }}" alt="{{ $method->name }} merchant QR" width="200" height="200">
            @endif
            <form method="post" action="{{ route('account.orders.payment.submit', $order->number) }}" enctype="multipart/form-data" class="form-stack">
              @csrf
              <input type="hidden" name="method" value="{{ $method->id }}">
              <label>Transfer reference<input name="reference" required maxlength="100"></label>
              <label>Payment proof<input name="proof" type="file" accept="image/png,image/jpeg,image/webp" required></label>
              <button class="btn primary">Submit proof</button>
            </form>
          </details>
        @endif
      </div>
    @empty
      <div class="panel-head"><p>There are currently no payment methods available for this order.</p></div>
    @endforelse
  @elseif($activePayment->status === 'manual_review')
    <div class="panel-head"><p>Your transfer is awaiting verification.</p></div>
  @else
    <div class="panel-head">
      <form method="post" action="{{ route('account.orders.payment.start', $order->number) }}">
        @csrf
        <input type="hidden" name="method" value="{{ $activePayment->commerce_payment_method_id }}">
        <button class="btn primary">Continue {{ $activePayment->method_name }} checkout</button>
      </form>
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
              @if($payment->proof_path)<a class="btn subtle compact" href="{{ route('dashboard.orders.payment.proof', [$order->number, $payment->number]) }}">Download proof</a>@endif
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
