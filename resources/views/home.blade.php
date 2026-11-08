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

<section class="container marketplace-section">
  <div class="marketplace-section-heading">
    <div><span class="eyebrow">Work opportunities</span><h2>Latest approved jobs</h2><p>Review client briefs and apply through your freelancer workspace.</p></div>
    <a class="btn subtle" href="{{ route('jobs') }}">Find more jobs</a>
  </div>
  @if($recentJobs->isNotEmpty())
    <div class="marketplace-jobs-grid">
      @foreach($recentJobs as $job)
        <article class="marketplace-job-card">
          <div class="eyebrow">{{ ucfirst($job->category) }} · {{ $job->public_id }}</div>
          <h3><a href="{{ $job->publicUrl() }}">{{ $job->title }}</a></h3>
          <p>{{ \Illuminate\Support\Str::limit($job->description,140) }}</p>
          <div class="marketplace-card-meta">
            <span>{{ $job->client?->name }}</span>
            <strong>{{ $job->budget===null?'Budget on request':'USD '.number_format((float)$job->budget,2) }}</strong>
          </div>
          <div class="marketplace-card-actions">
            <a href="{{ $job->publicUrl() }}" class="btn subtle compact">View opportunity</a>
            @auth
              @if(in_array((int)$job->id,$savedJobIds,true))
                <form method="post" action="{{ route('saved-jobs.destroy',$job) }}">@csrf @method('DELETE')
                  <button type="submit" class="btn subtle compact"><x-icon name="star" size="15"/> Saved</button>
                </form>
              @else
                <form method="post" action="{{ route('saved-jobs.store',$job) }}">@csrf
                  <button type="submit" class="btn subtle compact"><x-icon name="star" size="15"/> Save</button>
                </form>
              @endif
            @endauth
          </div>
        </article>
      @endforeach
    </div>
  @else
    <div class="marketplace-empty">There are no published job opportunities at the moment.</div>
  @endif
</section>

<section class="container marketplace-section marketplace-guidance">
  <div>
    <span class="eyebrow">Get started</span><h2>One platform, different workspaces</h2>
    <p>Find independent services, present your professional work, or manage approved projects within the account permissions assigned to you.</p>
  </div>
  <div class="toolbar-actions">
    @auth
      <a class="btn primary" href="{{ route('account') }}">Open my account</a>
      @if(auth()->user()->isCreator())<a class="btn subtle" href="{{ route('studio') }}">Creator Studio</a>@endif
    @else
      <a class="btn primary" href="{{ route('register') }}">Create an account</a>
      <a class="btn subtle" href="{{ route('login') }}">Sign in</a>
    @endauth
  </div>
</section>
@endsection
