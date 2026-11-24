@php
  $showFields = $errors->hasAny([
    'credential_label', 'credential_username', 'credential_password',
    'credential_key', 'credential_url', 'credential_days',
  ]);
@endphp
<div id="ticket-secure-share" class="ticket-secure-panel" data-ticket-secret-panel @unless($showFields) hidden @endunless>
  <div class="ticket-secure-panel-heading">
    <span class="ticket-vault-icon" aria-hidden="true"><x-icon name="lock-keyhole" size="20"/></span>
    <div>
      <div class="ticket-vault-eyebrow">ENCRYPTED · TIME-LIMITED</div>
      <h3>Temporary access details</h3>
      <p>Share only access created for this support request. Your information is encrypted separately from the conversation.</p>
    </div>
  </div>

  <div class="ticket-secure-note">
    <x-icon name="shield-check" size="18"/>
    <span>Only assigned support agents and authorized administrators can reveal this access. Every reveal is logged, and access expires automatically.</span>
  </div>

  <div class="ticket-secure-grid">
    <div class="ticket-secure-field ticket-secure-wide">
      <label for="ticket-secret-label">Access label <span class="ticket-field-required">*</span></label>
      <input id="ticket-secret-label" name="credential_label" maxlength="120"
        autocomplete="off" value="{{ old('credential_label') }}"
        placeholder="e.g. Temporary staging dashboard" aria-describedby="ticket-secret-label-help">
      <small id="ticket-secret-label-help">A short description support staff can recognize.</small>
      @error('credential_label')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror
    </div>
    <div class="ticket-secure-field ticket-secure-wide">
      <label for="ticket-secret-url">Portal URL <span class="ticket-field-optional">Optional · HTTPS only</span></label>
      <input id="ticket-secret-url" type="url" name="credential_url" maxlength="2048" inputmode="url"
        autocomplete="off" spellcheck="false" value="{{ old('credential_url') }}"
        placeholder="https://example.com/admin">
      @error('credential_url')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror
    </div>
    <div class="ticket-secure-field">
      <label for="ticket-secret-username">Username <span class="ticket-field-optional">Encrypted</span></label>
      <input id="ticket-secret-username" name="credential_username" maxlength="255"
        autocomplete="off" spellcheck="false" value="" placeholder="Temporary login name">
      @error('credential_username')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror
    </div>
    <div class="ticket-secure-field">
      <label for="ticket-secret-password">Password <span class="ticket-field-optional">Encrypted</span></label>
      <input id="ticket-secret-password" type="password" name="credential_password" maxlength="4096"
        autocomplete="new-password" value="" placeholder="Temporary password">
      @error('credential_password')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror
    </div>
    <div class="ticket-secure-field ticket-secure-wide">
      <label for="ticket-secret-key">API token / access key <span class="ticket-field-optional">Optional · Encrypted</span></label>
      <textarea id="ticket-secret-key" name="credential_key" rows="2" maxlength="4096"
        autocomplete="off" spellcheck="false"
        placeholder="Only use a temporary, scoped token—never a permanent production key."></textarea>
      @error('credential_key')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror
    </div>
    <div class="ticket-secure-field ticket-secure-wide">
      <label for="ticket-secret-days">Access expiry</label>
      <select name="credential_days" id="ticket-secret-days">
        <option value="1" @selected(old('credential_days') === '1')>1 day</option>
        <option value="3" @selected(old('credential_days', '3') === '3')>3 days (recommended)</option>
        <option value="7" @selected(old('credential_days') === '7')>7 days</option>
      </select>
      <small>Choose the shortest time required for troubleshooting. You can revoke access earlier.</small>
    </div>
  </div>
  <div class="ticket-secure-footer-note">
    <x-icon name="shield-check" size="18"/>
    <p>Never share recovery codes, payment-card details, seed phrases, permanent private keys, or your main administrator password. Revoke temporary access when the support task is finished.</p>
  </div>
</div>
