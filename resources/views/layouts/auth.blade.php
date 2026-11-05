<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title') · Bambodia</title>
  <script>document.documentElement.dataset.theme = localStorage.getItem('bambodia-theme') || 'dark';</script>
  @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="auth-body">
<main class="auth-shell">
  <a href="{{ route('home') }}" class="auth-brand" aria-label="Bambodia homepage"><span class="brand-mark">B</span><span>Bambodia<span class="brand-dot">.</span></span></a>
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
  <div class="auth-bottom"><a href="{{ route('home') }}">Back to Bambodia</a><button type="button" class="icon-button" data-theme-toggle aria-label="Toggle light or dark mode"><x-icon name="moon"/></button></div>
</main>
</body>
</html>
