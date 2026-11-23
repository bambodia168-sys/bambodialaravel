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
  </div>
  <section class="panel" aria-labelledby="payment-processing-heading">
    <div class="panel-head">
      <div>
        <h2 id="payment-processing-heading">Site-wide payment processing</h2>
        <p>{{ $collectingPayments
          ? 'Buyers can pay for USD orders using available payment methods.'
          : 'New payments are paused. You can configure methods and review payments already submitted.' }}</p>
        @error('enabled', 'paymentProcessing')
          <p role="alert">{{ $message }}</p>
        @enderror
      </div>
      <form method="post" action="{{ route('dashboard.payment-methods.processing') }}">
        @csrf @method('PATCH')
        <input type="hidden" name="enabled" value="{{ $collectingPayments ? '0' : '1' }}">
        <button class="btn {{ $collectingPayments ? 'subtle' : 'primary' }}" type="submit">
          {{ $collectingPayments ? 'Pause new payments' : 'Enable payments' }}
        </button>
      </form>
    </div>
  </section>
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
  .payment-method-dialog__mode { min-inline-size: 0; margin: .9rem 0 0; border: 0; padding: 0; }
  .payment-method-dialog__mode legend { font-weight: 700; }
  .payment-method-dialog__mode-options {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
    margin-block-start: .45rem;
  }
  .payment-method-dialog__mode-options label {
    display: flex;
    flex: 1 1 auto;
    align-items: center;
    gap: .55rem;
    margin: 0;
    padding: .6rem .8rem;
    border: 1px solid var(--line);
    border-radius: .65rem;
    cursor: pointer;
  }
  .payment-method-dialog__mode-options label:has(:checked) { border-color: var(--accent); }
  .payment-method-dialog__mode-options input[type="radio"] {
    width: auto;
    margin: 0;
    accent-color: var(--accent);
  }
  .payment-method-dialog__environment {
    margin: 0;
    min-inline-size: 0;
    padding: .65rem .9rem .9rem;
    border: 1px solid var(--line);
    border-radius: .75rem;
  }
  .payment-method-dialog__environment legend { padding-inline: .35rem; font-weight: 700; }
  .payment-method-dialog__environment p { margin: .2rem 0 .65rem; color: var(--muted); }
  .payment-method-dialog__mode-options small { display: block; margin-top: .2rem; color: var(--muted); font-size: .78rem; }
  .payment-method-dialog__environment[hidden] { display: none; }
  .payment-method-dialog__mode-options span { min-width: 0; overflow-wrap: anywhere; }
  .payment-method-dialog__mode-options input { flex: 0 0 auto; }
  @media (max-width: 480px) {
    .payment-method-dialog__mode-options { flex-direction: column; gap: .45rem; }
    .payment-method-dialog__mode-options label { flex-basis: auto; }
  }
  .payment-method-dialog :is(input,textarea,select):focus-visible { outline: 2px solid currentColor; outline-offset: 2px; }
  .payment-method-dialog__close { font-size: 1.35rem; }
  dialog.payment-method-dialog--add { width: min(29rem, calc(100% - 2rem)); }
  #add-payment-method-help { margin: .75rem 0 0; }
  .payment-method-dialog__body .payment-method-dialog__error { color: var(--danger); margin: .4rem 0 0; }
  .payment-qr-preview { margin: .75rem 0; }
  .payment-qr-preview img {
    display: block;
    max-inline-size: 100%;
    max-block-size: 22rem;
    inline-size: auto;
    block-size: auto;
    margin-block: .35rem;
  }
  .payment-qr-preview [hidden] { display: none; }
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
        @if($method->provider === 'bakong')
          @include('commerce.partials.bakong-settings', ['method' => $method, 'retry' => $retry])
        @elseif($method->usesGateway())
          @php($live = \App\Models\CommerceGatewaySetting::checkoutIsLive($method->provider))
          @if($method->provider === 'stripe')
            @php($environment = $retry ? old('stripe_environment', $live ? 'live' : 'sandbox') : ($live ? 'live' : 'sandbox'))
            <p>Stripe checkout environment <strong>{{ $live ? 'Live Production' : 'Sandbox' }}</strong></p>
            <label for="stripe-environment-{{ $method->id }}">Credentials environment</label>
            <select id="stripe-environment-{{ $method->id }}" name="stripe_environment">
              <option value="sandbox" @selected($environment === 'sandbox')>Sandbox</option>
              <option value="live" @selected($environment === 'live')>Live Production</option>
            </select>
            <p>Sandbox {{ \App\Support\CommerceGateway::ready('stripe', false) ? 'credentials saved' : 'credentials required' }} · Live Production {{ \App\Support\CommerceGateway::ready('stripe', true) ? 'credentials saved' : 'credentials required' }}</p>
          @elseif($method->provider === 'baray')
            @php($environment = $retry ? old('baray_environment', $live ? 'live' : 'sandbox') : ($live ? 'live' : 'sandbox'))
            <p>Currently selected for checkout: <strong>{{ $live ? 'Production' : 'Sandbox (Development)' }}</strong>. Saving credentials does not switch environments or enable payments.</p>
            <fieldset class="payment-method-dialog__mode" data-baray-settings>
              <legend>Select the Baray environment to configure</legend>
              <div class="payment-method-dialog__mode-options" role="group" aria-label="Baray environment">
                @foreach(['sandbox' => 'Sandbox / Development', 'live' => 'Production'] as $mode => $label)
                  @php($isReady = \App\Support\CommerceGateway::ready('baray', $mode === 'live'))
                  <label>
                    <input type="radio" name="baray_environment" value="{{ $mode }}" @checked($environment === $mode)>
                    <span><strong>{{ $label }}</strong><small class="payment-method-dialog__status">{{ $isReady ? 'Credentials saved' : 'Not configured' }}</small></span>
                  </label>
                @endforeach
              </div>
              @foreach(['sandbox' => false, 'live' => true] as $mode => $isProduction)
                <fieldset class="payment-method-dialog__environment" data-baray-fields="{{ $mode }}" @if($environment !== $mode) hidden @endif>
                  <legend>{{ $isProduction ? 'Production' : 'Sandbox / Development' }} credentials</legend>
                  <p>{{ $isProduction ? 'Real-money transactions. Keep the method hidden until the bank connection and webhook are verified.' : 'Simulated payments with Development keys. Use an isolated QA website and database.' }}</p>
                  @foreach(\App\Models\CommerceGatewaySetting::FIELDS['baray'] as $field => $label)
                    <label for="baray-{{ $mode }}-{{ $field }}-{{ $method->id }}">{{ $label }}
                      <input id="baray-{{ $mode }}-{{ $field }}-{{ $method->id }}"
                             name="gateway[{{ $mode }}][{{ $field }}]" type="password"
                             autocomplete="new-password" autocapitalize="off" spellcheck="false"
                             placeholder="Leave empty to keep existing value">
                    </label>
                  @endforeach
                </fieldset>
              @endforeach
            </fieldset>
            <p>Baray hosts the bank selection and payment page. The webhook confirms payment; the browser return alone never marks an order paid.</p>
          @else
            <p>{{ $live ? 'Live environment' : 'Sandbox environment' }} · {{ \App\Support\CommerceGateway::ready($method->provider, $live) ? 'Credentials saved' : 'Credentials required' }}</p>
          @endif
          @if($method->automatic())
            <p>Enter credentials for this environment and register the webhook endpoint in your {{ $method->name }} dashboard.</p>
            <p><code>{{ route('commerce.webhook', $method->provider) }}</code></p>
          @else
            <p>Creates a separate USD QR for each order and checks payment with ABA PayWay.</p>
            <p>Use your ABA PayWay merchant ID and API key. Arrange API access and server allowlisting with ABA.</p>
          @endif
          <p>Leave a field blank to keep its saved value{{ in_array($method->provider, ['stripe', 'baray'], true) ? ' for the selected environment' : '' }}.</p>
          @unless($method->provider === 'baray')
            @foreach(\App\Models\CommerceGatewaySetting::FIELDS[$method->provider] as $field => $label)
              <label>{{ $label }}
                <input name="gateway[{{ $field }}]" type="{{ in_array($field, ['secret', 'webhook_secret', 'token', 'sk', 'iv', 'api_key'], true) ? 'password' : 'text' }}"
                       autocomplete="new-password" autocapitalize="off" spellcheck="false">
              </label>
            @endforeach
          @endunless
          @if($method->provider === 'stripe')
            <label>
              <input type="checkbox" name="activate_stripe_environment" value="1" @checked($retry && old('activate_stripe_environment'))>
              Use this environment for new Stripe checkouts
            </label>
            <p>Live Production can accept real payments when Stripe is available and site-wide payment processing is enabled. Test checkouts run on the sandbox site.</p>
          @elseif($method->provider === 'baray')
            <label>
              <input type="checkbox" name="activate_baray_environment" value="1" @checked($retry && old('activate_baray_environment'))>
              Use this environment for new Baray checkouts
            </label>
            <p>Use <strong>Save settings</strong> to store either or both credential sets. To switch checkout mode, select the environment and check the box above. Production must not be activated until live bank confirmation, webhook authentication and QA acceptance are verified.</p>
          @endif
        @else
          <p>Enter the recipient and account details buyers need to complete the transfer. Buyers upload their receipt from the order page for review.</p>
          <label>Transfer instructions<textarea name="instructions" rows="3" maxlength="2500">{{ $retry ? old('instructions') : $method->instructions }}</textarea></label>
          <div data-payment-qr>
            <label>Optional merchant QR image<input name="qr" type="file" accept="image/png,image/jpeg,image/webp"></label>
            @if($method->qr_path)
              @php($previewUrl = route('dashboard.payment-methods.qr-preview', $method))
              <figure class="payment-qr-preview" data-saved-qr>
                <figcaption>Saved QR image</figcaption>
                <img src="{{ $previewUrl }}" alt="Saved {{ $method->name }} merchant QR" loading="lazy">
                <a href="{{ $previewUrl }}" target="_blank" rel="noopener">View full-size QR</a>
                <p data-qr-error hidden>The saved QR image could not be loaded. Upload a replacement.</p>
              </figure>
              <label><input name="remove_qr" type="checkbox" value="1"> Remove saved QR</label>
            @endif
            <figure class="payment-qr-preview" data-selected-qr hidden>
              <figcaption>Selected QR image</figcaption>
              <img alt="Selected {{ $method->name }} merchant QR" hidden>
              <p data-qr-error hidden>The selected image could not be opened. Choose a PNG, JPEG, or WebP image.</p>
            </figure>
          </div>
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
  for (const settings of document.querySelectorAll('[data-baray-settings]')) {
    const radios = settings.querySelectorAll('input[name="baray_environment"]');
    const panels = settings.querySelectorAll('[data-baray-fields]');
    const updatePanels = () => {
      const selected = settings.querySelector('input[name="baray_environment"]:checked')?.value;
      for (const panel of panels) {
        panel.hidden = panel.dataset.barayFields !== selected;
        // Hidden fields stay enabled so saving both modes at once preserves their values.
      }
    };
    radios.forEach(radio => radio.addEventListener('change', updatePanels));
    updatePanels();
  }

  for (const settings of document.querySelectorAll('[data-bakong-settings]')) {
    const radios = settings.querySelectorAll('input[name="bakong_environment"]');
    const panels = settings.querySelectorAll('[data-bakong-fields]');
    const updatePanels = () => {
      const selected = settings.querySelector('input[name="bakong_environment"]:checked')?.value;
      for (const panel of panels) {
        const inactive = panel.dataset.bakongFields !== selected;
        panel.hidden = inactive;
        panel.disabled = inactive;
      }
    };
    radios.forEach(radio => radio.addEventListener('change', updatePanels));
    updatePanels();
  }

  for (const group of document.querySelectorAll('[data-payment-qr]')) {
    const input = group.querySelector('input[type="file"]');
    const saved = group.querySelector('[data-saved-qr]');
    const selected = group.querySelector('[data-selected-qr]');
    const image = selected.querySelector('img');
    const selectionError = selected.querySelector('[data-qr-error]');
    let previewUrl;

    if (saved) {
      const savedImage = saved.querySelector('img');
      const showSavedError = () => {
        savedImage.hidden = true;
        saved.querySelector('a').hidden = true;
        saved.querySelector('[data-qr-error]').hidden = false;
      };
      savedImage.addEventListener('error', showSavedError);
      if (savedImage.complete && !savedImage.naturalWidth) showSavedError();
    }

    image.addEventListener('error', () => {
      image.hidden = true;
      selectionError.hidden = false;
    });

    input.addEventListener('change', () => {
      if (previewUrl) URL.revokeObjectURL(previewUrl);
      const file = input.files[0];
      previewUrl = file ? URL.createObjectURL(file) : undefined;
      image.hidden = !file;
      selectionError.hidden = true;
      selected.hidden = !file;
      if (saved) saved.hidden = Boolean(file);
      if (previewUrl) image.src = previewUrl;
      else image.removeAttribute('src');
    });
  }
  document.querySelector('dialog[data-payment-reopen]')?.showModal();
</script>
@endsection
