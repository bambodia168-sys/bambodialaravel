@extends('layouts.workspace')
@section('workspace','admin')
@section('title','Social Auth')
@push('head')
<link rel="stylesheet" href="{{ asset('css/system-configuration.css') }}">
@endpush
@section('content')
<div class="container inner-page">
  <div class="page-intro">
    <div class="eyebrow">System Configuration</div>
    <h1>Social Auth</h1>
    <p>Set up sign-in providers for Bambodia accounts. Users connect a provider from Account Security.</p>
  </div>
  <section class="panel" aria-label="Sign-in providers">
    @foreach(\App\Models\SocialLoginProvider::PROVIDERS as $provider => [$label])
      @php($setting = $configs->get($provider))
      <div class="system-setting-row">
        <div><h2>{{ $label }} Login</h2><p>{{ $setting ? 'Client credentials saved' : 'Setup required' }}</p></div>
        @include('dashboard.system-toggle', [
          'action' => route('dashboard.social-auth.status', $provider),
          'enabled' => (bool) $setting?->enabled,
          'unavailable' => !$setting,
          'label' => $label.' Login',
        ])
      </div>
      <details class="system-provider-form">
        <summary>{{ $setting ? 'Edit credentials' : 'Configure '.$label }}</summary>
        <form method="post" action="{{ route('dashboard.social-auth.configure', $provider) }}" class="form-stack">
          @csrf @method('PUT')
          <label>Callback URL<input value="{{ \App\Models\SocialLoginProvider::callbackUrl($provider) }}" readonly></label>
          <label>Client ID<input name="client_id" value="{{ $setting?->client_id }}" maxlength="255" required></label>
          <label>Client secret
            <input name="client_secret" type="password" autocomplete="new-password" maxlength="1024" @required(!$setting)>
          </label>
          @if($setting)<p class="system-setting-note">Leave the secret blank to keep it. Changing credentials switches this provider off until you turn it on again.</p>@endif
          <button class="btn primary compact" type="submit">Save credentials</button>
        </form>
      </details>
    @endforeach
  </section>
</div>
@endsection
