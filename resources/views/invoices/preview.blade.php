@extends('layouts.workspace')
@section('workspace','creator')
@section('title','Review invoice source')
@push('head')
<link rel="stylesheet" href="{{ asset('css/native-invoices.css') }}?v=2">
@endpush
@section('content')
<div class="container inner-page invoice-workspace">
  <div class="page-intro"><h1>Review invoice source</h1><p>Private draft only — not issued and not a tax invoice.</p></div>
  <section class="panel invoice-panel">
    <h2>Order {{ $source['reference'] }}</h2>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Description</th><th>Quantity</th><th>Amount</th></tr></thead>
      <tbody><tr><td>{{ $source['description'] }}</td><td>1</td><td>{{ $source['currency'] }} {{ $source['total'] }}</td></tr></tbody>
    </table></div>
    <p class="invoice-total">Total: {{ $source['currency'] }} {{ $source['total'] }}</p>
    <p class="hint">The price and parties come from the original order. No payment, invoice issuance, or notification will occur.</p>
    @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form method="post" action="{{ route('studio.invoices.store') }}" class="invoice-form">
      @csrf
      <input type="hidden" name="order_reference" value="{{ $source['reference'] }}">
      <input type="hidden" name="expected_version" value="{{ $source['version'] }}">
      <input type="hidden" name="request_id" value="{{ $requestId }}">
      <label>Due date (optional)<input type="date" name="due_on" value="{{ old('due_on') }}"></label>
      <label class="invoice-confirm"><input type="checkbox" name="confirm" value="1" required> Confirm creation of this private draft.</label>
      <div class="invoice-actions"><button class="btn primary" type="submit">Create private draft</button><a class="btn subtle" href="{{ route('studio.invoices.index') }}">Cancel</a></div>
    </form>
  </section>
</div>
@endsection
