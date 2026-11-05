<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title','Bambodia') · Licensed Digital Marketplace</title>
  <meta name="description" content="Discover licensed digital products, independent creators, and freelance services on Bambodia.">
  <script>document.documentElement.dataset.theme = localStorage.getItem('bambodia-theme') || 'light';</script>
  @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="site-body">
<header class="site-header">
  <div class="site-nav container">
    <a class="brand" href="{{ route('home') }}"><span class="brand-mark">B</span><span>Bambodia<span class="brand-dot">.</span></span></a>
    @if(\App\Models\FeatureFlag::isEnabled('marketplace_search'))
    <form class="nav-search" action="{{ url('/search') }}" method="get">
      <x-icon name="search" size="19"/>
      <input type="search" name="q" value="{{ request('q') }}" aria-label="Search marketplace" placeholder="Search products, projects and services">
      <button type="submit">Search</button>
    </form>
    @endif
    <div class="nav-actions">
      @if(\App\Models\FeatureFlag::isEnabled('marketplace_search'))
      <details class="dropdown mega">
        <summary>Categories</summary>
        <nav class="dropdown-panel mega-panel" aria-label="Browse categories">
          <a href="{{ url('/search?type=product') }}"><x-icon name="package"/>Digital products</a>
          <a href="{{ url('/search?type=project') }}"><x-icon name="layers"/>Projects</a>
          <a href="{{ url('/search?type=freelance') }}"><x-icon name="briefcase"/>Freelance</a>
          <a href="{{ url('/search?type=service') }}"><x-icon name="settings"/>Services</a>
        </nav>
      </details>
      @endif
      <button type="button" class="icon-button" data-theme-toggle aria-label="Toggle light or dark mode"><x-icon name="moon"/></button>
      @auth
      <details class="dropdown profile-dropdown">
        <summary class="profile-trigger" aria-label="Profile menu"><x-icon name="user" size="19"/></summary>
        <div class="dropdown-panel profile-panel">
          <strong>{{ auth()->user()->name }}</strong>
          <span class="text-muted">ID {{ auth()->user()->public_id }}</span>
          <a href="{{ url('/account?='.auth()->user()->public_id) }}">My account</a>
          @if(auth()->user()->isCreator())<a href="{{ url('/studio?='.auth()->user()->public_id) }}">Creator studio</a>@endif
          @if(auth()->user()->isAdministrator())<a href="{{ route('dashboard') }}">Administration</a>@endif
          <form action="{{ route('logout') }}" method="post">@csrf<button type="submit"><x-icon name="logout" size="16"/> Sign out</button></form>
        </div>
      </details>
      @else
      <a class="btn subtle compact" href="{{ route('login') }}">Sign in</a>
      @if(\App\Models\FeatureFlag::isEnabled('registration'))<a class="btn primary compact" href="{{ route('register') }}">Join Bambodia</a>@endif
      @endauth
    </div>
  </div>
</header>
<main class="site-main">@yield('content')</main>
<footer class="site-footer">
  <div class="container footer-columns">
    <div><div class="brand footer-brand"><span class="brand-mark">B</span><span>Bambodia<span class="brand-dot">.</span></span></div>
      @if(Route::has('about'))<a href="{{ route('about') }}">About</a>@endif
      @if(Route::has('author'))<a href="{{ route('author') }}">Author</a>@endif
      @if(Route::has('blog'))<a href="{{ route('blog') }}">Blog</a>@endif
      @if(Route::has('contact'))<a href="{{ route('contact') }}">Contact</a>@endif
    </div>
    <div><h3>Marketplace</h3>@if(\App\Models\FeatureFlag::isEnabled('marketplace_search'))<a href="{{ url('/search?type=project') }}">Find Projects</a><a href="{{ url('/search?type=product') }}">Find Products</a><a href="{{ url('/search?type=freelance') }}">Find Freelancers</a><a href="{{ url('/search?type=service') }}">Find Services</a>@endif @if(Route::has('jobs'))<a href="{{ route('jobs') }}">Find Jobs</a>@endif</div>
    <div><h3>For Users</h3>@auth<a href="{{ url('/account?='.auth()->user()->public_id) }}">Client Account</a>@else<a href="{{ route('register') }}">Client Account</a>@endauth
      @if(auth()->check() && auth()->user()->isCreator())<a href="{{ url('/studio?='.auth()->user()->public_id) }}">Creator Account</a>@else<a href="{{ route('register') }}">Creator Account</a>@endif
      @if(Route::has('policy-center'))<a href="{{ route('policy-center') }}">Policy Center</a>@endif
      @if(Route::has('support'))<a href="{{ route('support') }}">Support Center</a>@endif
    </div>
    <div><h3>Compliance</h3>
      @if(Route::has('policy.cookie'))<a href="{{ route('policy.cookie') }}">Cookie Policy</a>@endif
      @if(Route::has('policy.gdpr'))<a href="{{ route('policy.gdpr') }}">GDPR Policy</a>@endif
      @if(Route::has('policy.privacy'))<a href="{{ route('policy.privacy') }}">Privacy Policy</a>@endif
      @if(Route::has('policy.terms'))<a href="{{ route('policy.terms') }}">Terms of Service</a>@endif
    </div>
  </div>
  <div class="container footer-bottom"><span>© {{ date('Y') }} Bambodia · All Rights Reserved.</span><div class="footer-social"><a href="https://tonsamith.com" target="_blank" rel="noopener noreferrer" aria-label="Author website"><x-icon name="globe" size="18"/></a></div></div>
</footer>
</body>
</html>
