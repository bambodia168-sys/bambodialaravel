@extends('layouts.workspace')
@section('workspace', 'admin')
@section('title', 'Payment Methods')
@section('content')
<div class="container inner-page">
  <div class="page-intro">
    <h1>Payment methods</h1>
    @if(!config('commerce.payments_enabled'))
      <p>Payment processing is disabled. You can prepare payment methods here.</p>
    @else
      <p>Configure hosted checkout and manual transfer instructions for USD orders.</p>
    @endif
  </div>
  <section class="panel">
    <div class="panel-head">
      <form method="post" action="{{ route('dashboard.payment-methods.store') }}" class="table-search">
        @csrf
        <input name="name" aria-label="Payment method name" placeholder="Payment method name" value="{{ old('name') }}" maxlength="80" required>
        <select name="provider" aria-label="Payment provider">
          @foreach(\App\Models\CommercePaymentMethod::PROVIDERS as $key => $label)
            <option value="{{ $key }}" @selected(old('provider', 'other') === $key)>{{ $label }}</option>
          @endforeach
        </select>
        <button class="btn primary compact">Add method</button>
      </form>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Method</th><th>Mode</th><th>Availability</th><th>Settings</th></tr></thead>
        <tbody>
        @forelse($methods as $method)
          <tr>
            <td><strong>{{ $method->name }}</strong><div>{{ \App\Models\CommercePaymentMethod::PROVIDERS[$method->provider] }}</div></td>
            <td>{{ $method->automatic() ? 'Automatic' : 'Manual' }}</td>
            <td><span class="role-pill">{{ $method->available ? 'Available' : 'Hidden' }}</span></td>
            <td><details>
              <summary>Configure {{ $method->name }}</summary>
              <form method="post" action="{{ route('dashboard.payment-methods.update', $method) }}" enctype="multipart/form-data" class="form-stack">
                @csrf @method('PATCH')
                @if($method->automatic())
                  <p>{{ \App\Support\CommerceGateway::ready($method->provider, (bool) config('commerce.live_gateways')) ? 'Server credentials configured' : 'Server credentials required' }}</p>
                  <p>Webhook endpoint <code>{{ route('commerce.webhook', $method->provider) }}</code></p>
                @else
                  <label>Transfer instructions<textarea name="instructions" rows="3" maxlength="2500">{{ $method->instructions }}</textarea></label>
                  <label>Optional merchant QR image<input name="qr" type="file" accept="image/png,image/jpeg,image/webp"></label>
                  @if($method->qr_path)
                    <label><input name="remove_qr" type="checkbox" value="1"> Remove saved QR</label>
                  @endif
                @endif
                <label>Availability
                  <select name="available">
                    <option value="0" @selected(!$method->available)>Hidden</option>
                    <option value="1" @selected($method->available)>Available</option>
                  </select>
                </label>
                <button class="btn primary compact">Save settings</button>
              </form>
            </details></td>
          </tr>
        @empty
          <tr><td colspan="4" class="empty-cell">No payment methods configured.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$methods"/>
  </section>
</div>
@endsection
