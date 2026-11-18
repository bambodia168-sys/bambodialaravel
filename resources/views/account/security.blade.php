@extends('layouts.workspace')
@section('workspace','account')
@section('title','Account Security')
@section('content')
<div class="container inner-page">
  <div class="page-intro">
    <div class="eyebrow">My Account / Access &amp; Security</div>
    <h1>Account Security</h1>
    <p>Manage your authenticators, recovery codes, and passkeys.</p>
  </div>
  <section class="panel" id="account-two-factor">
    <div class="panel-head">
      <div><h2>Two-factor authentication</h2>
        <p>Use a six-digit code from Google Authenticator, Microsoft Authenticator, or another TOTP app.</p></div>
      <span class="role-pill">{{ $credential ? 'Enabled' : 'Not Enabled' }}</span>
    </div>
    @if(session('authenticator_status'))
      <p class="account-security-notice" role="status"><x-icon name="shield-check" size="18"/> {{ session('authenticator_status') }}</p>
    @endif
    @if($credential)
      @php
        $devices = [
          [$credential->authenticatorKey(), $credential->authenticator_name],
          ...$credential->devices->map(fn ($device) => [$device->id, $device->name])->all(),
        ];
      @endphp
      <div class="settings-form">
        <h3>Authenticators</h3>
        <p>Enabled since {{ $credential->enabled_at?->format('d M Y') }}</p>
        <p>{{ count($devices) }} of {{ $deviceLimit }} devices · <strong>{{ count($credential->recovery_hashes ?? []) }}</strong> recovery codes remaining</p>
        <ul class="account-authenticator-list">
          @foreach($devices as [$key, $name])
            <li>
              <strong>{{ $name }}</strong>
              @if(count($devices) > 1)
                <details>
                  <summary>Remove {{ $name }}</summary>
                  <form method="post" action="{{ route('account.security.authenticators.destroy', $key) }}">
                    @csrf @method('DELETE')
                    <label for="remove-password-{{ $key }}">Current password</label>
                    <input id="remove-password-{{ $key }}" name="current_password" type="password" autocomplete="current-password" required>
                    <label for="remove-code-{{ $key }}">Authenticator or recovery code</label>
                    <input id="remove-code-{{ $key }}" name="code" type="text" autocomplete="one-time-code" required maxlength="40">
                    <button class="btn subtle" type="submit">Remove authenticator</button>
                  </form>
                </details>
              @endif
            </li>
          @endforeach
        </ul>
      </div>
    @endif
    @if($enrollment)
      <div class="settings-form">
        <h3>Scan the QR code for {{ $enrollment['name'] }}</h3>
        <p>Add a new entry in your authenticator app, then scan this code. Setup expires in 10 minutes.</p>
        <img class="account-authenticator-qr" src="{{ $enrollmentQr }}" width="256" height="256" alt="QR code for authenticator setup">
        <details>
          <summary>Enter the setup key manually</summary>
          <label for="totp-enrollment-secret">Setup key</label>
          <input id="totp-enrollment-secret" class="mono" value="{{ $enrollment['secret'] }}" readonly autocomplete="off">
          <label for="totp-enrollment-uri">Authenticator URI</label>
          <textarea id="totp-enrollment-uri" class="mono" readonly rows="3">{{ $enrollmentUri }}</textarea>
        </details>
      </div>
      <form class="settings-form" method="post" action="{{ route('account.security.enable') }}">
        @csrf
        <label for="enrollment-code">Six-digit code from the new authenticator</label>
        <input id="enrollment-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
          pattern="[0-9]{6}" maxlength="6" required>
        @error('code')<p class="account-security-error" role="alert">{{ $message }}</p>@enderror
        <div class="dialog-actions">
          <button class="btn subtle" type="submit" form="cancel-authenticator-setup">Cancel setup</button>
          <button class="btn primary" type="submit">{{ $credential ? 'Verify & add authenticator' : 'Verify & enable' }}</button>
        </div>
      </form>
      <form id="cancel-authenticator-setup" method="post" action="{{ route('account.security.cancel') }}">@csrf</form>
    @elseif($enrollmentAllowed && (!$credential || count($devices) < $deviceLimit))
      <form method="post" action="{{ route('account.security.begin') }}" class="settings-form">
        @csrf
        <label for="authenticator-device-name">Authenticator name</label>
        <input id="authenticator-device-name" name="name" value="{{ old('name') }}" maxlength="80" placeholder="e.g. Work phone" required>
        <label for="begin-password">Current password</label>
        <input id="begin-password" name="current_password" type="password" autocomplete="current-password" required>
        @if($credential)
          <label for="begin-current-code">Existing authenticator or recovery code</label>
          <input id="begin-current-code" name="current_code" type="text" autocomplete="one-time-code" maxlength="40" required>
          @error('current_code')<p class="account-security-error" role="alert">{{ $message }}</p>@enderror
        @endif
        <div class="dialog-actions"><button class="btn primary" type="submit"><x-icon name="shield-check" size="16"/> {{ $credential ? 'Add authenticator' : 'Set up two-factor authentication' }}</button></div>
      </form>
    @elseif(!$enrollmentAllowed)
      <div class="settings-form"><p>New two-factor setup is currently disabled.</p></div>
    @endif
    @if($credential)
      <div class="settings-form">
        <details>
          <summary>Disable two-factor authentication</summary>
          <form class="account-disable-authenticator" method="post" action="{{ route('account.security.disable') }}">
            @csrf
            <p>Disabling two-factor authentication removes every authenticator and invalidates your recovery codes.</p>
            <label for="disable-password">Current password</label>
            <input id="disable-password" name="current_password" type="password" autocomplete="current-password" required>
            <label for="disable-code">Authenticator or recovery code</label>
            <input id="disable-code" name="code" type="text" required autocomplete="one-time-code" maxlength="40">
            <div class="dialog-actions"><button class="btn subtle" type="submit">Disable two-factor</button></div>
          </form>
        </details>
      </div>
    @endif
    @if(count($recoveryCodes))
      <div class="settings-form">
        <h3>One-use recovery codes</h3>
        <p>Save these codes securely now. They will not be shown again. Each code works exactly once.</p>
        <ul class="account-recovery-codes mono">
          @foreach($recoveryCodes as $code)<li>{{ $code }}</li>@endforeach
        </ul>
      </div>
    @endif
  </section>
  @include('account.sign-in-methods')
</div>
@endsection
