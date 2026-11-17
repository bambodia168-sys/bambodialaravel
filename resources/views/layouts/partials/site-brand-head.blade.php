@php
  $siteFaviconLight=\App\Models\SiteBrandAsset::urlFor('favicon_light');
  $siteFaviconDark=\App\Models\SiteBrandAsset::urlFor('favicon_dark');
  $siteFaviconLightMime=\App\Models\SiteBrandAsset::actualMimeFor('favicon_light');
@endphp
<link rel="icon" type="{{ $siteFaviconLightMime }}" href="{{ $siteFaviconLight }}" data-site-theme-icon
  data-light-href="{{ $siteFaviconLight }}" data-dark-href="{{ $siteFaviconDark }}">
<link rel="apple-touch-icon" href="{{ \App\Models\SiteBrandAsset::urlFor('touch_icon') }}">
<link rel="manifest" href="{{ route('site.manifest') }}">
<script defer src="{{ asset('js/site-identity-theme-icon.js') }}?v=1"></script>
