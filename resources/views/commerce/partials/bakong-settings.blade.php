@php
  $live = \App\Models\CommerceGatewaySetting::checkoutIsLive('bakong');
  $active = $live ? 'live' : 'sandbox';
  $modes = ['sandbox' => 'Sandbox', 'live' => 'Production'];
  $previous = $retry ? old('bakong_environment') : null;
  $selection = in_array($previous, array_keys($modes), true) ? $previous : $active;
@endphp

<div data-bakong-settings>
  <p>Current environment <strong>{{ $modes[$active] }}</strong></p>
  <fieldset class="payment-method-dialog__mode" aria-describedby="bakong-mode-help-{{ $method->id }}">
    <legend>Use for new Bakong checkouts</legend>
    <div class="payment-method-dialog__mode-options">
      @foreach($modes as $mode => $label)
        <label>
          <input type="radio" name="bakong_environment" value="{{ $mode }}" @checked($selection === $mode) required>
          {{ $label }}
        </label>
      @endforeach
    </div>
  </fieldset>
  <p id="bakong-mode-help-{{ $method->id }}">Select one environment, then save. Sandbox is for test installations. Public checkout uses Production when Bakong is available and payments are enabled.</p>

  @foreach($modes as $mode => $label)
    @php($configured = \App\Support\CommerceGateway::ready('bakong', $mode === 'live'))
    <fieldset class="payment-method-dialog__environment" data-bakong-fields="{{ $mode }}" @if($selection !== $mode) hidden disabled @endif>
      <legend>{{ $label }} credentials</legend>
      <p>{{ $configured ? 'Credentials saved. Leave fields blank to keep them.' : 'Enter the recipient details and API token for this environment.' }}</p>
      @foreach(\App\Models\CommerceGatewaySetting::FIELDS['bakong'] as $field => $fieldLabel)
        <label>{{ $field === 'token' ? $label.' API token' : $fieldLabel }}
          <input name="gateway[{{ $mode }}][{{ $field }}]" type="{{ $field === 'token' ? 'password' : 'text' }}"
                 autocomplete="{{ $field === 'token' ? 'new-password' : 'off' }}" autocapitalize="off" spellcheck="false">
        </label>
      @endforeach
    </fieldset>
  @endforeach
</div>
