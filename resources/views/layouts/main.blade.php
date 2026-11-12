<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  @include('layouts.partials.site-brand-head')
  @include('layouts.partials.site-seo-head')
  <script>document.documentElement.dataset.theme = localStorage.getItem('bambodia-theme') || @json(\App\Models\SiteSetting::setting('site.default_theme'));</script>
  @vite(['resources/css/app.css','resources/js/app.js'])
  @include('layouts.partials.site-typography')
  <link rel="stylesheet" href="{{ asset('css/header-flag-scroll-top.css') }}?v=1">
  {{-- Badge tips are portalled to a viewport-safe layer without changing their artwork or layout. --}}
  <link rel="stylesheet" href="{{ asset('css/creator-badge-tooltip-viewport.css') }}?v=1">
  <script defer src="{{ asset('js/creator-badge-tooltip-viewport.js') }}?v=1"></script>
  @if(request()->routeIs('creators.show','account.profile.edit','studio.profile.edit','dashboard.users.profile.edit','localized.*.creators.show','localized.*.account.profile.edit','localized.*.studio.profile.edit','localized.*.dashboard.users.profile.edit'))
    <link rel="stylesheet" href="{{ asset('css/creator-profile-v3.css') }}?v=2">
    <script defer src="{{ asset('js/creator-profile-v3.js') }}?v=2"></script>
  @endif
</head>
<body class="site-body">
<x-flash-feedback :validation-errors="$errors->all()" />
<header class="site-header">
  <div class="site-nav container">
    <a class="brand" href="{{ route('home') }}" aria-label="Bambodia home">
      <span class="brand-logo-pair">
        <img class="brand-logo-light" src="{{ \App\Models\SiteBrandAsset::urlFor('logo_light') }}" alt="{{ \App\Models\SiteSetting::setting('identity.name') }}" width="550" height="144" decoding="async">
        <img class="brand-logo-dark" src="{{ \App\Models\SiteBrandAsset::urlFor('logo_dark') }}" alt="{{ \App\Models\SiteSetting::setting('identity.name') }}" width="550" height="144" decoding="async">
      </span>
    </a>
    @if(\App\Models\FeatureFlag::isEnabled('marketplace_search'))
    @php
      $categoryGroups = \App\Models\MarketplaceCategory::query()
        ->where('is_active', true)
        ->orderBy('type')->orderBy('sort_order')->orderBy('name')
        ->get(['public_id','type','name'])->groupBy('type');
      $categoryTypes = ['product'=>'Product','project'=>'Project','service'=>'Service','freelance'=>'Freelance'];
      $categoryIcons = ['product'=>'package','project'=>'layers','service'=>'wrench','freelance'=>'briefcase'];
    @endphp
    <details class="dropdown nav-category-menu" data-public-category-menu>
      <summary class="nav-category-trigger" aria-label="Browse marketplace categories" title="Browse categories">
        <x-icon name="grid" size="23"/>
        <span class="sr-only">Categories</span>
      </summary>
      <div class="dropdown-panel nav-category-panel">
        <div class="nav-category-intro">
          <strong>Browse categories</strong>
          <span>Discover products, projects, services and freelance work</span>
        </div>
        <nav class="nav-category-groups" aria-label="Marketplace categories">
          @foreach($categoryTypes as $categoryType => $categoryLabel)
            <section class="nav-category-group" aria-label="{{ $categoryLabel }} categories">
              <a class="nav-category-heading" href="{{ route('marketplace.search',['type'=>$categoryType]) }}">
                <x-icon :name="$categoryIcons[$categoryType]" size="18"/>
                <span>{{ $categoryLabel }}</span>
                <x-icon name="chevron-right" size="15"/>
              </a>
              @if($categoryGroups->has($categoryType))
              @foreach($categoryGroups->get($categoryType) as $category)
                <a class="nav-category-entry" href="{{ route('marketplace.search',['type'=>$categoryType,'category'=>$category->public_id]) }}">
                  {{ $category->name }}
                </a>
              @endforeach
              @else
                <span class="nav-category-empty">No active categories yet</span>
              @endif
            </section>
          @endforeach
        </nav>
        <a class="nav-category-explore" href="{{ route('marketplace.search') }}">Explore all listings <x-icon name="chevron-right" size="16"/></a>
      </div>
    </details>
    @endif
    @if(\App\Models\FeatureFlag::isEnabled('marketplace_search'))
    <form class="nav-search" action="{{ url('/search') }}" method="get">
      <x-icon name="search" size="19"/>
      <input type="search" name="q" value="{{ request('q') }}" aria-label="Search marketplace" placeholder="{{ __('ui.search_placeholder') }}">
      <button type="submit">{{ __('ui.search') }}</button>
    </form>
    @endif
    @if(\App\Models\FeatureFlag::isEnabled('marketplace_search'))
    <details class="dropdown nav-mobile-search" data-public-mobile-search>
      <summary class="nav-mobile-search-trigger" aria-label="Open marketplace search" title="Search marketplace">
        <x-icon name="search" size="21"/>
        <span class="sr-only">Search</span>
      </summary>
      <div class="nav-mobile-search-panel">
        <form action="{{ url('/search') }}" method="get" role="search" class="nav-mobile-search-form">
          <label class="sr-only" for="mobile-marketplace-query">Search products, projects and services</label>
          <x-icon name="search" size="19"/>
          <input id="mobile-marketplace-query" type="search" name="q" value="{{ request('q') }}"
            autocomplete="off" placeholder="{{ __('ui.search_placeholder') }}">
          <button type="submit">{{ __('ui.search') }}</button>
        </form>
      </div>
    </details>
    @endif
    <div class="nav-actions">
      <x-language-menu class="nav-language-menu header-flag-only"/>
      @auth
      <details class="dropdown profile-dropdown">
        <summary class="profile-trigger" aria-label="Profile menu"><x-icon name="user" size="19"/></summary>
        <div class="dropdown-panel profile-panel">
          <strong>{{ auth()->user()->name }}</strong>
          <span class="text-muted">ID {{ auth()->user()->public_id }}</span>
          <a href="{{ url('/account?='.auth()->user()->public_id) }}"><x-icon name="user" size="16"/> {{ __('ui.my_account') }}</a>
          <a href="{{ route('account.downloads') }}"><x-icon name="download" size="16"/> My Downloads</a>
          <a href="{{ route('account.saved-listings') }}"><x-icon name="star" size="16"/> Saved Listings</a>
          <a href="{{ route('account.saved-jobs') }}"><x-icon name="briefcase" size="16"/> Saved Jobs</a>
          <a href="{{ route('account.notifications') }}"><x-icon name="mail" size="16"/> Notifications</a>
          <a href="{{ route('account.policy-receipts') }}"><x-icon name="book" size="16"/> My Policy Receipts</a>
          <a href="{{ route('privacy.index') }}"><x-icon name="shield" size="16"/> Privacy Requests</a>
          <a href="{{ route('contracts.index') }}"><x-icon name="briefcase" size="16"/> My Contracts</a>
          @if(in_array(auth()->user()->role,['customer','subscriber'],true))<a href="{{ route('account.inquiries') }}"><x-icon name="message" size="16"/> My Service Requests</a>@endif
          @if(auth()->user()->isCreator())<a href="{{ route('studio.inquiries') }}"><x-icon name="message" size="16"/> Creator Inquiries</a>@endif
          @if(auth()->user()->role==='freelancer')<a href="{{ route('resume.edit') }}"><x-icon name="file-text" size="16"/> Resume Builder</a><a href="{{ route('jobs.applied') }}"><x-icon name="send" size="16"/> My Proposals</a>@endif
          @if(in_array(auth()->user()->role,['customer','subscriber','super_admin'],true))<a href="{{ route('jobs.mine') }}"><x-icon name="briefcase" size="16"/> My Jobs</a>@endif
          @if(auth()->user()->isCreator())<a href="{{ route('studio.profile.edit') }}"><x-icon name="user" size="16"/> Creator Profile</a><a href="{{ url('/studio?='.auth()->user()->public_id) }}"><x-icon name="palette" size="16"/> {{ __('ui.creator_studio') }}</a>@elseif(in_array(auth()->user()->role, ['customer','subscriber'], true))<a href="{{ route('creator.apply') }}"><x-icon name="user-round-check" size="16"/> Become a creator</a>@endif
          @if(auth()->user()->isAdministrator())<a href="{{ route('dashboard') }}"><x-icon name="layout-dashboard" size="16"/> {{ __('ui.administration') }}</a>@endif
          <form action="{{ route('logout') }}" method="post">@csrf<button type="submit"><x-icon name="logout" size="16"/>{{ __('ui.sign_out') }}</button></form>
          <div class="profile-theme-row">
            <button type="button" class="profile-theme-toggle" data-theme-toggle>
              <span class="theme-icon-moon"><x-icon name="moon" size="17"/></span>
              <span class="theme-icon-sun"><x-icon name="sun" size="17"/></span>
              <span data-theme-label>Switch theme</span>
            </button>
          </div>
        </div>
      </details>
      @else
      <details class="dropdown profile-dropdown">
        <summary class="profile-trigger" aria-label="Account menu"><x-icon name="user" size="19"/></summary>
        <div class="dropdown-panel profile-panel">
          <a href="{{ route('login') }}"><x-icon name="log-in" size="16"/> {{ __('ui.sign_in') }}</a>
          @if(\App\Models\FeatureFlag::isEnabled('registration'))<a href="{{ route('register') }}"><x-icon name="user-plus" size="16"/> {{ __('ui.join') }}</a>@endif
          <div class="profile-theme-row">
            <button type="button" class="profile-theme-toggle" data-theme-toggle>
              <span class="theme-icon-moon"><x-icon name="moon" size="17"/></span>
              <span class="theme-icon-sun"><x-icon name="sun" size="17"/></span>
              <span data-theme-label>Switch theme</span>
            </button>
          </div>
        </div>
      </details>
      @endauth
    </div>
  </div>
</header>
<main class="site-main">@yield('content')</main>
@php
  $footerPolicies = \App\Models\Policy::query()->where('status', 'published')
      ->whereIn('category', ['cookies', 'gdpr', 'privacy', 'terms'])
      ->orderByDesc('updated_at')->get()->unique('category')->keyBy('category');
  $aboutPage = \App\Models\ContentEntry::query()->where('status', 'published')
      ->where('type', 'page')->where('slug', 'about')->latest()->first();
@endphp
<footer class="site-footer">
  <div class="container footer-columns">
    <div>
      <a class="brand footer-brand" href="{{ route('home') }}" aria-label="Bambodia home">
        <span class="brand-logo-pair">
          <img class="brand-logo-light" src="{{ \App\Models\SiteBrandAsset::urlFor('logo_light') }}" alt="{{ \App\Models\SiteSetting::setting('identity.name') }}" width="550" height="144" loading="lazy" decoding="async">
          <img class="brand-logo-dark" src="{{ \App\Models\SiteBrandAsset::urlFor('logo_dark') }}" alt="{{ \App\Models\SiteSetting::setting('identity.name') }}" width="550" height="144" loading="lazy" decoding="async">
        </span>
      </a>
      @if($aboutPage)<a href="{{ $aboutPage->publicUrl() }}">About</a>@endif
      <a href="https://tonsamith.com" target="_blank" rel="noopener noreferrer">Author</a>
      @if(Route::has('blog'))<a href="{{ route('blog') }}">Blog</a>@endif
      <a href="{{ route('creators.index') }}">Browse Creators</a>
      @if(Route::has('contact'))<a href="{{ route('contact') }}">Contact</a>@endif
    </div>
    <div><h3>{{ __('ui.marketplace') }}</h3>@if(\App\Models\FeatureFlag::isEnabled('marketplace_search'))<a href="{{ url('/search?type=project') }}">{{ __('ui.find_projects') }}</a><a href="{{ url('/search?type=product') }}">{{ __('ui.find_products') }}</a><a href="{{ url('/search?type=freelance') }}">{{ __('ui.find_freelancers') }}</a><a href="{{ url('/search?type=service') }}">{{ __('ui.find_services') }}</a>@endif @if(Route::has('jobs'))<a href="{{ route('jobs') }}">Find Jobs</a>@endif</div>
    <div><h3>{{ __('ui.for_users') }}</h3>@auth<a href="{{ url('/account?='.auth()->user()->public_id) }}">Client Account</a>@else @if(\App\Models\FeatureFlag::isEnabled('registration'))<a href="{{ route('register') }}">Client Account</a>@endif @endauth
      @if(auth()->check() && auth()->user()->isCreator())<a href="{{ url('/studio?='.auth()->user()->public_id) }}">Creator Account</a>@else @if(\App\Models\FeatureFlag::isEnabled('registration'))<a href="{{ route('register') }}">Creator Account</a>@endif @endif
      @if(Route::has('policy-center'))<a href="{{ route('policy-center') }}">Policy Center</a>@endif
      @if(Route::has('support'))<a href="{{ route('support') }}">Support Center</a>@endif
      @if(\App\Models\SiteSetting::setting('site.support_email')!=='')
        <a href="mailto:{{ \App\Models\SiteSetting::setting('site.support_email') }}">Email support</a>
      @endif
    </div>
    <div><h3>{{ __('ui.compliance') }}</h3>
      @if($footerPolicies->has('cookies'))<a href="{{ $footerPolicies->get('cookies')->publicUrl() }}">Cookie Policy</a>@endif
      @if($footerPolicies->has('gdpr'))<a href="{{ $footerPolicies->get('gdpr')->publicUrl() }}">GDPR Policy</a>@endif
      @if($footerPolicies->has('privacy'))<a href="{{ $footerPolicies->get('privacy')->publicUrl() }}">Privacy Policy</a>@endif
      @if($footerPolicies->has('terms'))<a href="{{ $footerPolicies->get('terms')->publicUrl() }}">Terms of Service</a>@endif
    </div>
  </div>
  <div class="container footer-bottom"><span class="footer-copyright">© {{ date('Y') }} {{ \App\Models\SiteSetting::setting('identity.name') }} · All Rights Reserved.</span>
    <div class="footer-actions">
      <div class="footer-social" aria-label="Social media">
        @foreach(\App\Models\SiteSocialLink::query()->where('is_active',true)->orderBy('sort_order')->orderBy('id')->get() as $social)
          <a href="{{ $social->url }}" target="_blank" rel="noopener noreferrer nofollow"
            aria-label="{{ $social->label }}" title="{{ $social->label }}">
            <x-icon :name="$social->iconName()" size="18"/>
          </a>
        @endforeach
      </div>
    </div>
  </div>
</footer>
<button type="button" class="back-to-top" data-back-to-top aria-label="Back to top" title="Back to top">
  <x-icon name="arrow-up" size="22"/>
</button>
</body>
</html>
