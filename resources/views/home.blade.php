@extends('layouts.main')
@section('title','Home')
@section('content')
<section class="hero container marketplace-home-hero">
  <div class="eyebrow">{{ \App\Models\SiteSetting::setting('appearance.hero_eyebrow') }}</div>
  <h1>{{ app()->getLocale()==='en' ? \App\Models\SiteSetting::setting('appearance.hero_title_en') : __('ui.hero_title') }}</h1>
  <p>{{ app()->getLocale()==='en' ? \App\Models\SiteSetting::setting('appearance.hero_intro_en') : __('ui.hero_intro') }}</p>
  @if($searchEnabled && \App\Models\SiteSetting::enabled('appearance.hero_search_enabled'))
    <form class="hero-search" method="get" action="{{ route('marketplace.search') }}">
      <x-icon name="search" size="22"/>
      <input type="search" name="q" maxlength="120" aria-label="Search published listings" placeholder="Search digital products, creators and services">
      <button class="btn primary" type="submit">{{ __('ui.explore') }}</button>
    </form>
  @endif
  @if($searchEnabled && \App\Models\SiteSetting::enabled('appearance.hero_categories_enabled'))
    <div class="hero-links">
      <a href="{{ route('marketplace.search',['type'=>'product']) }}">{{ __('ui.digital_products') }} <small>{{ number_format($listingTotals['product']??0) }}</small></a>
      <a href="{{ route('marketplace.search',['type'=>'project']) }}">{{ __('ui.projects') }} <small>{{ number_format($listingTotals['project']??0) }}</small></a>
      <a href="{{ route('marketplace.search',['type'=>'service']) }}">{{ __('ui.services') }} <small>{{ number_format($listingTotals['service']??0) }}</small></a>
      <a href="{{ route('marketplace.search',['type'=>'freelance']) }}">{{ __('ui.freelance') }} <small>{{ number_format($listingTotals['freelance']??0) }}</small></a>
    </div>
  @endif
  <div class="hero-quick-actions">
    <a href="{{ route('jobs') }}"><x-icon name="briefcase" size="17"/> Find approved jobs</a>
    <a href="{{ route('creators.index') }}"><x-icon name="users" size="17"/> Explore creators</a>
    @auth<a href="{{ route('account.saved-listings') }}"><x-icon name="star" size="17"/> My saved listings</a>
      <a href="{{ route('account.saved-jobs') }}"><x-icon name="briefcase" size="17"/> My saved jobs</a>
    @endauth
  </div>
</section>

@if($searchEnabled)
<section class="container marketplace-type-directory" aria-labelledby="browse-by-type-heading">
  <div class="marketplace-section-heading">
    <div><span class="eyebrow">Explore Bambodia</span><h2 id="browse-by-type-heading">Find the work that fits</h2>
      <p>Browse real, approved marketplace listings by type.</p></div>
    <a class="btn subtle compact" href="{{ route('marketplace.search') }}">Explore all listings <x-icon name="chevron-right" size="16"/></a>
  </div>
  <div class="marketplace-type-directory-grid">
    @foreach([
      ['type'=>'product','title'=>'Digital products','icon'=>'package','intro'=>'Licensed digital creations and source files'],
      ['type'=>'project','title'=>'Projects','icon'=>'layers','intro'=>'Published project offerings and collaboration'],
      ['type'=>'service','title'=>'Services','icon'=>'settings','intro'=>'Professional services from independent creators'],
      ['type'=>'freelance','title'=>'Freelance','icon'=>'briefcase','intro'=>'Freelance offers and specialized expertise'],
    ] as $directory)
      <a class="marketplace-type-directory-tile" href="{{ route('marketplace.search',['type'=>$directory['type']]) }}">
        <span class="marketplace-directory-icon"><x-icon :name="$directory['icon']" size="23"/></span>
        <strong>{{ $directory['title'] }}</strong>
        <small>{{ $directory['intro'] }}</small>
        <span class="marketplace-directory-footer">
          <span>{{ number_format((int)($listingTotals[$directory['type']] ?? 0)) }} approved</span>
          <x-icon name="chevron-right" size="17"/>
        </span>
      </a>
    @endforeach
  </div>
</section>
@endif

@if($searchEnabled)
<section class="container marketplace-section">
  <div class="marketplace-section-heading">
    <div><span class="eyebrow">Discover new work</span><h2>Recently published listings</h2><p>Current approved items from actual creator accounts.</p></div>
    <a class="btn subtle" href="{{ route('marketplace.search') }}">Browse all listings</a>
  </div>
  @if($recentListings->isNotEmpty())
    <div class="marketplace-card-grid marketplace-home-feed">
      @foreach($recentListings as $listing)
        <x-listing-card :listing="$listing" :is-saved="in_array((int)$listing->id,$savedIds,true)"/>
      @endforeach
    </div>
  @else
    <div class="marketplace-empty"><x-icon name="package" size="32"/><strong>Listings will appear here after approval.</strong>
      <p>No publicly approved marketplace items are currently available.</p>
    </div>
  @endif
</section>
<section id="bambodia-freelancers" class="container marketplace-section marketplace-freelancer-section" aria-labelledby="freelancer-section-title">
  <div class="marketplace-section-heading">
    <div>
      <span class="eyebrow">Discover independent talent</span>
      <h2 id="freelancer-section-title">Freelancer</h2>
      <p>Meet professionals with public portfolios and explore their approved work.</p>
    </div>
    <a class="btn subtle" href="{{ route('creators.index') }}">View all freelancers <x-icon name="arrow-up-right" size="17"/></a>
  </div>
  @if($freelancers->isNotEmpty())
    <div class="marketplace-freelancer-grid" aria-label="Public freelancer profiles">
      @foreach($freelancers as $freelancer)
        <article class="marketplace-freelancer-card">
          <div class="marketplace-freelancer-card-heading">
            <span class="marketplace-freelancer-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($freelancer->name,0,1)) }}</span>
            <span class="marketplace-freelancer-visibility"><x-icon name="user-round" size="14"/> Public profile</span>
          </div>
          <h3><a href="{{ route('creators.show',$freelancer->public_id) }}">{{ $freelancer->name }}</a></h3>
          <p class="marketplace-freelancer-headline">{{ $freelancer->creatorProfile->headline }}</p>
          <p class="marketplace-freelancer-bio">{{ \Illuminate\Support\Str::limit($freelancer->creatorProfile->biography,135) }}</p>
          <div class="marketplace-freelancer-card-footer">
            <span><x-icon name="briefcase" size="15"/> {{ number_format($freelancer->public_listings_count) }} approved {{ \Illuminate\Support\Str::plural('listing',$freelancer->public_listings_count) }}</span>
            <a href="{{ route('creators.show',$freelancer->public_id) }}">View portfolio <x-icon name="arrow-up-right" size="16"/></a>
          </div>
        </article>
      @endforeach
    </div>
  @else
    <div class="marketplace-empty marketplace-freelancer-empty">
      <x-icon name="users" size="30"/>
      <strong>Freelancer profiles are coming soon.</strong>
      <p>Public portfolios will appear here when freelancers choose to publish them.</p>
      <a class="btn subtle compact" href="{{ route('creators.index') }}">Browse the freelancer directory</a>
    </div>
  @endif
</section>
@endif

<section id="bambodia-jobs" class="container marketplace-section bambodia-jobs-section" aria-labelledby="bambodia-jobs-title">
  <div class="marketplace-section-heading">
    <div><span class="eyebrow">Work opportunities</span><h2 id="bambodia-jobs-title">Latest approved jobs</h2><p>Explore approved client opportunities and find work that fits your skills.</p></div>
    <a class="btn subtle" href="{{ route('jobs') }}">Explore all jobs <x-icon name="arrow-up-right" size="17"/></a>
  </div>
  @if($recentJobs->isNotEmpty())
    <div class="bambodia-job-list" aria-label="Latest approved job opportunities">
      @foreach($recentJobs as $job)
        <x-job-list-row :job="$job" :is-saved="in_array((int)$job->id,$savedJobIds,true)"/>
      @endforeach
    </div>
  @else
    <div class="marketplace-empty"><x-icon name="briefcase" size="28"/><strong>No approved jobs yet</strong><p>New job opportunities will appear here after review and publication.</p><a class="btn subtle compact" href="{{ route('jobs') }}">Browse jobs</a></div>
  @endif
</section>

<section id="bambodia-join" class="container bambodia-join-section" aria-labelledby="bambodia-join-title">
  <div class="bambodia-join-panel">
    <div class="bambodia-join-content">
      <span class="bambodia-join-kicker">Start your journey</span>
      <h2 id="bambodia-join-title">Ready to grow your business or freelance career in Cambodia?</h2>
      <p>Discover approved opportunities, share your professional work, and manage your activity through Bambodia.</p>
    </div>
    <div class="bambodia-join-actions">
      @auth
        <a class="bambodia-join-button bambodia-join-button--primary" href="{{ route('account') }}">My Account <x-icon name="arrow-up-right" size="18"/></a>
        <a class="bambodia-join-button bambodia-join-button--secondary" href="{{ route('jobs') }}">Find Jobs <x-icon name="arrow-up-right" size="18"/></a>
      @else
        <a class="bambodia-join-button bambodia-join-button--primary" href="{{ route('register') }}">Join Free <x-icon name="arrow-up-right" size="18"/></a>
        <a class="bambodia-join-button bambodia-join-button--secondary" href="{{ route('login') }}">Sign In <x-icon name="arrow-up-right" size="18"/></a>
      @endauth
    </div>
  </div>
</section>

<section id="bambodia-advantage" class="bambodia-advantage-section" aria-labelledby="bambodia-advantage-title">
  <div class="container bambodia-advantage-inner">
    <header class="bambodia-advantage-header">
      <span class="bambodia-advantage-kicker">THE BAMBODIA ADVANTAGE</span>
      <h2 id="bambodia-advantage-title">Built for Cambodia's growing digital marketplace</h2>
      <p>Discover a practical space for creators, freelancers, and clients, with clear publishing and account workflows.</p>
    </header>
    <div class="bambodia-advantage-grid">
      <article class="bambodia-advantage-card">
        <span class="bambodia-advantage-icon bambodia-advantage-icon--rose" aria-hidden="true"><x-icon name="clipboard-check" size="28"/></span>
        <h3>Reviewed Marketplace Listings</h3>
        <p>Products, projects, services, and job briefs are reviewed before appearing in public marketplace results.</p>
      </article>
      <article class="bambodia-advantage-card">
        <span class="bambodia-advantage-icon bambodia-advantage-icon--green" aria-hidden="true"><x-icon name="users" size="28"/></span>
        <h3>Public Creator Portfolios</h3>
        <p>Creators can choose to publish a profile, introduce their expertise, and show approved work to potential clients.</p>
      </article>
      <article class="bambodia-advantage-card">
        <span class="bambodia-advantage-icon bambodia-advantage-icon--gold" aria-hidden="true"><x-icon name="shield" size="28"/></span>
        <h3>Controlled Job Proposals</h3>
        <p>Freelancers with a published resume can apply to approved jobs through role-based, account-managed proposal workflows.</p>
      </article>
    </div>
    <div class="bambodia-advantage-footer">
      <p>BUILT AROUND YOUR DIGITAL WORK</p>
      <div class="bambodia-advantage-capabilities" aria-label="Bambodia marketplace capabilities">
        <span><x-icon name="package" size="18"/> Digital Products</span>
        <span><x-icon name="users" size="18"/> Creator Portfolios</span>
        <span><x-icon name="briefcase" size="18"/> Approved Jobs</span>
        <span><x-icon name="shield" size="18"/> Account Workspaces</span>
      </div>
    </div>
  </div>
</section>
@endsection
