@extends('layouts.workspace')
@section('workspace','admin')
@section('title','Social Auth')
@push('head')
<link rel="stylesheet" href="{{ asset('css/system-configuration.css') }}?v=4">
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
      @php
        $setting = $configs->get($provider);
        $dialogId = 'configure-social-'.$provider;
        $validation = $errors->getBag('social-auth-'.$provider);
        $clientId = $validation->any() ? old('client_id') : $setting?->client_id;
      @endphp
      <div class="system-setting-row">
        <div><h2>{{ $label }} Login</h2><p>{{ $setting ? 'Client credentials saved' : 'Setup required' }}</p></div>
        <div class="system-setting-actions">
          @include('dashboard.system-toggle', [
            'action' => route('dashboard.social-auth.status', $provider),
            'enabled' => (bool) $setting?->enabled,
            'unavailable' => !$setting,
            'label' => $label.' Login',
          ])
          <button class="icon-button" type="button" data-modal-open="{{ $dialogId }}" aria-haspopup="dialog"
            aria-controls="{{ $dialogId }}" aria-label="Configure {{ $label }}" title="Configure {{ $label }}">
            <x-icon name="settings" size="18"/>
          </button>
        </div>
      </div>
      <dialog class="config-dialog social-auth-dialog" id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title">
        <header class="social-auth-dialog-header">
          <div>
            <h2 id="{{ $dialogId }}-title">Configure {{ $label }}</h2>
            <p>Enter the OAuth credentials from your {{ $label }} application.</p>
          </div>
          <button class="icon-button" type="button" data-modal-close aria-label="Close {{ $label }} configuration">
            <x-icon name="x" size="18"/>
          </button>
        </header>
        <form method="post" action="{{ route('dashboard.social-auth.configure', $provider) }}">
          @csrf @method('PUT')
          <div class="social-auth-callback">
            <div class="social-auth-callback-heading">
              <strong>Callback URL</strong>
              <button type="button" class="btn subtle compact" data-copy-target="{{ $dialogId }}-callback" aria-live="polite">Copy URL</button>
            </div>
            <code id="{{ $dialogId }}-callback">{{ \App\Models\SocialLoginProvider::callbackUrl($provider) }}</code>
            <p>Register this exact URL in your provider’s OAuth settings.</p>
          </div>
          <label for="{{ $dialogId }}-client-id">Client ID</label>
          <input id="{{ $dialogId }}-client-id" name="client_id" value="{{ is_string($clientId) ? $clientId : '' }}" maxlength="255" required
            @if($validation->has('client_id')) aria-invalid="true" aria-describedby="{{ $dialogId }}-id-error" @endif>
          @if($validation->has('client_id'))
            <p id="{{ $dialogId }}-id-error" class="social-auth-field-error">{{ $validation->first('client_id') }}</p>
          @endif
          <label for="{{ $dialogId }}-client-secret">Client secret</label>
          <input id="{{ $dialogId }}-client-secret" name="client_secret" type="password" autocomplete="new-password" maxlength="1024"
            @required(!$setting) @if($validation->has('client_secret')) aria-invalid="true" aria-describedby="{{ $dialogId }}-secret-error" @endif>
          @if($validation->has('client_secret'))
            <p id="{{ $dialogId }}-secret-error" class="social-auth-field-error">{{ $validation->first('client_secret') }}</p>
          @endif
          @if($setting)<p class="social-auth-secret-note">Leave the secret blank to keep it. After changing credentials, turn {{ $label }} Login back on.</p>@endif
          <div class="dialog-actions">
            <button type="button" class="btn subtle" data-modal-close>Cancel</button>
            <button type="submit" class="btn primary">Save credentials</button>
          </div>
        </form>
      </dialog>
      @if($validation->any())
        <script>
          (() => {
            const dialog = document.getElementById(@json($dialogId));
            dialog.showModal();
            dialog.querySelector('[aria-invalid="true"]')?.focus();
          })();
        </script>
      @endif
    @endforeach
  </section>
</div>
@endsection
