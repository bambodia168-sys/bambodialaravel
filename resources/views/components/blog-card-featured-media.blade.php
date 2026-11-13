@props(['post'])

@php
  $featuredMedia = $post->featuredMedia;
  $isImage = $post->featured_type === 'image'
      && $featuredMedia && str_starts_with((string) $featuredMedia->mime_type, 'image/');
  $isVideo = $post->featured_type === 'video'
      && $featuredMedia && in_array($featuredMedia->mime_type, ['video/mp4', 'video/webm'], true);
  $youtubeId = null;
  if ($post->featured_type === 'youtube'
      && preg_match('~^https://www\\.youtube\\.com/watch\\?v=([A-Za-z0-9_-]{11})$~D', (string) $post->featured_url, $youtubeMatches)) {
      $youtubeId = $youtubeMatches[1];
  }
@endphp

@if($isImage)
  <img src="{{ $featuredMedia->previewUrl() }}"
       alt="{{ $featuredMedia->alt_text ?: $post->title }}"
       loading="lazy" decoding="async">
@elseif($isVideo)
  {{-- A private uploaded video is only requested via the existing access-controlled
       preview endpoint; the browser seeks a short first frame, never autoplays it. --}}
  <span class="blog-card-media-backdrop" aria-hidden="true"><x-icon name="film" size="38"/></span>
  <video class="blog-card-video-thumbnail" data-blog-video-poster
         muted playsinline preload="metadata" tabindex="-1" aria-hidden="true">
    <source src="{{ $featuredMedia->previewUrl() }}#t=0.1" type="{{ $featuredMedia->mime_type }}">
  </video>
  <span class="blog-card-play-overlay" aria-hidden="true"><x-icon name="play" size="25"/></span>
  <span class="blog-card-video-badge">Video</span>
@elseif($youtubeId)
  {{-- Only the thumbnail is loaded in cards. The YouTube player stays on the
       destination article and no third-party iframe is loaded in the feed. --}}
  <span class="blog-card-media-backdrop blog-card-youtube-backdrop" aria-hidden="true"><x-icon name="youtube" size="42"/></span>
  <img class="blog-card-youtube-thumbnail" data-blog-youtube-thumbnail
       src="{{ 'https://i.ytimg.com/vi/'.$youtubeId.'/hqdefault.jpg' }}"
       alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">
  <span class="blog-card-play-overlay" aria-hidden="true"><x-icon name="play" size="25"/></span>
  <span class="blog-card-video-badge">YouTube</span>
@elseif($post->featured_type === 'color' && preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $post->featured_color))
  <span class="blog-card-color" style="background-color:{{ $post->featured_color }}" role="img" aria-label="Featured color"></span>
@else
  <span class="blog-card-placeholder"><x-icon name="file-text" size="34"/><span>Blog article</span></span>
@endif
