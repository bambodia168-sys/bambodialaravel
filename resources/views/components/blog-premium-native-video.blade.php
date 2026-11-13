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

  {{-- Accessible icon-only exit within the immersive fullscreen surface. --}}
  <button type="button" class="blog-player-fullscreen-exit" data-blog-player-exit-fullscreen
          aria-label="Exit full screen" title="Exit full screen" hidden>
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M9 4v5H4M15 4v5h5M20 15h-5v5M4 15h5v5"/>
    </svg>
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
      <div class="blog-player-volume-wrap" data-blog-player-volume-wrap>
        <button type="button" class="blog-player-icon-btn blog-player-volume-trigger"
                data-blog-player-volume-trigger aria-label="Adjust volume"
                aria-expanded="false" aria-controls="blog-volume-{{ $media->getKey() }}"
                title="Adjust volume">
          <span data-blog-player-volume-icon><x-icon name="volume-2" size="19"/></span>
          <span data-blog-player-muted-icon hidden><x-icon name="volume-x" size="19"/></span>
        </button>
        <div class="blog-player-volume-popover" data-blog-player-volume-popover
             id="blog-volume-{{ $media->getKey() }}" hidden>
          <input class="blog-player-volume" data-blog-player-volume type="range"
                 min="0" max="1" step="0.05" value="1" aria-label="Volume"
                 aria-orientation="vertical">
          <button type="button" class="blog-player-volume-mute" data-blog-player-mute
                  aria-label="Mute video" title="Mute or unmute">
            <x-icon name="volume-x" size="17"/>
          </button>
        </div>
      </div>
      <button type="button" class="blog-player-rate" data-blog-player-rate
              aria-label="Playback speed: 1×" title="Change playback speed">1×</button>
      <button type="button" class="blog-player-icon-btn blog-player-pip" data-blog-player-pip
              aria-label="Picture in picture" title="Picture in picture" hidden>
        <x-icon name="picture-in-picture" size="20"/>
      </button>
      <button type="button" class="blog-player-icon-btn blog-player-fullscreen-btn"
              data-blog-player-fullscreen aria-label="Enter full screen"
              title="Enter full screen" aria-keyshortcuts="F" aria-pressed="false">
        <span data-blog-player-expand-icon>
          <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 4H4v5M15 4h5v5M20 15v5h-5M4 15v5h5"/>
          </svg>
        </span>
        <span data-blog-player-collapse-icon hidden>
          <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor"
               stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9 4v5H4M15 4v5h5M20 15h-5v5M4 15h5v5"/>
          </svg>
        </span>
      </button>
    </div>
  </div>
  <span class="blog-player-status" data-blog-player-status role="status" aria-live="polite"></span>
</div>
