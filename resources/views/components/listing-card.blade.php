@props(['listing','isSaved'=>false])
<article class="marketplace-card">
  <a class="marketplace-card-art" href="{{ $listing->publicUrl() }}" aria-label="View {{ $listing->title }}">
    @if($listing->type==='product' && $listing->coverAsset?->review_status==='approved')
      <img src="{{ $listing->coverAsset->previewUrl() }}" alt="{{ $listing->coverAsset->alt_text }}" loading="lazy">
    @else
      <span class="marketplace-card-placeholder"><x-icon :name="match($listing->type){'product'=>'package','project'=>'layers','freelance'=>'briefcase',default=>'settings'}" size="38"/></span>
    @endif
    <span class="marketplace-card-type">{{ ucfirst($listing->type) }}</span>
  </a>
  <div class="marketplace-card-body">
    <div class="marketplace-card-category">{{ $listing->category?->name ?? ucfirst($listing->type) }}</div>
    <h3><a href="{{ $listing->publicUrl() }}">{{ $listing->title }}</a></h3>
    <p>{{ \Illuminate\Support\Str::limit($listing->description,105) }}</p>
    <div class="marketplace-card-meta">
      <span>
        @if($listing->creator?->creatorProfile?->is_public)
          <a href="{{ route('creators.show',$listing->creator->public_id) }}">{{ $listing->creator->name }}</a>
        @else
          {{ $listing->creator?->name }}
        @endif
      </span>
      <strong>{{ $listing->price!==null?'USD '.number_format((float)$listing->price,2):'Price on request' }}</strong>
    </div>
    <div class="marketplace-card-actions">
      <a class="btn subtle compact" href="{{ $listing->publicUrl() }}">View details</a>
      @auth
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
      @endauth
    </div>
  </div>
</article>
