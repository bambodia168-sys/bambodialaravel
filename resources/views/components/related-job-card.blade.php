@props(['job', 'isSaved' => false])
@php
  $approvedFeaturedImage = $job->featuredAsset
    && in_array($job->featured_kind,['image','animated'],true);
@endphp
<article class="marketplace-card marketplace-card--compact premium-related-job-card">
  <div class="marketplace-card-media">
    <a href="{{ $job->publicUrl() }}" class="marketplace-card-art" aria-label="View job: {{ $job->title }}">
      @if($approvedFeaturedImage)
        <img src="{{ $job->featuredAsset->previewUrl() }}"
          alt="{{ $job->featuredAsset->alt_text ?: $job->title }}" loading="lazy" decoding="async">
      @else
        <span class="marketplace-card-placeholder"
          @if($job->featured_kind === 'color' && preg_match('/^#[0-9a-fA-F]{6}$/D',(string)$job->featured_color))
            style="background:{{ $job->featured_color }}"
          @endif>
          <x-icon name="briefcase" size="38"/>
        </span>
      @endif
      <span class="marketplace-card-type">Job</span>
      @if($job->status==='published' && $job->is_featured)
        <span class="marketplace-card-featured" aria-label="Administrator featured job"><x-icon name="tag" size="13"/> Featured</span>
      @endif
    </a>
    @auth
      <div class="marketplace-card-save card-save-action">
        <x-save-star :item="$job" :is-saved="$isSaved"/>
      </div>
    @endauth
  </div>
  <div class="marketplace-card-body">
    <div class="marketplace-card-category">
      <a class="marketplace-card-metadata-link" href="{{ $job->categoryUrl() }}">{{ $job->category_label }}</a>
    </div>
    <h3><a href="{{ $job->publicUrl() }}" title="{{ $job->title }}">{{ $job->title }}</a></h3>
    <div class="marketplace-card-creator">
      @if($job->client?->public_id)
        <a class="marketplace-card-metadata-link" href="{{ $job->publisherUrl() }}">{{ $job->client->name }}</a>
      @else
        <span>Bambodia client</span>
      @endif
    </div>
    <div class="marketplace-card-footer">
      <strong class="marketplace-card-price">{{ $job->budget !== null
        ? \App\Support\MarketplaceCardPresentation::price($job->budget,$job->currency)
        : 'Budget on request' }}</strong>
    </div>
  </div>
</article>
