@php
  $activityOnOrderSheet = $section === 'dashboard.orders';
  $canReview = $activityOnOrderSheet && auth()->user()->role === 'super_admin';
  $canReviewTransfer = $canReview && !in_array((int) auth()->id(), [(int) $order->buyer_id, (int) $order->seller_id], true);
  $showReviewColumn = $canReview && $payments->contains(fn ($payment) => $payment->submitted_reference || $payment->proof_path
      || $payment->review_note || ($canReviewTransfer && $payment->status === 'manual_review'));
@endphp
@if($payments->isNotEmpty())
  <div class="panel-head"><h2>Payment activity</h2></div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th scope="col">Method</th><th scope="col">Status</th><th scope="col">Submitted{{ $activityOnOrderSheet ? ' (ICT)' : '' }}</th>@if($showReviewColumn)<th scope="col" class="order-activity-review">Review</th>@endif</tr></thead>
      <tbody>
      @foreach($payments as $payment)
        <tr>
          <td>{{ $payment->method_name }}</td>
          <td>{{ \App\Models\CommercePayment::LABELS[$payment->status] }}</td>
          <td>{{ ($activityOnOrderSheet ? $payment->created_at->setTimezone('Asia/Phnom_Penh') : $payment->created_at)->format('d M Y H:i') }}</td>
          @if($showReviewColumn)
            <td class="order-activity-review">
              @if($payment->submitted_reference)<p class="mono">{{ $payment->submitted_reference }}</p>@endif
              @if($payment->proof_path)<a class="btn subtle compact" href="{{ route('dashboard.orders.payment.proof', [$order->number, $payment->number]) }}">Download payment receipt</a>@endif
              @if($payment->review_note)<p>{{ $payment->review_note }}</p>@endif
              @if($payment->status === 'manual_review' && $canReviewTransfer)
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
