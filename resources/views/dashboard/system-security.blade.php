@extends('layouts.workspace')
@section('workspace','admin')
@section('title','Security')
@push('head')
<link rel="stylesheet" href="{{ asset('css/system-configuration.css') }}">
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
      <div class="system-setting-row">
        <div><h2>{{ $label }}</h2><p>{{ $description }}</p></div>
        @include('dashboard.system-toggle', ['action' => route('dashboard.system-security.update', $key), 'enabled' => $states[$key]])
      </div>
    @endforeach
  </section>
  <p class="system-setting-note">Browser controls limit ordinary interactions on public content. Account and download access are enforced separately.</p>
</div>
@endsection
