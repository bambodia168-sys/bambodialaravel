@extends('layouts.workspace')
@section('workspace','account')
@section('title','Account Security')
@section('content')
@php
  $siteName=\App\Models\SiteSetting::setting('identity.name');
  $uri=$enrollmentSecret ? \App\Support\Security\Totp::uri($siteName,auth()->user()->email,$enrollmentSecret) : null;
@endphp
<div class="container inner-page">
  <div class="page-intro">
    <div class="eyebrow">My Account / Access &amp; Security</div>
    <h1>Account Security</h1>
    <p>Protect your login with a six-digit authenticator code. Recovery codes give access if your device is unavailable.</p>
  </div>
  <section class="panel">
    <div class="panel-head">
      <div><h2>Two-factor authentication</h2>
        <p>Use your authenticator app, such as Microsoft Authenticator, Google Authenticator, or another TOTP app.</p></div>
      <span class="role-pill">{{ $credential ? 'Enabled' : 'Not enabled' }}</span>
    </div>
    @if(!$credential && !$enrollmentSecret)
      <form method="post" action="{{ route('account.security.begin') }}" class="settings-form">
        @csrf
        <label for="begin-password">Confirm current password</label>
        <input id="begin-password" name="current_password" type="password" autocomplete="current-password" required>
        <div class="dialog-actions"><button class="btn primary" type="submit"><x-icon name="shield-check" size="16"/> Set up two-factor authentication</button></div>
      </form>
    @elseif(!$credential)
      <div class="settings-form">
        <p><strong>Step 1.</strong> Add this secret to your authenticator app. Never share it.</p>
        <label for="totp-enrollment-secret">Authenticator setup secret</label>
        <input id="totp-enrollment-secret" class="mono" value="{{ $enrollmentSecret }}" readonly autocomplete="off">
        <details><summary>Manual authenticator URI</summary><textarea class="mono" readonly rows="3">{{ $uri }}</textarea></details>
      </div>
      <form class="settings-form" method="post" action="{{ route('account.security.enable') }}">
        @csrf
        <label for="enrollment-code">Step 2. Enter current six-digit code</label>
        <input id="enrollment-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
          pattern="[0-9]{6}" maxlength="6" required>
        <div class="dialog-actions"><button class="btn primary" type="submit">Verify &amp; enable</button></div>
      </form>
    @else
      <div class="settings-form">
        <p>Enabled since {{ $credential->enabled_at?->format('d M Y') }}. Each authenticator code can only be used once.</p>
        <p><strong>{{ count($credential->recovery_hashes??[]) }}</strong> recovery codes remaining.</p>
      </div>
      <form class="settings-form" method="post" action="{{ route('account.security.disable') }}">
        @csrf
        <h3>Disable two-factor authentication</h3>
        <p>Requires both your password and an authenticator code or unused recovery code.</p>
        <label for="disable-password">Current password</label>
        <input id="disable-password" name="current_password" type="password" autocomplete="current-password" required>
        <label for="disable-code">Authenticator or recovery code</label>
        <input id="disable-code" name="code" type="text" required autocomplete="one-time-code">
        <div class="dialog-actions"><button class="btn subtle" type="submit">Disable two-factor</button></div>
      </form>
    @endif
  </section>
  @if(count($recoveryCodes))
    <section class="panel" style="margin-top:20px">
      <div class="panel-head"><div><h2>One-use recovery codes</h2>
        <p>Save these codes securely now. They will not be shown again. Each code works exactly once.</p></div></div>
      <div class="settings-form"><div class="mono" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px">
        @foreach($recoveryCodes as $code)<strong>{{ $code }}</strong>@endforeach
      </div></div>
    </section>
  @endif
</div>
@endsection
