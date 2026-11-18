@php
  $passkeysEnabled = \App\Support\SystemSecurity::enabled('passkeys');
  $enabledProviders = \App\Models\SocialLoginProvider::enabledProviders();
@endphp
@if($passkeysEnabled || $enabledProviders)
  @push('head')
    <link rel="stylesheet" href="{{ asset('css/system-configuration.css') }}?v=5">
    @if($passkeysEnabled)<script defer src="{{ asset('js/passkeys.js') }}"></script>@endif
  @endpush
  <section class="sign-in-options" aria-labelledby="sign-in-options-title">
    <h2 class="sign-in-options-divider" id="sign-in-options-title">Other ways to sign in</h2>
    @if($passkeysEnabled)
      <form method="post" action="{{ route('login.passkeys.authenticate') }}" class="sign-in-passkey"
        data-passkey-form="authenticate" data-options="{{ route('login.passkeys.options') }}">
        @csrf
        <button class="btn subtle sign-in-passkey-button" type="submit">
          <x-icon name="key-round" size="18"/>
          <span>Sign in with a passkey</span>
        </button>
        <p role="status"></p>
      </form>
    @endif
    @if($enabledProviders)
      <div class="sign-in-providers" role="group" aria-label="Social sign-in">
        @foreach(\App\Models\SocialLoginProvider::PROVIDERS as $provider => [$label])
          @if(in_array($provider, $enabledProviders, true))
            <a class="btn sign-in-provider" href="{{ route('social.start', $provider) }}">
              <x-social-provider-icon :provider="$provider" />
              <span>{{ $label }}</span>
            </a>
          @endif
        @endforeach
      </div>
    @endif
  </section>
@endif
