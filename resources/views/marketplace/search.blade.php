@extends('layouts.main')
@section('title','Search Marketplace')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Marketplace discovery</div><h1>Browse listings</h1>
    <p>Explore approved digital products, services, projects and freelance offers. Listings displayed here reflect current publication checks.</p>
  </div>
  @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
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
            <option value="{{ $category->public_id }}" @selected($selectedCategory===$category->public_id)>{{ ucfirst($category->type) }} · {{ $category->name }}</option>
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
