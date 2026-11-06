<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <link rel="icon" href="{{ asset('favicon.ico') }}" type="image/x-icon" sizes="any">
  <link rel="icon" href="{{ asset('favicon.png') }}" type="image/png" sizes="64x64">
  <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}" sizes="152x152">
  <title>@yield('title') · Bambodia</title>
  <script>document.documentElement.dataset.theme = localStorage.getItem('bambodia-theme') || @json(\App\Models\SiteSetting::setting('site.default_theme'));</script>
  @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="auth-body">
<main class="auth-shell">
  <section class="auth-card" aria-labelledby="auth-title">
    <div class="auth-card-head">
      <div class="eyebrow">Your marketplace account</div>
      <h1 id="auth-title">@yield('heading')</h1>
      <p>@yield('intro')</p>
    </div>
    @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
    @yield('content')
  </section>
  <nav class="auth-tools" aria-label="Account page controls">
    <a class="auth-home-tool" href="{{ route('home') }}" aria-label="{{ __('ui.home') }}">
      <x-icon name="home" size="18"/><span>{{ __('ui.home') }}</span>
    </a>
    <button type="button" class="auth-theme-tool" data-theme-toggle aria-label="Toggle light or dark mode">
      <span class="theme-icon-moon"><x-icon name="moon" size="18"/></span>
      <span class="theme-icon-sun"><x-icon name="sun" size="18"/></span>
      <span class="sr-only">Toggle theme</span>
    </button>
    <x-language-menu :compact="true" />
  </nav>
</main>
</body>
</html>
