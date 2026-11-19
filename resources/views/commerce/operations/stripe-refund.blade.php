<section class="panel">
  <details class="panel-head">
    <summary>Record a Stripe refund</summary>
    <form method="post" action="{{ route('dashboard.orders.stripe-refund', $order->number) }}" class="form-stack">
      @csrf
      <p>Enter the reference of an existing full Stripe refund. Bambodia verifies it against this order.</p>
      <label>Stripe refund ID<input name="reference" value="{{ old('reference') }}" placeholder="re_…" maxlength="113" required></label>
      <label>Download and license access
        <select name="access" required>
          <option value="">Choose an access decision</option>
          <option value="retain" @selected(old('access') === 'retain')>Retain existing access</option>
          <option value="revoke" @selected(old('access') === 'revoke')>Revoke existing access</option>
        </select>
      </label>
      <label>Reason for the access decision<textarea name="note" maxlength="500" required>{{ old('note') }}</textarea></label>
      <a href="{{ url('/policies/78556802-refund-and-cancellation-policy') }}">Refund and Cancellation Policy</a>
      <button class="btn primary">Verify and record refund</button>
    </form>
  </details>
</section>
