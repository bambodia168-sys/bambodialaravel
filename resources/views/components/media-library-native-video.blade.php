@props(['asset', 'controls'])

<div class="media-player-shell" data-media-player tabindex="0" aria-label="Private video preview">
  <div class="media-player-surface">
    <video data-player-video src="{{ $asset->previewUrl() }}"
      controls preload="none" playsinline aria-label="{{ $asset->alt_text }}"></video>
    <button class="media-player-center" type="button" data-player-overlay
      aria-label="Play video"><x-icon name="play" size="28"/></button>
    {{ $slot }}
  </div>
  <div class="media-player-controls" aria-label="Video playback controls">
    <button type="button" class="media-player-tool" data-player-toggle aria-label="Play video">
      <span data-player-play><x-icon name="play" size="18"/></span>
      <span data-player-pause><x-icon name="pause" size="18"/></span>
    </button>
    <input class="media-player-seek" data-player-seek @if (!in_array('progress', $controls, true)) data-control-disabled @endif type="range"
      min="0" max="1000" step="1" value="0" aria-label="Seek video">
    <span class="media-player-clock" data-player-clock @if (!in_array('time', $controls, true)) data-control-disabled @endif>0:00 / 0:00</span>
    <button class="media-player-tool" type="button" data-player-mute @if (!in_array('volume', $controls, true)) data-control-disabled @endif aria-label="Mute video">
      <span data-player-sound><x-icon name="volume-2" size="18"/></span>
      <span data-player-muted><x-icon name="volume-x" size="18"/></span>
    </button>
    <select class="media-player-speed" data-player-speed @if (!in_array('speed', $controls, true)) data-control-disabled @endif aria-label="Playback speed">
      <option value="0.75">0.75×</option>
      <option value="1" selected>1×</option>
      <option value="1.25">1.25×</option>
      <option value="1.5">1.5×</option>
      <option value="2">2×</option>
    </select>
    <button class="media-player-tool" type="button" data-player-pip @if (!in_array('pip', $controls, true)) data-control-disabled @endif aria-label="Picture in picture">
      <x-icon name="picture-in-picture" size="18"/>
    </button>
    <button class="media-player-tool" type="button" data-player-fullscreen @if (!in_array('fullscreen', $controls, true)) data-control-disabled @endif aria-label="Fullscreen video">
      <x-icon name="maximize" size="18"/>
    </button>
  </div>
</div>
