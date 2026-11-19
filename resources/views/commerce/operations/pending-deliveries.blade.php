<section class="panel" id="pending-deliveries" aria-labelledby="pending-deliveries-heading">
  <div class="panel-head">
    <div>
      <h2 id="pending-deliveries-heading">Paid orders awaiting delivery <span class="count">{{ $pendingDeliveries->total() }}</span></h2>
      @if($pendingDeliveries->total() === 0)
        <p>No paid orders are awaiting delivery.</p>
      @endif
    </div>
  </div>
  @if($pendingDeliveries->isNotEmpty())
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr><th scope="col">Order</th><th scope="col">Verified payment</th><th scope="col">Pending delivery</th><th scope="col">Action</th></tr>
        </thead>
        <tbody>
          @foreach($pendingDeliveries as $delivery)
            @php($order = $delivery['order'])
            <tr>
              <td>
                <a class="listing-title-link" href="{{ route('dashboard.orders.show', $order->number) }}">{{ $order->listing_title }}</a>
                <div class="text-muted mono">{{ $order->number }}</div>
              </td>
              <td>
                {{ $order->currency }} {{ $order->total }}
                <div class="text-muted">{{ $order->paidAttempt?->method_name }} · {{ $order->completed_at?->toDateString() }}</div>
              </td>
              <td>
                <strong>{{ $order->license_tier === null ? 'Service workspace' : ($order->entitlement ? 'License' : 'Download and license') }}</strong>
                <div class="text-muted">{{ $delivery['blocker'] ?? 'Retry to check and complete delivery.' }}</div>
              </td>
              <td>
                <form method="post" action="{{ route('dashboard.commerce-operations.retry-delivery', $order->number) }}">
                  @csrf
                  <button class="btn subtle compact commerce-delivery-retry" type="submit">Retry delivery</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$pendingDeliveries"/>
  @endif
</section>
