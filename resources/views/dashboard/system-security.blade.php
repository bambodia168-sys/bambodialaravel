@extends('layouts.workspace')
@section('workspace','admin')
@section('title','Security')
@push('head')
<link rel="stylesheet" href="{{ asset('css/system-configuration.css') }}?v=2">
@endpush
@section('content')
<div class="container inner-page">
  <div class="page-intro">
    <div class="eyebrow">System Configuration</div>
    <h1>Security</h1>
    <p>Manage account sign-in controls and browser-level protection for public content.</p>
  </div>
  <section class="panel" aria-label="Security controls">
    @foreach(\App\Support\SystemSecurity::CONTROLS as $key => [$label, $description])
      @php
        $dialogId = 'configure-security-'.$key;
        $action = route('dashboard.system-security.update', $key);
        $enabled = $states[$key];
      @endphp
      <div class="system-setting-row">
        <div><h2>{{ $label }}</h2><p>{{ $description }}</p></div>
        <div class="system-setting-actions">
          @include('dashboard.system-toggle', ['action' => $action, 'enabled' => $enabled])
          <button class="icon-button" type="button" data-modal-open="{{ $dialogId }}" aria-haspopup="dialog"
            aria-controls="{{ $dialogId }}" aria-label="Configure {{ $label }}" title="Configure {{ $label }}">
            <x-icon name="settings" size="18"/>
          </button>
        </div>
      </div>
      <dialog class="config-dialog" id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title">
        <form method="post" action="{{ $action }}">
          @csrf @method('PATCH')
          <h2 id="{{ $dialogId }}-title">{{ $label }}</h2>
          @if($key === 'passkeys')
            <p>Enable passkeys here to let each user add a device passkey under Account Security.</p>
            <a class="btn subtle compact" href="{{ route('account.security') }}{{ $enabled ? '#account-passkeys' : '' }}">Open Account Security</a>
          @elseif($key === 'two_factor_enrollment')
            <p>Allow new enrollment here. Each user sets up their authenticator app and gets recovery codes under Account Security.</p>
            <a class="btn subtle compact" href="{{ route('account.security') }}#account-two-factor">Open Account Security</a>
          @else
            <p>{{ $description }} This setting applies to the main content area on public pages.</p>
          @endif
          <label for="{{ $dialogId }}-enabled">Status</label>
          <select id="{{ $dialogId }}-enabled" name="enabled">
            <option value="1" @selected($enabled)>On</option>
            <option value="0" @selected(!$enabled)>Off</option>
          </select>
          <div class="dialog-actions">
            <button class="btn subtle" type="button" data-modal-close>Cancel</button>
            <button class="btn primary" type="submit">Save</button>
          </div>
        </form>
      </dialog>
    @endforeach
  </section>
  <p class="system-setting-note">Browser controls limit ordinary interactions on public content. Account and download access are enforced separately.</p>
</div>
@endsection
