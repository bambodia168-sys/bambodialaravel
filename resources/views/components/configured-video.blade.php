@props(['media', 'title', 'settings' => null, 'private' => false, 'library' => false])

@php
  $settings ??= \App\Support\VideoPlayerSettings::read();
  $type = $settings['type'];
  $profile = $settings['profiles'][$type];
  $ad = $private ? null : $profile['ad'];
  $preload = $library ? 'none' : 'metadata';
@endphp
<div {{ $attributes->class(['configured-player', 'configured-player--'.$profile['skin']]) }}
     data-configured-player="{{ $type }}" data-configured-controls="{{ implode(' ', $profile['controls']) }}">
  @if ($type === 'plyr')
    <video data-plyr-video src="{{ $media->previewUrl() }}" controls playsinline preload="{{ $preload }}"
           aria-label="{{ $media->alt_text ?: $title }}"></video>
    <x-video-player-overlays :profile="$profile" :ad="$ad"/>
  @elseif ($library)
    <x-media-library-native-video :asset="$media" :controls="$profile['controls']">
      <x-video-player-overlays :profile="$profile" :ad="$ad"/>
    </x-media-library-native-video>
  @else
    <x-blog-premium-native-video :media="$media" :title="$title" :preload="$preload" :controls="$profile['controls']">
      <x-video-player-overlays :profile="$profile" :ad="$ad"/>
    </x-blog-premium-native-video>
  @endif
  <div class="configured-player-recovery" data-player-recovery hidden>
    <p role="status">Video couldn’t load.</p>
    <button class="btn subtle compact" type="button" data-player-retry>Retry video</button>
  </div>
</div>
