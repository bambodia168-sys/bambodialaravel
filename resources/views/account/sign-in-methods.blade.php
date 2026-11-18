@php
  $passkeysEnabled = \App\Support\SystemSecurity::enabled('passkeys');
  $passkeys = auth()->user()->passkeys()->latest()->get(['id', 'name', 'last_used_at']);
  $enabledProviders = \App\Models\SocialLoginProvider::enabledProviders();
  $linkedProviders = \App\Models\SocialLoginIdentity::query()->where('user_id', auth()->id())->pluck('provider')->all();
@endphp
@push('head')
<link rel="stylesheet" href="{{ asset('css/system-configuration.css') }}">
@if($passkeysEnabled)<script defer src="{{ asset('js/passkeys.js') }}"></script>@endif
@endpush
@if($passkeysEnabled || $passkeys->isNotEmpty())
  <section class="panel" aria-label="Passkeys">
    <div class="panel-head"><div><h2>Passkeys</h2><p>Use your device’s fingerprint, face recognition, PIN, or security key to sign in.</p></div></div>
    @if($passkeysEnabled)
      <form class="settings-form" method="post" action="{{ route('account.passkeys.register') }}"
        data-passkey-form="register" data-options="{{ route('account.passkeys.options') }}">
        @csrf
        <label for="passkey-name">Device name</label>
        <input id="passkey-name" name="name" maxlength="80" placeholder="Work laptop" required>
        <label for="passkey-password">Current password</label>
        <input id="passkey-password" name="current_password" type="password" autocomplete="current-password" required>
        <p role="status"></p>
        <button class="btn primary" type="submit">Add passkey</button>
      </form>
    @endif
    @foreach($passkeys as $passkey)
      <form class="settings-form" method="post" action="{{ route('account.passkeys.destroy', $passkey->id) }}">
        @csrf @method('DELETE')
        <p><strong>{{ $passkey->name }}</strong>@if($passkey->last_used_at) · Last used {{ $passkey->last_used_at->toDateString() }}@endif</p>
        <label for="revoke-passkey-{{ $passkey->id }}">Current password</label>
        <input id="revoke-passkey-{{ $passkey->id }}" name="current_password" type="password" autocomplete="current-password" required>
        <button class="btn subtle" type="submit">Remove passkey</button>
      </form>
    @endforeach
  </section>
@endif
@if($enabledProviders || $linkedProviders)
  <section class="panel" aria-label="Social sign-in">
    <div class="panel-head"><div><h2>Social sign-in</h2><p>Connect a provider to use it the next time you sign in.</p></div></div>
    @foreach(\App\Models\SocialLoginProvider::PROVIDERS as $provider => [$label])
      @php($linked = in_array($provider, $linkedProviders, true))
      @continue(!$linked && !in_array($provider, $enabledProviders, true))
      <form class="settings-form" method="post" action="{{ route($linked ? 'account.social.disconnect' : 'account.social.connect', $provider) }}">
        @csrf
        @if($linked) @method('DELETE') @endif
        <p><strong>{{ $label }}</strong> · {{ $linked ? 'Connected' : 'Available' }}</p>
        <label for="{{ $provider }}-link-password">Current password</label>
        <input id="{{ $provider }}-link-password" name="current_password" type="password" autocomplete="current-password" required>
        <button class="btn subtle" type="submit">{{ $linked ? 'Disconnect' : 'Connect' }} {{ $label }}</button>
      </form>
    @endforeach
  </section>
@endif
