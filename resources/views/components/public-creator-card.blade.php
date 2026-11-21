@props(['creator', 'badges', 'style' => null])

@php
  $profile = $creator->creatorProfile?->is_public ? $creator->creatorProfile : null;
  $profileUrl = route('creators.show', $creator->public_id);
  $specialty = $profile ? ($creator->publicExpertise->pluck('label')->unique()->implode(' · ') ?: $profile->headline) : null;
  $publicLocation = $profile ? trim((string) $creator->userProfile?->public_location) : '';
  $locationParts = preg_split('/\s*,\s*/u', $publicLocation, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $country = $locationParts ? end($locationParts) : '';
  $locationId = 'public-creator-location-'.$creator->public_id;
  $approvedCount = (int) $creator->public_listings_count;
  $listingCountLabel = trans_choice(':count approved listing|:count approved listings', $approvedCount, ['count' => number_format($approvedCount)]);
  $style ??= random_int(1, 4);
@endphp

<article class="public-creator-card" data-card-style="{{ $style }}">
  <div class="public-creator-body">
    <svg class="public-creator-watermark" viewBox="0 0 120 140" fill="none" aria-hidden="true">
      <path d="M114 5c-20 46-45 83-94 125M106 23C79 14 57 25 38 41c28 3 50-1 68-18ZM92 47C68 37 42 51 21 71c27 0 54-7 71-24Zm-18 24c-26-6-48 12-64 31 25-3 51-15 64-31ZM103 30c15 11 15 35 9 60-11-20-17-40-9-60ZM88 54c16 17 13 42 5 62-9-21-13-42-5-62ZM70 78c12 15 7 40-4 57-6-24-7-41 4-57Z"
            stroke="currentColor" stroke-width="1.15" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    <div class="public-creator-identity">
      <a class="public-creator-avatar" href="{{ $profileUrl }}" aria-label="View {{ $creator->name }} creator profile">
        <x-profile-avatar :user="$creator" public lazy size="28" :alt="$creator->name.' profile photo'"/>
        <svg class="public-creator-wreath" viewBox="0 0 60 66" aria-hidden="true">
          <path d="M12 60C6 41 17 18 48 7" fill="none" stroke="currentColor" stroke-width="1.4"/>
          <path d="M11 56C0 50 0 43 2 36c9 4 12 11 9 20Zm2-11c-8-7-7-15-3-21 7 7 7 14 3 21Zm8-13c-6-9-3-17 3-21 4 7 2 15-3 21Zm13-13c-3-9 3-15 10-17 0 8-4 14-10 17Zm-21 30c4-9 12-12 19-9-5 7-12 9-19 9Zm8-12c5-9 13-10 19-7-5 6-12 8-19 7Zm10-12c8-7 15-7 21-3-7 5-14 5-21 3Zm12-10C47 7 54 4 60 5c-4 7-10 11-17 10Z" fill="currentColor"/>
        </svg>
      </a>
      <div class="public-creator-details">
        <h3><a href="{{ $profileUrl }}">{{ $creator->name }}</a></h3>
        @if(filled($specialty))
          <p>{{ $specialty }}</p>
        @endif
      </div>
    </div>
    @if($badges->isNotEmpty())
      <div class="public-creator-badges" data-creator-badges>
        <div class="public-creator-badge-rail">
          <div class="public-creator-badge-track">
            <x-creator-badges :badges="$badges" variant="compact" mode="badge"/>
          </div>
        </div>
      </div>
    @endif
  </div>
  <footer class="public-creator-meta">
    <a class="public-creator-listings" href="{{ $profileUrl }}" aria-label="{{ $listingCountLabel }}" title="{{ $listingCountLabel }}">
      <x-icon name="briefcase" size="16"/>
      <span>{{ number_format($approvedCount) }}</span>
    </a>
    @if(filled($country))
      @if($country !== $publicLocation)
        <div class="public-creator-location" data-creator-location>
          <button type="button" class="public-creator-location-trigger" aria-expanded="false" aria-describedby="{{ $locationId }}">
            <x-icon name="globe" size="16"/>
            <span>{{ $country }}</span>
          </button>
          <span id="{{ $locationId }}" class="public-creator-location-detail" role="tooltip" hidden>{{ $publicLocation }}</span>
        </div>
      @else
        <span class="public-creator-location">
          <x-icon name="globe" size="16"/>
          <span>{{ $country }}</span>
        </span>
      @endif
    @endif
  </footer>
</article>
