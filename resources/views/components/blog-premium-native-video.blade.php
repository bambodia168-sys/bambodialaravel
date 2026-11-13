@props(['media', 'title'])

{{-- Progressive enhancement: native browser controls stay available until JS
     successfully binds accessible custom controls. Never autoplay on load. --}}
<div class="blog-premium-player" data-blog-premium-player role="group"
     aria-label="Video player for {{ $title }}" tabindex="0">
  <video class="post-public-video blog-premium-player-video" data-blog-player-video
         controls playsinline preload="metadata"
         aria-label="{{ $media->alt_text ?: $title }}"
         src="{{ $media->previewUrl() }}"></video>

  <div class="blog-player-top" aria-hidden="true">
    <span class="blog-player-brand"><x-icon name="video" size="16"/> Video</span>
  </div>

  {{-- Fullscreen gets a prominent, clearly labeled exit in the top-right corner.
       Kept separate from the video controls for mouse, touch and keyboard access. --}}
  <button type="button" class="blog-player-fullscreen-exit" data-blog-player-exit-fullscreen
          aria-label="Exit full screen" title="Exit full screen" hidden>
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3v6H3M3 9l7-7M15 21v-6h6m0 0-7 7"/></svg>
    <span>Exit full screen</span>
  </button>

  <button type="button" class="blog-player-center" data-blog-player-toggle data-blog-player-center
          aria-label="Play video" hidden>
    <x-icon name="play" size="34"/>
  </button>

  <div class="blog-player-hud" data-blog-player-hud hidden>
    <div class="blog-player-progress">
      <input type="range" data-blog-player-seek min="0" max="100" step="0.1" value="0"
             aria-label="Seek video" aria-valuetext="0:00 of 0:00">
    </div>
    <div class="blog-player-controls">
      <button type="button" class="blog-player-icon-btn" data-blog-player-toggle
              data-blog-player-action aria-label="Play video" title="Play or pause">
        <span data-blog-player-play-icon><x-icon name="play" size="20"/></span>
        <span data-blog-player-pause-icon hidden><x-icon name="pause" size="20"/></span>
      </button>
      <output class="blog-player-time" data-blog-player-time aria-label="Video playback time">0:00 / 0:00</output>
      <span class="blog-player-control-spacer"></span>
      <button type="button" class="blog-player-icon-btn" data-blog-player-mute
              aria-label="Mute video" title="Mute or unmute">
        <span data-blog-player-volume-icon><x-icon name="volume-2" size="19"/></span>
        <span data-blog-player-muted-icon hidden><x-icon name="volume-x" size="19"/></span>
      </button>
      <input class="blog-player-volume" data-blog-player-volume type="range"
             min="0" max="1" step="0.05" value="1" aria-label="Volume">
      <button type="button" class="blog-player-rate" data-blog-player-rate
              aria-label="Playback speed: 1×" title="Change playback speed">1×</button>
      <button type="button" class="blog-player-icon-btn blog-player-pip" data-blog-player-pip
              aria-label="Picture in picture" title="Picture in picture" hidden>
        <x-icon name="picture-in-picture" size="20"/>
      </button>
      <button type="button" class="blog-player-icon-btn blog-player-fullscreen-btn"
              data-blog-player-fullscreen aria-label="Enter full screen"
              title="Full screen" aria-keyshortcuts="F" aria-pressed="false">
        <span data-blog-player-expand-icon><x-icon name="maximize" size="19"/></span>
        <span data-blog-player-collapse-icon hidden><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 3v6H3M3 9l7-7M15 21v-6h6m0 0-7 7"/></svg></span>
        <span class="blog-player-fullscreen-label" data-blog-player-fullscreen-label>Full screen</span>
      </button>
    </div>
  </div>
  <span class="blog-player-status" data-blog-player-status role="status" aria-live="polite"></span>
</div>
