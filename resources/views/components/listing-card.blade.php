@props(['listing','isSaved'=>false])
@php
  $featured=$listing->status === 'published' && (bool)$listing->is_featured;
  $preview=$listing->type==='product'
    ? \App\Support\MarketplaceCardPresentation::safePreviewUrl($listing->preview_url) : null;
  $money=\App\Support\MarketplaceCardPresentation::price(
    $listing->type==='product' ? ($listing->regular_price ?? $listing->price) : $listing->price,
    $listing->currency
  );
@endphp
<article class="marketplace-card">
  <a class="marketplace-card-art" href="{{ $listing->publicUrl() }}" aria-label="View {{ $listing->title }}">
    @if($listing->featuredAsset && in_array($listing->featured_kind, ['image','animated'],true))
      <img src="{{ $listing->featuredAsset->previewUrl() }}" alt="{{ $listing->featuredAsset->alt_text ?: $listing->title }}" loading="lazy" decoding="async">
    @elseif($listing->type==='product' && $listing->coverAsset)
      <img src="{{ $listing->coverAsset->previewUrl() }}" alt="{{ $listing->coverAsset->alt_text ?: $listing->title }}" loading="lazy" decoding="async">
    @else
      <span class="marketplace-card-placeholder" @if($listing->featured_kind==='color' && preg_match('/^#[0-9a-fA-F]{6}$/D',(string)$listing->featured_color)) style="background:{{ $listing->featured_color }}" @endif>
        <x-icon :name="match($listing->type){'product'=>'package','project'=>'layers','freelance'=>'briefcase',default=>'settings'}" size="38"/>
      </span>
    @endif
    <span class="marketplace-card-type">{{ ucfirst($listing->type) }}</span>
    @if($featured)
      <span class="marketplace-card-featured" aria-label="Administrator featured listing"><x-icon name="tag" size="13"/> Featured</span>
    @endif
  </a>
  <div class="marketplace-card-body">
    <div class="marketplace-card-category">{{ $listing->category?->name ?? ucfirst($listing->type) }}</div>
    <h3><a href="{{ $listing->publicUrl() }}" title="{{ $listing->title }}">{{ $listing->title }}</a></h3>
    <div class="marketplace-card-creator">
      @if($listing->creator?->creatorProfile?->is_public)
        <a href="{{ route('creators.show',$listing->creator->public_id) }}">{{ $listing->creator->name }}</a>
      @else
        <span>{{ $listing->creator?->name }}</span>
      @endif
    </div>
    <div class="marketplace-card-footer">
      <strong class="marketplace-card-price">{{ $money }}</strong>
      @if($preview)
        <a class="marketplace-card-live-preview" href="{{ $preview }}" target="_blank"
           rel="noopener noreferrer nofollow external"
           aria-label="Open creator-provided live preview for {{ $listing->title }}">
          Live Preview <x-icon name="arrow-up-right" size="15"/>
        </a>
      @endif
    </div>
    @auth
    <div class="marketplace-card-actions">
      @if($isSaved)
        <form method="post" action="{{ route('saved-listings.destroy',$listing) }}">
          @csrf @method('DELETE')
          <button type="submit" class="btn subtle compact" aria-label="Remove {{ $listing->title }} from saved listings">
            <x-icon name="star" size="15"/> Saved
          </button>
        </form>
      @else
        <form method="post" action="{{ route('saved-listings.store',$listing) }}">
          @csrf
          <button type="submit" class="btn subtle compact" aria-label="Save {{ $listing->title }} to your collection">
            <x-icon name="star" size="15"/> Save
          </button>
        </form>
      @endif
    </div>
    @endauth
  </div>
</article>
