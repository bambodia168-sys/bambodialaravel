@props(['item', 'kind', 'typeIcon', 'fallbackTitle', 'fallbackText', 'productCover' => null])

@php
  $asset=$item->featuredAsset;
  $youtubeId=($item->featured_kind === 'youtube' && preg_match('/^[A-Za-z0-9_-]{11}$/D',(string)($item->featured_youtube_id ?? '')))
    ? $item->featured_youtube_id : null;
  $color=($item->featured_kind === 'color' && preg_match('/^#[0-9a-fA-F]{6}$/D',(string)$item->featured_color))
    ? $item->featured_color : null;
@endphp
<section class="premium-showcase premium-detail-media premium-detail-media--{{ $kind }}" aria-label="{{ ucfirst($kind) }} featured media" data-featured-stage>
  <span class="premium-detail-floating-type"><x-icon :name="$typeIcon" size="16"/> {{ ucfirst($kind) }}</span>
  @if($asset && in_array($item->featured_kind, ['image','animated'],true))
    <img class="premium-showcase-cover premium-detail-media-content" src="{{ $asset->previewUrl() }}"
      alt="{{ $asset->alt_text ?: $item->title }}" decoding="async">
  @elseif($asset && $item->featured_kind === 'video')
    <video class="premium-showcase-cover studio-showcase-video premium-detail-media-content"
      src="{{ $asset->previewUrl() }}" controls playsinline preload="metadata"
      aria-label="{{ $asset->alt_text ?: $item->title }}"></video>
  @elseif($youtubeId)
    <div class="premium-detail-media-content premium-detail-youtube-preview" data-youtube-stage>
      <a class="premium-detail-youtube-play" href="https://www.youtube.com/watch?v={{ $youtubeId }}"
        target="_blank" rel="noopener noreferrer external"
        data-featured-youtube-play data-youtube-id="{{ $youtubeId }}"
        aria-label="Load featured YouTube video: {{ $item->title }}">
        <span class="premium-detail-youtube-play-icon"><x-icon name="play" size="27"/></span>
        <strong>Watch featured video</strong>
        <small>Load YouTube player</small>
      </a>
    </div>
  @elseif($color)
    <div class="premium-showcase-placeholder premium-detail-media-content" style="background-color:{{ $color }};color:#fff">
      <span class="premium-showcase-symbol"><x-icon :name="$typeIcon" size="58"/></span>
      <span class="eyebrow" style="color:inherit">{{ ucfirst($kind) }}</span>
      <span class="premium-showcase-secondary">{{ $fallbackText }}</span>
    </div>
  @elseif($productCover)
    <img class="premium-showcase-cover premium-detail-media-content" src="{{ $productCover->previewUrl() }}"
      alt="{{ $productCover->alt_text ?: $item->title }}" decoding="async">
  @else
    <div class="premium-showcase-placeholder premium-showcase-{{ $kind }} premium-detail-media-content">
      <span class="premium-showcase-symbol"><x-icon :name="$typeIcon" size="58"/></span>
      <span class="eyebrow">{{ ucfirst($kind) }} on Bambodia</span>
      <h2>{{ $fallbackTitle }}</h2>
      <span class="premium-showcase-secondary">{{ $fallbackText }}</span>
    </div>
  @endif
</section>
