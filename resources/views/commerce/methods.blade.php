@extends('layouts.workspace')
@section('workspace', 'admin')
@section('title', 'Payment Methods')
@section('content')
<div class="container inner-page">
  <div class="page-intro payment-methods-intro">
    <h1>Payment methods</h1>
    <button class="btn primary compact payment-methods-add" type="button" data-modal-open="add-payment-method"
            aria-haspopup="dialog" aria-controls="add-payment-method">
      <x-icon name="plus" size="16"/> Add method
    </button>
    @if(!config('commerce.payments_enabled'))
      <p>Payment processing is disabled. You can prepare payment methods here.</p>
    @else
      <p>Configure hosted checkout and manual transfer instructions for USD orders.</p>
    @endif
  </div>
  <section class="panel">
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
  .payment-methods-intro {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: start;
    column-gap: .75rem;
  }
  .payment-methods-intro p { grid-column: 1 / -1; }
  .payment-methods-add { justify-self: end; }
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
  dialog.payment-method-dialog--add { width: min(29rem, calc(100% - 2rem)); }
  #add-payment-method-help { margin: .75rem 0 0; }
  .payment-method-dialog__body .payment-method-dialog__error { color: var(--danger); margin: .4rem 0 0; }
</style>
@php($addErrors = $errors->getBag('addPaymentMethod'))
<dialog class="config-dialog payment-method-dialog payment-method-dialog--add" id="add-payment-method"
        aria-labelledby="add-payment-method-heading" aria-describedby="add-payment-method-help"
        @if($addErrors->any()) data-payment-reopen @endif>
  <form method="post" action="{{ route('dashboard.payment-methods.store') }}">
    @csrf
    <header class="payment-method-dialog__header">
      <h2 id="add-payment-method-heading">Add manual payment method</h2>
      <button type="button" class="btn subtle compact payment-method-dialog__close" data-modal-close aria-label="Close add payment method">×</button>
    </header>
    <div class="payment-method-dialog__body">
      <label for="add-payment-method-name">Payment method name</label>
      <input id="add-payment-method-name" name="name" maxlength="80" required autofocus
             value="{{ $addErrors->any() ? old('name') : '' }}"
             @error('name', 'addPaymentMethod') aria-invalid="true" aria-describedby="add-payment-method-name-error" @enderror>
      @error('name', 'addPaymentMethod')
        <p class="payment-method-dialog__error" id="add-payment-method-name-error" role="alert">{{ $message }}</p>
      @enderror
      <label for="add-payment-method-provider">Manual payment type</label>
      <select id="add-payment-method-provider" name="provider"
              @error('provider', 'addPaymentMethod') aria-invalid="true" aria-describedby="add-payment-method-provider-error" @enderror>
        @foreach(\App\Models\CommercePaymentMethod::manualProviders() as $key => $label)
          <option value="{{ $key }}" @selected(($addErrors->any() ? old('provider', 'other') : 'other') === $key)>{{ $label }}</option>
        @endforeach
      </select>
      @error('provider', 'addPaymentMethod')
        <p class="payment-method-dialog__error" id="add-payment-method-provider-error" role="alert">{{ $message }}</p>
      @enderror
      <p id="add-payment-method-help">New methods start hidden. Use Configure to add transfer instructions or a QR image.</p>
    </div>
    <footer class="payment-method-dialog__actions">
      <button class="btn subtle" type="button" data-modal-close>Cancel</button>
      <button class="btn primary" type="submit">Add method</button>
    </footer>
  </form>
</dialog>
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
            <p>Enter credentials for this environment and register the webhook endpoint in your {{ $method->name }} dashboard.</p>
            <p><code>{{ route('commerce.webhook', $method->provider) }}</code></p>
          @else
            <p>Creates a separate USD QR for each order and checks payment with {{ $method->provider === 'aba' ? 'ABA PayWay' : 'Bakong' }}.</p>
            @if($method->provider === 'aba')
              <p>Use your ABA PayWay merchant ID and API key. Arrange API access and server allowlisting with ABA.</p>
            @else
              <p>Enter the recipient's Bakong account details and NBC Open API access token.</p>
            @endif
          @endif
          <p>Leave a field blank to keep its saved value.</p>
          @foreach(\App\Models\CommerceGatewaySetting::FIELDS[$method->provider] as $field => $label)
            <label>{{ $label }}
              <input name="gateway[{{ $field }}]" type="{{ in_array($field, ['secret', 'webhook_secret', 'token'], true) ? 'password' : 'text' }}"
                     autocomplete="new-password" autocapitalize="off" spellcheck="false">
            </label>
          @endforeach
        @else
          <p>Enter the recipient and account details buyers need to complete the transfer. Buyers upload their receipt from the order page for review.</p>
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
