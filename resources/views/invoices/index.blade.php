@extends('layouts.workspace')
@section('workspace', $studio ? 'creator' : 'account')
@section('title', 'Invoices')
@push('head')
<link rel="stylesheet" href="{{ asset('css/native-invoices.css') }}?v=2">
@endpush
@section('content')
<div class="container inner-page invoice-workspace">
  <div class="page-intro">
    <h1>{{ $studio ? 'Sales invoices' : 'My invoices' }}</h1>
    <p>{{ $studio ? 'Private order-linked drafts. Invoicing is separate from payment and settlement.' : 'Only issued invoices addressed to your account appear here.' }}</p>
  </div>
  @if($studio && \App\Policies\MarketplaceInvoicePolicy::writesEnabled())
    <section class="panel invoice-panel">
      <h2>New invoice draft</h2>
      <p class="hint">Use the ten-digit reference of an existing eligible unpaid sales order. Review the locked original amount before confirming.</p>
      <form method="get" action="{{ route('studio.invoices.preview') }}" class="invoice-form">
        <label>Order reference<input name="order_reference" required maxlength="10" pattern="[1-9][0-9]{9}" inputmode="numeric" placeholder="10-digit order reference" value="{{ old('order_reference') }}"></label>
        <button class="btn primary" type="submit">Review source order</button>
      </form>
      <a href="{{ route('studio.orders.index') }}">View sales orders</a>
    </section>
  @endif
  <section class="panel">
    <div class="panel-head">
      <form method="get" class="table-search">
        <label for="invoice-status" class="hint">Document status</label>
        <select id="invoice-status" name="status">
          <option value="">All visible statuses</option>
          @foreach($statuses as $value)
            <option value="{{ $value }}" @selected($value===$status)>{{ ucfirst($value) }}</option>
          @endforeach
        </select>
        <button class="btn subtle compact">Filter</button>
      </form>
      <span class="count">{{ $invoices->total() }} invoices</span>
    </div>
    @if($invoices->isEmpty())
      <p class="invoice-empty" role="status">No invoices found.</p>
    @else
    <div class="table-scroll" role="region" aria-label="Invoice documents" tabindex="0">
      <table class="data-table">
        <thead><tr><th>Document</th><th>Order</th><th>Status</th><th>Amount</th><th>Due date</th><th>Action</th></tr></thead>
        <tbody>
          @foreach($invoices as $invoice)
            <tr>
              <td><span class="invoice-document-number">{{ $invoice->invoice_number ?? 'Private draft' }}</span><br><small>{{ $invoice->created_at->format('d M Y') }}</small></td>
              <td>{{ $invoice->order->public_reference }}</td>
              <td><span class="role-pill">{{ ucfirst($invoice->status) }}</span></td>
              <td>{{ $invoice->currency }} {{ $invoice->total }}</td>
              <td>{{ $invoice->due_on?->format('d M Y') ?? '—' }}</td>
              <td><a class="btn subtle compact" href="{{ route($studio?'studio.invoices.show':'account.invoices.show',$invoice->public_id) }}">View</a></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif
    {{ $invoices->links() }}
  </section>
</div>
@endsection
