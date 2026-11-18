@php
  $passkeysEnabled = \App\Support\SystemSecurity::enabled('passkeys');
  $enabledProviders = \App\Models\SocialLoginProvider::enabledProviders();
@endphp
@if($passkeysEnabled || $enabledProviders)
  @push('head')
    <link rel="stylesheet" href="{{ asset('css/system-configuration.css') }}">
    @if($passkeysEnabled)<script defer src="{{ asset('js/passkeys.js') }}"></script>@endif
  @endpush
  @if($passkeysEnabled)
    <form method="post" action="{{ route('login.passkeys.authenticate') }}"
      class="system-auth-actions" data-passkey-form="authenticate" data-options="{{ route('login.passkeys.options') }}">
      @csrf
      <button class="btn subtle" type="submit"><x-icon name="key-round" size="17"/> Sign in with a passkey</button>
      <p role="status"></p>
    </form>
  @endif
  @if($enabledProviders)
    <div class="system-auth-actions" aria-label="Social sign-in">
      @foreach(\App\Models\SocialLoginProvider::PROVIDERS as $provider => [$label])
        @if(in_array($provider, $enabledProviders, true))
          <a class="btn subtle" href="{{ route('social.start', $provider) }}">Continue with {{ $label }}</a>
        @endif
      @endforeach
    </div>
  @endif
@endif
