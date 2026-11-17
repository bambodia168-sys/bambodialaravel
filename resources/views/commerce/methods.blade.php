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
            <td>{{ $method->dynamicQr() ? 'Dynamic QR' : ($method->automatic() ? 'Automatic' : 'Manual') }}</td>
            <td><span class="role-pill">{{ $method->available ? 'Available' : 'Hidden' }}</span></td>
            <td>
              <button class="btn subtle compact" type="button" data-modal-open="payment-method-{{ $method->id }}"
                      aria-haspopup="dialog" aria-controls="payment-method-{{ $method->id }}"
                      aria-label="Configure {{ $method->name }}">Configure</button>
            </td>
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
<style>
  dialog.payment-method-dialog {
    position: fixed;
    inset: 0;
    margin: auto;
    width: min(36rem, calc(100% - 2rem));
    max-height: calc(100dvh - 2rem);
    padding: 0;
    overflow: auto;
    overscroll-behavior: contain;
  }
  .payment-method-dialog__header, .payment-method-dialog__actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .75rem;
    padding: 1rem clamp(1rem, 4vw, 1.5rem);
  }
  .payment-method-dialog__header { border-bottom: 1px solid var(--line); }
  .payment-method-dialog__actions { border-top: 1px solid var(--line); justify-content: flex-end; flex-wrap: wrap; }
  .payment-method-dialog__body { padding: .35rem clamp(1rem, 4vw, 1.5rem) 1.25rem; }
  .payment-method-dialog__body code { overflow-wrap: anywhere; }
  .payment-method-dialog__body input[type="checkbox"] { display: inline; width: auto; }
  .payment-method-dialog :is(input,textarea,select):focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
  .payment-method-dialog__close { font-size: 1.35rem; }
</style>
@foreach($methods as $method)
  @php($retry = (string) old('configure_method') === (string) $method->id)
  <dialog class="config-dialog payment-method-dialog" id="payment-method-{{ $method->id }}"
          aria-labelledby="payment-method-heading-{{ $method->id }}" @if($retry && $errors->any()) data-payment-reopen @endif>
    <form method="post" action="{{ route('dashboard.payment-methods.update', $method) }}" enctype="multipart/form-data">
      @csrf @method('PATCH')
      <input type="hidden" name="configure_method" value="{{ $method->id }}">
      <header class="payment-method-dialog__header">
        <h2 id="payment-method-heading-{{ $method->id }}">Configure {{ $method->name }}</h2>
        <button type="button" class="btn subtle compact payment-method-dialog__close" data-modal-close aria-label="Close configuration" autofocus>×</button>
      </header>
      <div class="payment-method-dialog__body">
        @if($retry && $errors->any())
          <ul role="alert">
            @foreach($errors->all() as $error)
              <li>{{ $error }}</li>
            @endforeach
          </ul>
        @endif
        @if($method->usesGateway())
          @php($live = (bool) config('commerce.live_gateways'))
          <p>{{ $live ? 'Live environment' : 'Sandbox environment' }} · {{ \App\Support\CommerceGateway::ready($method->provider, $live) ? 'Credentials saved' : 'Credentials required' }}</p>
          @if($method->automatic())
            <p>Webhook endpoint<br><code>{{ route('commerce.webhook', $method->provider) }}</code></p>
          @else
            <p>Creates a separate USD QR for each order and checks payment with {{ $method->provider === 'aba' ? 'ABA PayWay' : 'Bakong' }}.</p>
          @endif
          <p>Leave a field blank to keep its saved value.</p>
          @foreach(\App\Models\CommerceGatewaySetting::FIELDS[$method->provider] as $field => $label)
            <label>{{ $label }}
              <input name="gateway[{{ $field }}]" type="{{ in_array($field, ['secret', 'webhook_secret', 'token'], true) ? 'password' : 'text' }}"
                     autocomplete="new-password" autocapitalize="off" spellcheck="false">
            </label>
          @endforeach
        @else
          <label>Transfer instructions<textarea name="instructions" rows="3" maxlength="2500">{{ $retry ? old('instructions') : $method->instructions }}</textarea></label>
          <label>Optional merchant QR image<input name="qr" type="file" accept="image/png,image/jpeg,image/webp"></label>
          @if($method->qr_path)
            <label><input name="remove_qr" type="checkbox" value="1"> Remove saved QR</label>
          @endif
        @endif
        <label>Availability
          <select name="available">
            <option value="0" @selected(!(bool) ($retry ? old('available') : $method->available))>Hidden</option>
            <option value="1" @selected((bool) ($retry ? old('available') : $method->available))>Available</option>
          </select>
        </label>
      </div>
      <footer class="payment-method-dialog__actions">
        <button class="btn subtle" type="button" data-modal-close>Cancel</button>
        <button class="btn primary" type="submit">Save settings</button>
      </footer>
    </form>
  </dialog>
@endforeach
<script>
  document.querySelector('dialog[data-payment-reopen]')?.showModal();
</script>
@endsection
