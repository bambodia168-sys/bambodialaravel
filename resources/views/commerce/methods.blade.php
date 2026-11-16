@extends('layouts.workspace')
@section('workspace', 'admin')
@section('title', 'Payment Methods')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><h1>Payment methods</h1><p>Manage the payment preferences buyers can select for unpaid orders.</p></div>
  <section class="panel">
    <div class="panel-head">
      <form method="post" action="{{ route('dashboard.payment-methods.store') }}" class="table-search">
        @csrf
        <input name="name" aria-label="Payment method name" placeholder="Payment method name" value="{{ old('name') }}" maxlength="80" required>
        <button class="btn primary compact">Add method</button>
      </form>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Method</th><th>Availability</th><th>Action</th></tr></thead>
        <tbody>
        @forelse($methods as $method)
          <tr><td>{{ $method->name }}</td><td><span class="role-pill">{{ $method->available ? 'Available' : 'Hidden' }}</span></td>
            <td><form method="post" action="{{ route('dashboard.payment-methods.update', $method) }}">
              @csrf @method('PATCH')
              <input type="hidden" name="available" value="{{ $method->available ? '0' : '1' }}">
              <button class="btn subtle compact">{{ $method->available ? 'Hide' : 'Make available' }}</button>
            </form></td></tr>
        @empty
          <tr><td colspan="3" class="empty-cell">No payment methods configured.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$methods"/>
  </section>
</div>
@endsection
