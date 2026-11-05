<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title','Bambodia') · Licensed Digital Marketplace</title>
  <meta name="description" content="Discover licensed digital products, independent creators, and freelance services on Bambodia.">
  <script>document.documentElement.dataset.theme = localStorage.getItem('bambodia-theme') || @json(\App\Models\SiteSetting::setting('site.default_theme'));</script>
  @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="site-body">
<header class="site-header">
  <div class="site-nav container">
    <a class="brand" href="{{ route('home') }}"><span class="brand-mark">B</span><span>Bambodia</span></a>
    @if(\App\Models\FeatureFlag::isEnabled('marketplace_search'))
    @php
      $categoryGroups = \App\Models\MarketplaceCategory::query()
        ->where('is_active', true)
        ->orderBy('type')->orderBy('sort_order')->orderBy('name')
        ->get(['public_id','type','name'])->groupBy('type');
      $categoryTypes = ['product'=>'Product','project'=>'Project','service'=>'Service','freelance'=>'Freelance'];
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
            @php($items = $categoryGroups->get($categoryType, collect()))
            <section class="nav-category-group" aria-label="{{ $categoryLabel }} categories">
              <a class="nav-category-heading" href="{{ route('marketplace.search',['type'=>$categoryType]) }}">
                <x-icon :name="match($categoryType) {'product'=>'package','project'=>'layers','service'=>'wrench',default=>'briefcase'}" size="18"/>
                <span>{{ $categoryLabel }}</span>
                <x-icon name="chevron-right" size="15"/>
              </a>
              @forelse($items as $category)
                <a class="nav-category-entry" href="{{ route('marketplace.search',['type'=>$categoryType,'category'=>$category->public_id]) }}">
                  {{ $category->name }}
                </a>
              @empty
                <span class="nav-category-empty">No active categories yet</span>
              @endforelse
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
    <div class="nav-actions">
      @if(\App\Models\FeatureFlag::isEnabled('marketplace_search'))
        <nav class="nav-marketplace-links" aria-label="Marketplace">
          <a href="{{ route('marketplace.search',['type'=>'product']) }}">Product</a>
          <a href="{{ route('marketplace.search',['type'=>'project']) }}">Project</a>
          <a href="{{ route('marketplace.search',['type'=>'service']) }}">Service</a>
          <a href="{{ route('marketplace.search',['type'=>'freelance']) }}">Freelance</a>
        </nav>
      @endif
      @auth
      <details class="dropdown profile-dropdown">
        <summary class="profile-trigger" aria-label="Profile menu"><x-icon name="user" size="19"/></summary>
        <div class="dropdown-panel profile-panel">
          <strong>{{ auth()->user()->name }}</strong>
          <span class="text-muted">ID {{ auth()->user()->public_id }}</span>
          <a href="{{ url('/account?='.auth()->user()->public_id) }}">{{ __('ui.my_account') }}</a>
          <a href="{{ route('account.downloads') }}"><x-icon name="download" size="16"/> My Downloads</a>
          <a href="{{ route('account.saved-listings') }}"><x-icon name="star" size="16"/> Saved Listings</a>
          <a href="{{ route('account.saved-jobs') }}"><x-icon name="briefcase" size="16"/> Saved Jobs</a>
          <a href="{{ route('account.notifications') }}"><x-icon name="mail" size="16"/> Notifications</a>
          <a href="{{ route('account.policy-receipts') }}"><x-icon name="book" size="16"/> My Policy Receipts</a>
          <a href="{{ route('privacy.index') }}"><x-icon name="shield" size="16"/> Privacy Requests</a>
          <a href="{{ route('contracts.index') }}"><x-icon name="briefcase" size="16"/> My Contracts</a>
          @if(in_array(auth()->user()->role,['customer','subscriber'],true))<a href="{{ route('account.inquiries') }}">My Service Requests</a>@endif
          @if(auth()->user()->isCreator())<a href="{{ route('studio.inquiries') }}">Creator Inquiries</a>@endif
          @if(auth()->user()->role==='freelancer')<a href="{{ route('resume.edit') }}">Resume Builder</a><a href="{{ route('jobs.applied') }}">My Proposals</a>@endif
          @if(in_array(auth()->user()->role,['customer','subscriber','super_admin'],true))<a href="{{ route('jobs.mine') }}">My Jobs</a>@endif
          @if(auth()->user()->isCreator())<a href="{{ route('studio.profile.edit') }}"><x-icon name="user" size="16"/> Creator Profile</a><a href="{{ url('/studio?='.auth()->user()->public_id) }}">{{ __('ui.creator_studio') }}</a>@elseif(in_array(auth()->user()->role, ['customer','subscriber'], true))<a href="{{ route('creator.apply') }}">Become a creator</a>@endif
          @if(auth()->user()->isAdministrator())<a href="{{ route('dashboard') }}">{{ __('ui.administration') }}</a>@endif
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
          <a href="{{ route('login') }}">{{ __('ui.sign_in') }}</a>
          @if(\App\Models\FeatureFlag::isEnabled('registration'))<a href="{{ route('register') }}">{{ __('ui.join') }}</a>@endif
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
    <div><div class="brand footer-brand"><span class="brand-mark">B</span><span>Bambodia</span></div>
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
  <div class="container footer-bottom"><span>© {{ date('Y') }} Bambodia · All Rights Reserved.</span>
    <div class="footer-actions">
      <div class="footer-social" aria-label="Social media">
        <a href="https://tonsamith.com" target="_blank" rel="noopener noreferrer" aria-label="Author website"><x-icon name="globe" size="18"/></a>
        <a href="https://github.com/bambodia168-sys/bambodialaravel" target="_blank" rel="noopener noreferrer" aria-label="Bambodia GitHub repository"><x-icon name="github" size="18"/></a>
      </div>
      <x-language-menu/>
    </div>
  </div>
</footer>
</body>
</html>
