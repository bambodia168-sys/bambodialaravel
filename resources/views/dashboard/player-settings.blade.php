@extends('layouts.dashboard')
@section('title', 'Player Settings')
@section('content')
@php
  $types = \App\Support\VideoPlayerSettings::TYPES;
  $selected = old('type', $settings['type']);
  $selected = is_string($selected) && isset($types[$selected]) ? $selected : $settings['type'];
@endphp
<div class="page-intro">
  <h1>Player Settings</h1>
  <p>Choose how uploaded videos play on Bambodia. Each player keeps its own appearance, controls, branding, and ad settings.</p>
</div>
@if ($errors->any())
  <div class="notice danger" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<div class="player-settings-layout">
  <section class="panel">
    <form class="settings-form player-settings-form" method="post" action="{{ route('dashboard.player-settings.update') }}" data-player-settings-form>
      @csrf
      @method('PATCH')
      <label for="player-type">Player Type</label>
      <select id="player-type" name="type" data-player-type>
        @foreach ($types as $type => $label)
          <option value="{{ $type }}" @selected($selected === $type)>{{ $label }}</option>
        @endforeach
      </select>
      <p class="hint">Saving applies the selected player to the site. Save each player’s changes before switching to edit the other.</p>
      @foreach ($types as $type => $label)
        @php
          $key = 'profiles.'.$type;
          $field = 'profiles['.$type.']';
          $profile = $settings['profiles'][$type];
          $controls = old($key.'.controls', session()->hasOldInput($key) ? [] : $profile['controls']);
          $ad = $profile['ad'];
          $adEnabled = old($key.'.ad.enabled', session()->hasOldInput($key) ? false : $ad['enabled']);
        @endphp
        <fieldset class="player-settings-profile" data-player-profile="{{ $type }}" @if($type !== $selected) hidden disabled @endif>
          <legend>{{ $label }}</legend>
          <label for="{{ $type }}-skin">Player Skin</label>
          <select id="{{ $type }}-skin" name="{{ $field }}[skin]">
            @foreach (\App\Support\VideoPlayerSettings::SKINS as $skin => $skinLabel)
              <option value="{{ $skin }}" @selected(old($key.'.skin', $profile['skin']) === $skin)>{{ $skinLabel }}</option>
            @endforeach
          </select>
          <fieldset class="player-settings-group">
            <legend>Playback controls</legend>
            <div class="player-setting-checks">
              @foreach (\App\Support\VideoPlayerSettings::CONTROLS[$type] as $control)
                <label>
                  <input type="checkbox" name="{{ $field }}[controls][]" value="{{ $control }}" @checked(in_array($control, (array) $controls, true))>
                  {{ ['progress' => 'Seek bar', 'time' => 'Time display', 'volume' => 'Volume', 'speed' => 'Playback speed', 'rewind' => 'Rewind', 'fast-forward' => 'Fast-forward', 'pip' => 'Picture in picture', 'fullscreen' => 'Fullscreen'][$control] }}
                </label>
              @endforeach
            </div>
          </fieldset>
          <fieldset class="player-settings-group">
            <legend>Branding</legend>
            <label for="{{ $type }}-logo">Logo image URL</label>
            <input id="{{ $type }}-logo" type="url" name="{{ $field }}[logo_url]" value="{{ old($key.'.logo_url', $profile['logo_url']) }}" maxlength="2000" placeholder="https://">
            <label for="{{ $type }}-watermark">Watermark image URL</label>
            <input id="{{ $type }}-watermark" type="url" name="{{ $field }}[watermark_url]" value="{{ old($key.'.watermark_url', $profile['watermark_url']) }}" maxlength="2000" placeholder="https://">
            <label for="{{ $type }}-text">Overlay text</label>
            <input id="{{ $type }}-text" name="{{ $field }}[overlay_text]" value="{{ old($key.'.overlay_text', $profile['overlay_text']) }}" maxlength="100">
            <label for="{{ $type }}-position">Brand position</label>
            <select id="{{ $type }}-position" name="{{ $field }}[brand_position]">
              <option value="top-left" @selected(old($key.'.brand_position', $profile['brand_position']) === 'top-left')>Top left</option>
              <option value="top-right" @selected(old($key.'.brand_position', $profile['brand_position']) === 'top-right')>Top right</option>
            </select>
          </fieldset>
          <fieldset class="player-settings-group">
            <legend>Pre-roll ads</legend>
            <label class="player-setting-check"><input type="checkbox" name="{{ $field }}[ad][enabled]" value="1" @checked($adEnabled)> Show an ad before public uploaded videos</label>
            <label for="{{ $type }}-provider">Ad provider</label>
            <select id="{{ $type }}-provider" name="{{ $field }}[ad][provider]" data-ad-provider>
              @foreach (\App\Support\VideoPlayerSettings::AD_PROVIDERS as $provider => $providerLabel)
                <option value="{{ $provider }}" @selected(old($key.'.ad.provider', $ad['provider']) === $provider)>{{ $providerLabel }}</option>
              @endforeach
            </select>
            <label for="{{ $type }}-format">Creative format</label>
            <select id="{{ $type }}-format" name="{{ $field }}[ad][format]" data-ad-format>
              @foreach (\App\Support\VideoPlayerSettings::AD_FORMATS as $format => $formatLabel)
                <option value="{{ $format }}" @selected(old($key.'.ad.format', $ad['format']) === $format)>{{ $formatLabel }}</option>
              @endforeach
            </select>
            <p class="hint" data-ad-direct>Use your own image, video, or text and an optional campaign link. Facebook Ads uses these directly supplied creatives.</p>
            <p class="hint" data-ad-google hidden>Use a Google Ad Manager video tag starting with https://pubads.g.doubleclick.net/gampad/ads.</p>
            <div data-ad-field="media">
              <label for="{{ $type }}-ad-media" data-ad-media-label>Ad media URL</label>
              <input id="{{ $type }}-ad-media" type="url" name="{{ $field }}[ad][media_url]" value="{{ old($key.'.ad.media_url', $ad['media_url']) }}" maxlength="2000" placeholder="https://">
            </div>
            <div data-ad-field="text">
              <label for="{{ $type }}-ad-text">Ad text</label>
              <textarea id="{{ $type }}-ad-text" name="{{ $field }}[ad][text]" maxlength="240" rows="2">{{ old($key.'.ad.text', $ad['text']) }}</textarea>
            </div>
            <div data-ad-field="link">
              <label for="{{ $type }}-ad-link">Destination URL</label>
              <input id="{{ $type }}-ad-link" type="url" name="{{ $field }}[ad][link_url]" value="{{ old($key.'.ad.link_url', $ad['link_url']) }}" maxlength="2000" placeholder="https://">
            </div>
            <div data-ad-field="duration">
              <label for="{{ $type }}-ad-duration">Image or text display time in seconds</label>
              <input id="{{ $type }}-ad-duration" type="number" min="3" max="30" name="{{ $field }}[ad][duration]" value="{{ old($key.'.ad.duration', $ad['duration']) }}" required>
            </div>
          </fieldset>
        </fieldset>
      @endforeach
      <button class="btn primary" type="submit">Save player settings</button>
    </form>
  </section>
  <section class="panel player-settings-preview">
    <div class="panel-head"><div><h2>Saved player preview</h2><p>{{ $types[$settings['type']] }}</p></div></div>
    <div class="player-settings-preview-body">
      @if ($preview)
        <x-configured-video :media="$preview" :title="$preview->original_name" :settings="$settings" private/>
        <p class="hint">Save your changes to update the preview. Ads run on public videos.</p>
      @else
        <p class="hint">An approved video from the Media Library will appear here for playback testing.</p>
      @endif
    </div>
  </section>
</div>
@endsection
