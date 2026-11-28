@extends('layouts.workspace')
@section('workspace', $studio ? 'creator' : 'account')
@section('title', 'Invoice details')
@push('head')
<link rel="stylesheet" href="{{ asset('css/native-invoices.css') }}?v=1">
@endpush
@section('content')
<div class="container inner-page invoice-workspace">
  <div class="page-intro"><h1>{{ $invoice['number'] ?? 'Invoice draft' }}</h1>
    <p>Document status: {{ ucfirst($invoice['status']) }} · Source payment state: {{ str_replace('_',' ',ucfirst($invoice['payment_state'])) }}</p>
  </div>
  <section class="panel invoice-panel">
    @if(in_array($invoice['status'],['draft','discarded'],true))
      <p class="invoice-notice">PRIVATE — NOT ISSUED — NOT A TAX INVOICE</p>
    @endif
    <p>Order <a href="{{ route($studio?'studio.orders.show':'account.orders.show',$invoice['reference']) }}">{{ $invoice['reference'] }}</a></p>
    <div class="table-scroll"><table class="data-table"><thead><tr><th>Description</th><th>Quantity</th><th>Amount</th></tr></thead><tbody>
      @foreach($invoice['items'] as $item)
        <tr><td>{{ $item['description'] }}</td><td>{{ $item['quantity'] }}</td><td>{{ $invoice['currency'] }} {{ $item['amount'] }}</td></tr>
      @endforeach
    </tbody></table></div>
    <p class="invoice-total">Total: {{ $invoice['currency'] }} {{ $invoice['total'] }}</p>
    <p>Due date: {{ $invoice['due_on'] ?? 'Not set' }} @if($invoice['overdue']) · Overdue @endif</p>
    @if($invoice['payment_state']==='review_required')<p class="invoice-notice">Source needs review. No collectible amount is asserted.</p>@endif
    <p class="hint">Payment and receipt information comes from the linked native order. Issuance and invoice email are not available in this phase.</p>
  </section>
  @if($errors->any())<div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
  @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
  @if($studio && $invoice['can_update'])
    <section class="panel invoice-panel"><h2>Update due date</h2>
      <form method="post" action="{{ route('studio.invoices.update',$invoice['id']) }}" class="invoice-form">
        @csrf @method('PATCH')
        <input type="hidden" name="expected_version" value="{{ $invoice['version'] }}">
        <input type="hidden" name="request_id" value="{{ $updateRequest }}">
        <label>Due date<input type="date" name="due_on" value="{{ old('due_on',$invoice['due_on']) }}"></label>
        <label class="invoice-confirm"><input type="checkbox" name="confirm" value="1" required> Confirm this due date change.</label>
        <button class="btn primary" type="submit">Save due date</button>
      </form>
    </section>
  @endif
  @if($studio && $invoice['can_discard'])
    <section class="panel invoice-panel"><h2>Discard private draft</h2>
      <p class="hint">This is terminal. The order is unaffected, and the document history is preserved. A replacement draft is not supported in this phase.</p>
      <form method="post" action="{{ route('studio.invoices.discard',$invoice['id']) }}" class="invoice-form">
        @csrf
        <input type="hidden" name="expected_version" value="{{ $invoice['version'] }}">
        <input type="hidden" name="request_id" value="{{ $discardRequest }}">
        <label class="invoice-confirm"><input type="checkbox" name="confirm" value="1" required> Confirm permanent discard of this unissued draft.</label>
        <button class="btn subtle" type="submit">Discard draft</button>
      </form>
    </section>
  @endif
  <a class="btn subtle" href="{{ route($studio?'studio.invoices.index':'account.invoices.index') }}">Back to invoices</a>
</div>
@endsection
