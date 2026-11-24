@php
  $showFields = $errors->hasAny([
    'credential_label', 'credential_username', 'credential_password',
    'credential_key', 'credential_url', 'credential_days',
  ]);
@endphp
<div id="ticket-secure-share" class="ticket-secure-panel" data-ticket-secret-panel @unless($showFields) hidden @endunless>
  <div class="ticket-secure-panel-heading">
    <x-icon name="shield-check" size="20"/>
    <div>
      <h3>Share temporary access securely</h3>
      <p>Stored separately from ticket messages, encrypted with the platform key. Only assigned support staff or authorized administrators can reveal it. Automatically expires; you can revoke it anytime.</p>
    </div>
  </div>
  <div class="ticket-secure-grid">
    <div class="ticket-secure-wide">
      <label for="ticket-secret-label">Access label</label>
      <input id="ticket-secret-label" name="credential_label" maxlength="120"
        autocomplete="off" value="{{ old('credential_label') }}" placeholder="Example: Temporary staging WordPress login">
      @error('credential_label')<p class="ticket-field-error">{{ $message }}</p>@enderror
    </div>
    <div class="ticket-secure-wide">
      <label for="ticket-secret-url">Portal URL (HTTPS only)</label>
      <input id="ticket-secret-url" type="url" name="credential_url" maxlength="2048" inputmode="url"
        autocomplete="off" value="{{ old('credential_url') }}" placeholder="https://example.com/admin">
      @error('credential_url')<p class="ticket-field-error">{{ $message }}</p>@enderror
    </div>
    <div>
      <label for="ticket-secret-username">Username (encrypted)</label>
      <input id="ticket-secret-username" name="credential_username" maxlength="255"
        autocomplete="off" value="" placeholder="Login name">
    </div>
    <div>
      <label for="ticket-secret-password">Password (encrypted)</label>
      <input id="ticket-secret-password" type="password" name="credential_password" maxlength="4096"
        autocomplete="new-password" value="" placeholder="Temporary password">
      @error('credential_password')<p class="ticket-field-error">{{ $message }}</p>@enderror
    </div>
    <div class="ticket-secure-wide">
      <label for="ticket-secret-key">Temporary API token / access key (encrypted)</label>
      <textarea id="ticket-secret-key" name="credential_key" rows="2" maxlength="4096"
        autocomplete="off" placeholder="Use a scoped temporary token, never a production private or root key."></textarea>
      @error('credential_key')<p class="ticket-field-error">{{ $message }}</p>@enderror
    </div>
    <div class="ticket-secure-wide">
      <label for="ticket-secret-days">Access expiry</label>
      <select name="credential_days" id="ticket-secret-days">
        <option value="1" @selected(old('credential_days') === '1')>1 day</option>
        <option value="3" @selected(old('credential_days', '3') === '3')>3 days (recommended)</option>
        <option value="7" @selected(old('credential_days') === '7')>7 days</option>
      </select>
    </div>
  </div>
  <p class="text-muted">Prefer a temporary, least-privilege support account. Never share payment-card numbers, recovery codes, seed phrases, permanent private keys, or your main administrator password. Rotate/revoke access after the support task.</p>
</div>
