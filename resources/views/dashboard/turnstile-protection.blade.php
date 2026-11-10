@extends('layouts.dashboard')
@section('title','Bot Protection')
@section('content')
<div class="page-intro">
  <div class="eyebrow">Access &amp; Security / Spam protection</div>
  <h1>Cloudflare Turnstile</h1>
  <p>Protect account registration, login and password-reset requests with real Cloudflare token verification. Keep existing account forms and layout. Bot checks remain off until a successful live connection test.</p>
</div>
<div class="metrics">
  <div class="metric"><span><x-icon name="shield-check" size="18"/> Status</span>
    <strong style="font-size:22px">{{ $config?->is_enabled?'Enabled':'Disabled' }}</strong>
    <small>{{ $config?'Keys saved securely':'No keys configured' }}</small>
  </div>
  <div class="metric"><span><x-icon name="activity" size="18"/> Latest verification</span>
    <strong style="font-size:18px">{{ $config?->tested_at?->format('d M Y H:i')??'Not tested' }}</strong>
    <small>UTC · Valid test needed to enable</small>
  </div>
</div>
<section class="panel" style="margin-top:20px">
  <div class="panel-head"><div><h2>1. Cloudflare keys</h2><p>Add a Turnstile widget from your Cloudflare dashboard using this website hostname. Never paste keys into public HTML.</p></div></div>
  <form class="settings-form" method="post" action="{{ route('dashboard.turnstile.configure') }}" autocomplete="off">
    @csrf
    <label for="turnstile-sitekey">Public site key</label>
    <input id="turnstile-sitekey" name="site_key" required maxlength="128" value="{{ $config?->site_key??'' }}" placeholder="Cloudflare Turnstile site key">
    <label for="turnstile-secret">Secret key</label>
    <input id="turnstile-secret" name="secret_key" type="password" maxlength="256"
      placeholder="{{ $config ? 'Leave empty to retain the encrypted secret' : 'Enter the private Turnstile secret' }}"
      autocomplete="new-password">
    <p class="hint">Saving new keys turns bot protection off until verification succeeds. Nothing is activated automatically.</p>
    <button type="submit" class="btn primary">Save keys</button>
  </form>
</section>
@if($config)
<section class="panel" style="margin-top:20px">
  <div class="panel-head"><div><h2>2. Test connection</h2><p>Complete a real challenge on this host. The submitted token is validated by your server using Cloudflare Siteverify, including hostname and expected action.</p></div></div>
  <form class="settings-form" method="post" action="{{ route('dashboard.turnstile.verify') }}">
    @csrf
    <div class="cf-turnstile" data-sitekey="{{ $config->site_key }}" data-action="admin_test" data-theme="auto" data-size="flexible"></div>
    <button type="submit" class="btn primary">Verify connection</button>
  </form>
</section>
<section class="panel" style="margin-top:20px">
  <div class="panel-head"><div><h2>3. Protected forms</h2><p>Select the real authentication actions that will require Cloudflare validation. Existing Laravel throttling and session security remain active.</p></div></div>
  <form class="settings-form" method="post" action="{{ route('dashboard.turnstile.status') }}">
    @csrf @method('PATCH')
    <label for="turnstile-enable">Protection status</label>
    <select id="turnstile-enable" name="is_enabled">
      <option value="0" @selected(!$config->is_enabled)>Disabled</option>
      <option value="1" @selected($config->is_enabled)>Enabled (requires recently verified keys)</option>
    </select>
    @foreach(['protect_login'=>'Login','protect_registration'=>'Registration','protect_password_reset'=>'Password reset request'] as $field=>$label)
      <label for="turnstile-{{ $field }}">{{ $label }}</label>
      <select id="turnstile-{{ $field }}" name="{{ $field }}">
        <option value="1" @selected($config->{$field})>Protected</option>
        <option value="0" @selected(!$config->{$field})>Not protected</option>
      </select>
    @endforeach
    <button type="submit" class="btn primary">Save protection</button>
  </form>
</section>
<section class="panel" style="margin-top:20px">
  <div class="panel-head"><div><h2>Disconnect</h2><p>Removing the configuration restores the existing account forms and rate limits.</p></div>
    <form method="post" action="{{ route('dashboard.turnstile.destroy') }}">@csrf @method('DELETE')
      <button type="submit" class="btn subtle compact">Remove configuration</button>
    </form>
  </div>
</section>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
@endif
@endsection
