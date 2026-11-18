@extends('layouts.workspace')
@section('workspace', 'account')
@section('title', 'Review Order')
@section('content')
<div class="container inner-page">
  <div class="page-intro">
    <h1>Review order</h1>
    <p><a href="{{ $record->publicUrl() }}">{{ $record->title }}</a> · {{ $record->creator->name }}</p>
  </div>
  @foreach($quotes as $tier => $quote)
    <section class="panel">
      <div class="panel-head">
        <h2>{{ $quote['license'] ?? 'Order summary' }}</h2>
        <strong class="mono">{{ $quote['currency'] }} {{ $quote['total'] }}</strong>
      </div>
      <div class="panel-head">
        <form method="post" action="{{ route('account.orders.store', $record->public_id) }}" class="form-stack">
          @csrf
          <input type="hidden" name="request_key" value="{{ $requestKey }}">
          <input type="hidden" name="tier" value="{{ $tier }}">
          <input type="hidden" name="quote" value="{{ $quote['signature'] }}">
          @if($quote['total'] === '0.00')
            <p>A zero-total order is recorded immediately. Access and delivery are arranged separately.</p>
          @else
            @if(!$collectingPayments && $methods->isNotEmpty())
              <label>Preferred payment method
                <select name="payment_method">
                  <option value="">Decide later</option>
                  @foreach($methods as $method)
                    <option value="{{ $method->id }}">{{ $method->name }}</option>
                  @endforeach
                </select>
              </label>
            @endif
            @if($collectingPayments)
              <p>Choose an available payment method after placing your order.</p>
            @else
              <p>This records an unpaid order. You won’t be charged.</p>
            @endif
          @endif
          <button class="btn primary">Place order</button>
        </form>
      </div>
    </section>
  @endforeach
</div>
@endsection
