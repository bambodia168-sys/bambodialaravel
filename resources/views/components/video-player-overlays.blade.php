@props(['profile', 'ad' => null])

@if ($profile['logo_url'] || $profile['watermark_url'] || $profile['overlay_text'])
  <div class="configured-player-brand configured-player-brand--{{ $profile['brand_position'] }}">
    @if ($profile['logo_url'])
      <img src="{{ $profile['logo_url'] }}" alt="" referrerpolicy="no-referrer" loading="lazy">
    @endif
    @if ($profile['watermark_url'])
      <img class="configured-player-watermark" src="{{ $profile['watermark_url'] }}" alt="" referrerpolicy="no-referrer" loading="lazy">
    @endif
    @if ($profile['overlay_text'])
      <span>{{ $profile['overlay_text'] }}</span>
    @endif
  </div>
@endif
@if ($ad && $ad['enabled'])
  <div class="configured-player-ad" data-player-ad="{{ $ad['format'] }}"
       data-ad-url="{{ $ad['media_url'] }}" data-ad-seconds="{{ $ad['duration'] }}" hidden>
    <button class="configured-ad-play" type="button" data-ad-play>Play video</button>
    <div class="configured-ad-content" data-ad-content hidden>
      <span class="configured-ad-label">Advertisement</span>
      <div class="configured-ad-media" data-ad-media>
        @if ($ad['format'] !== 'google_tag' && $ad['text'])
          <p>{{ $ad['text'] }}</p>
        @endif
      </div>
      <div class="configured-ad-actions">
        @if ($ad['format'] !== 'google_tag' && $ad['link_url'])
          <a href="{{ $ad['link_url'] }}" target="_blank" rel="sponsored noopener noreferrer">Learn more</a>
        @endif
        <button type="button" data-ad-skip>Continue to video</button>
      </div>
    </div>
    <span class="sr-only" data-ad-status role="status"></span>
  </div>
@endif
