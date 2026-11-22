@php
  $live = \App\Models\CommerceGatewaySetting::checkoutIsLive('bakong');
  $activeLabel = $live ? 'Production' : 'Sandbox';
  $selection = $retry ? old('bakong_environment') : null;
@endphp

<p>Active Bakong environment <strong>{{ $activeLabel }}</strong></p>
<p>Bakong generates a USD QR for each order. Use the National Bank of Cambodia API token and recipient details for each environment. Leave fields blank to keep their saved values.</p>

<label for="bakong-environment-{{ $method->id }}">Use for new Bakong checkouts</label>
<select id="bakong-environment-{{ $method->id }}" name="bakong_environment" aria-describedby="bakong-mode-help-{{ $method->id }}">
  <option value="" @selected($selection === null)>Keep current ({{ $activeLabel }})</option>
  <option value="sandbox" @selected($selection === 'sandbox')>Sandbox</option>
  <option value="live" @selected($selection === 'live')>Production</option>
</select>
<p id="bakong-mode-help-{{ $method->id }}">Sandbox checkout runs on test installations. Public checkout requires Production, an available Bakong method, and enabled payment processing.</p>

<div class="payment-method-dialog__environments">
  @foreach(['sandbox' => 'Sandbox', 'live' => 'Production'] as $mode => $label)
    @php($configured = \App\Support\CommerceGateway::ready('bakong', $mode === 'live'))
    <fieldset class="payment-method-dialog__environment">
      <legend>{{ $label }}</legend>
      <p>{{ $configured ? 'Credentials saved' : 'Credentials required' }}</p>
      @foreach(\App\Models\CommerceGatewaySetting::FIELDS['bakong'] as $field => $fieldLabel)
        <label>{{ $field === 'token' ? $label.' API token' : $fieldLabel }}
          <input name="gateway[{{ $mode }}][{{ $field }}]" type="{{ $field === 'token' ? 'password' : 'text' }}"
                 autocomplete="{{ $field === 'token' ? 'new-password' : 'off' }}" autocapitalize="off" spellcheck="false">
        </label>
      @endforeach
    </fieldset>
  @endforeach
</div>
