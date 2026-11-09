@props(['job'])
@php
  $approvedFeaturedImage = $job->featuredAsset
    && in_array($job->featured_kind,['image','animated'],true);
@endphp
<article class="marketplace-card marketplace-card--compact premium-related-job-card">
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
  <div class="marketplace-card-body">
    <span class="marketplace-card-category">{{ ucfirst($job->category) }}</span>
    <h3><a href="{{ $job->publicUrl() }}" title="{{ $job->title }}">{{ $job->title }}</a></h3>
    <div class="marketplace-card-creator"><span>{{ $job->client?->name ?? 'Bambodia client' }}</span></div>
    <div class="marketplace-card-footer">
      <strong class="marketplace-card-price">{{ $job->budget !== null
        ? \App\Support\MarketplaceCardPresentation::price($job->budget,$job->currency)
        : 'Budget on request' }}</strong>
    </div>
  </div>
</article>
