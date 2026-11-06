@extends('layouts.main')
@section('title','Search Marketplace')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Marketplace discovery</div><h1>Browse listings</h1>
    <p>Explore approved digital products, services, projects and freelance offers. Listings displayed here reflect current publication checks.</p>
  </div>
  @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
  <nav class="marketplace-discovery-tabs" aria-label="Marketplace listing types">
    @foreach([''=>'All listings','product'=>'Products','project'=>'Projects','service'=>'Services','freelance'=>'Freelance'] as $tabType=>$tabLabel)
      <a href="{{ route('marketplace.search',array_merge(request()->except('type','category','page'),$tabType!==''?['type'=>$tabType]:[])) }}"
         class="marketplace-discovery-tab {{ $type===$tabType?'is-active':'' }}"
         @if($type===$tabType)aria-current="page"@endif>{{ $tabLabel }}</a>
    @endforeach
  </nav>
  <section class="marketplace-filter-panel">
    <form method="get" action="{{ route('marketplace.search') }}" class="marketplace-filter-form">
      <div class="marketplace-filter-field marketplace-filter-search">
        <label for="marketplace-q">Search listings</label>
        <input id="marketplace-q" name="q" type="search" maxlength="120" value="{{ $query }}" placeholder="Title, service or keyword">
      </div>
      <div class="marketplace-filter-field">
        <label for="marketplace-type">Type</label>
        <select name="type" id="marketplace-type">
          <option value="">All types</option>
          @foreach(\App\Models\MarketplaceListing::TYPES as $option)
            <option value="{{ $option }}" @selected($type===$option)>{{ ucfirst($option) }}</option>
          @endforeach
        </select>
      </div>
      <div class="marketplace-filter-field">
        <label for="marketplace-category">Category</label>
        <select name="category" id="marketplace-category">
          <option value="">All categories</option>
          @foreach($categories as $category)
            @if($type==='' || $category->type===$type)
              <option value="{{ $category->public_id }}" @selected($selectedCategory===$category->public_id)>{{ ucfirst($category->type) }} · {{ $category->name }}</option>
            @endif
          @endforeach
        </select>
      </div>
      <div class="marketplace-filter-field">
        <label for="marketplace-min">Minimum USD</label>
        <input name="min_price" id="marketplace-min" type="number" min="0" max="9999999999.99" step="0.01" value="{{ $minPrice }}" placeholder="Any">
      </div>
      <div class="marketplace-filter-field">
        <label for="marketplace-max">Maximum USD</label>
        <input name="max_price" id="marketplace-max" type="number" min="0" max="9999999999.99" step="0.01" value="{{ $maxPrice }}" placeholder="Any">
      </div>
      <div class="marketplace-filter-field">
        <label for="marketplace-sort">Sort results</label>
        <select id="marketplace-sort" name="sort">
          @foreach(['recent'=>'Newest first','oldest'=>'Oldest first','price_low'=>'Price: low to high','price_high'=>'Price: high to low','title'=>'Title A–Z'] as $value=>$label)
            <option value="{{ $value }}" @selected($sort===$value)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="marketplace-filter-actions">
        <button type="submit" class="btn primary"><x-icon name="search" size="16"/> Apply filters</button>
        <a href="{{ route('marketplace.search') }}" class="btn subtle">Reset</a>
      </div>
    </form>
  </section>
  @if($query!=='' || $type!=='' || $selectedCategory!=='' || $minPrice!=='' || $maxPrice!=='' || $sort!=='recent')
    <nav class="marketplace-active-filters" aria-label="Active search filters">
      <span class="marketplace-active-filters-label">Active filters</span>
      @if($query!=='')
        <a href="{{ route('marketplace.search',request()->except('q','page')) }}" aria-label="Remove keyword filter">Keyword: {{ $query }} <span aria-hidden="true">×</span></a>
      @endif
      @if($type!=='')
        <a href="{{ route('marketplace.search',request()->except('type','category','page')) }}" aria-label="Remove listing type filter">Type: {{ ucfirst($type) }} <span aria-hidden="true">×</span></a>
      @endif
      @if($selectedCategory!=='')
        <a href="{{ route('marketplace.search',request()->except('category','page')) }}" aria-label="Remove category filter">Category: {{ $categories->firstWhere('public_id',$selectedCategory)?->name ?? 'Selected' }} <span aria-hidden="true">×</span></a>
      @endif
      @if($minPrice!=='')
        <a href="{{ route('marketplace.search',request()->except('min_price','page')) }}" aria-label="Remove minimum price filter">Minimum: USD {{ $minPrice }} <span aria-hidden="true">×</span></a>
      @endif
      @if($maxPrice!=='')
        <a href="{{ route('marketplace.search',request()->except('max_price','page')) }}" aria-label="Remove maximum price filter">Maximum: USD {{ $maxPrice }} <span aria-hidden="true">×</span></a>
      @endif
      @if($sort!=='recent')
        <a href="{{ route('marketplace.search',request()->except('sort','page')) }}" aria-label="Remove sorting preference">Sort: {{ str_replace('_',' ',ucfirst($sort)) }} <span aria-hidden="true">×</span></a>
      @endif
      <a class="marketplace-clear-filters" href="{{ route('marketplace.search') }}">Clear all</a>
    </nav>
  @endif
  <section class="marketplace-section">
    <div class="marketplace-section-heading">
      <div><span class="eyebrow">Current public listings</span>
        <h2>{{ number_format($listings->total()) }} {{ $listings->total()===1?'result':'results' }}</h2></div>
      @auth<a href="{{ route('account.saved-listings') }}" class="btn subtle"><x-icon name="star" size="16"/> My saved listings</a>@endauth
    </div>
    @if($listings->isNotEmpty())
      <div class="marketplace-card-grid">
        @foreach($listings as $listing)
          <x-listing-card :listing="$listing" :is-saved="in_array((int)$listing->id,$savedIds,true)"/>
        @endforeach
      </div>
    @else
      <div class="marketplace-empty">No approved listings match these filters. Adjust your search or reset the price range.</div>
    @endif
    <x-pagination :paginator="$listings"/>
  </section>
</div>
@endsection
