<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  @include('layouts.partials.site-brand-head')
  <title>@yield('title') · {{ \App\Models\SiteSetting::setting('identity.name') }}</title>
  <meta name="robots" content="noindex,nofollow">
  <script>document.documentElement.dataset.theme = localStorage.getItem('bambodia-theme') || @json(\App\Models\SiteSetting::setting('site.default_theme'));</script>
  @vite(['resources/css/app.css','resources/js/app.js'])
  <link rel="stylesheet" href="{{ asset('css/auth-tools.css') }}">
  @include('layouts.partials.site-typography')
  @stack('head')
</head>
<body class="auth-body">
<x-flash-feedback :validation-errors="$errors->all()" />
<main class="auth-shell">
  <section class="auth-card" aria-labelledby="auth-title">
    <div class="auth-card-head">
      <div class="eyebrow">Your marketplace account</div>
      <h1 id="auth-title">@yield('heading')</h1>
      <p>@yield('intro')</p>
    </div>
    @yield('content')
    <nav class="auth-tools" aria-label="Account page controls">
      <a class="auth-home-tool auth-tool-icon" href="{{ route('home') }}" aria-label="{{ __('ui.home') }}" title="{{ __('ui.home') }}">
        <x-icon name="home" size="20"/>
      </a>
      <button type="button" class="auth-theme-tool auth-tool-icon" data-theme-toggle aria-label="Toggle light or dark mode">
        <span class="theme-icon-moon"><x-icon name="moon" size="20"/></span>
        <span class="theme-icon-sun"><x-icon name="sun" size="20"/></span>
        <span class="sr-only">Toggle theme</span>
      </button>
      <x-language-menu :compact="true" />
    </nav>
  </section>
</main>
</body>
</html>
