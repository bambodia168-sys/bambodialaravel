@extends('layouts.workspace')
@section('workspace', $buyer ? 'account' : 'admin')
@section('title', $buyer ? 'Order Refund' : 'Order Settlement')
@section('content')
<link rel="stylesheet" href="{{ asset('css/commerce-settlements.css') }}">
@php($pageRoute = $buyer ? 'account.orders.refund' : 'dashboard.commerce-operations.show')
@php($record = $order->operations)
@php($labels = \App\Support\CommerceOperations::LABELS)
<div class="container inner-page">
  <section class="panel">
    <header class="panel-head">
      <div>
        <h1>{{ $buyer ? 'Order Refund' : 'Order Settlement' }}</h1>
        <p><a href="{{ route($buyer ? 'account.orders.show' : 'dashboard.orders.show', $order->number) }}">{{ $order->listing_title }}</a></p>
      </div>
      @if($action)
        <button type="submit" class="btn primary compact commerce-operation-toolbar" form="commerce-operation-form">{{ $labels[$action] }}</button>
      @elseif($actions)
        <form class="table-search commerce-operation-toolbar" method="get" action="{{ route($pageRoute, $order->number) }}">
          <select name="action" aria-label="Order action" required>
            <option value="">Choose action</option>
            @foreach($actions as $option)<option value="{{ $option }}">{{ $labels[$option] }}</option>@endforeach
          </select>
          <button class="btn primary compact">Continue</button>
        </form>
      @endif
    </header>

    @if(session('status'))<p class="commerce-operation-message" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())
      <div class="commerce-operation-message" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="table-scroll">
      <table class="data-table">
        <tbody>
        <tr><th scope="row">Order number</th><td>{{ $order->number }}</td></tr>
        <tr><th scope="row">Amount collected</th><td>{{ $order->currency }} {{ $order->total }}</td></tr>
        <tr><th scope="row">Refund status</th><td>{{ $record?->refund_status ? ucfirst($record->refund_status) : 'No refund recorded' }}</td></tr>
        @if($record?->refund_reason)<tr><th scope="row">Request reason</th><td>{{ $record->refund_reason }}</td></tr>@endif
        @if($record?->refund_reference)<tr><th scope="row">Refund reference</th><td>{{ $record->refund_reference }}</td></tr>@endif
        @unless($buyer)
          <tr><th scope="row">Verified payment reference</th><td>{{ $order->paidAttempt->settlement_reference }}</td></tr>
          <tr><th scope="row">Statement match</th><td>{{ $record?->statement_reference ?? 'Awaiting match' }}</td></tr>
          @if($record?->seller_payable !== null)<tr><th scope="row">Seller payable</th><td>{{ $order->currency }} {{ $order->status === 'refunded' ? '0.00' : $record->seller_payable }}</td></tr>@endif
          @if($record?->payout_reference)<tr><th scope="row">Payout reference</th><td>{{ $record->payout_reference }}</td></tr>@endif
          @if($record?->recoveryDue())<tr><th scope="row">Seller payout recovery due</th><td>{{ $order->currency }} {{ $record->seller_payable }}</td></tr>@endif
          @if($record?->recovery_reference)<tr><th scope="row">Recovery reference</th><td>{{ $record->recovery_reference }}</td></tr>@endif
        @endunless
        </tbody>
      </table>
    </div>

    @if($action)
      @php($usesEvidence = ! in_array($action, ['request_refund', 'reject_refund'], true))
      <form id="commerce-operation-form" class="commerce-operation-form" method="post"
            action="{{ route($buyer ? 'account.orders.refund.store' : 'dashboard.commerce-operations.store', $order->number) }}">
        @csrf
        <input type="hidden" name="action" value="{{ $action }}">
        @if($usesEvidence)
          <label>{{ $action === 'reconcile' ? 'External statement line reference' : 'External transfer reference' }}
            <input name="reference" value="{{ old('reference') }}" maxlength="120" required>
          </label>
          <label>Verified amount ({{ $order->currency }})
            <input name="amount" inputmode="decimal" value="{{ old('amount') }}" placeholder="0.00" maxlength="13" required>
          </label>
          @if($action === 'reconcile')
            <label>Payment reference from statement
              <input name="payment_reference" value="{{ old('payment_reference') }}" maxlength="230" required>
            </label>
            <label>Seller payable ({{ $order->currency }})
              <input name="seller_payable" inputmode="decimal" value="{{ old('seller_payable') }}" placeholder="0.00" maxlength="13" required>
            </label>
          @elseif($action === 'record_refund')
            <p>Record the full refund after it settles with the payment provider or bank.
              @if($order->license_tier)The order’s download and license are revoked when the refund is recorded.@endif
            </p>
          @endif
          <label class="commerce-operation-confirmation"><input type="checkbox" name="confirmed" value="1" required> I verified the amount and reference externally.</label>
        @endif
        <label>{{ $buyer ? 'Reason for refund' : 'Evidence or decision note' }}
          <textarea name="note" maxlength="500" minlength="5" required>{{ old('note') }}</textarea>
        </label>
      </form>
    @endif
  </section>

  @if($events?->isNotEmpty())
    <section class="panel">
      <div class="panel-head"><h2>Activity</h2></div>
      <div class="table-scroll">
        <table class="data-table">
          <thead><tr><th scope="col">Recorded</th><th scope="col">Action</th><th scope="col">Actor</th><th scope="col">Note</th></tr></thead>
          <tbody>
          @foreach($events as $event)
            <tr><td>{{ $event->created_at->format('d M Y H:i') }}</td><td>{{ $labels[$event->action] }}</td><td>{{ $event->actor?->name }}</td><td>{{ $event->note }}</td></tr>
          @endforeach
          </tbody>
        </table>
      </div>
    </section>
  @endif
</div>
@endsection
