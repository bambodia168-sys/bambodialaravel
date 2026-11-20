@props(['creator', 'badges'])

@php
  $profile = $creator->creatorProfile?->is_public ? $creator->creatorProfile : null;
  $profileUrl = route('creators.show', $creator->public_id);
  $specialty = $profile ? ($creator->publicExpertise->pluck('label')->unique()->implode(' · ') ?: $profile->headline) : null;
  $locationParts = $profile ? preg_split('/\s*,\s*/u', trim((string) $creator->userProfile?->public_location), -1, PREG_SPLIT_NO_EMPTY) : [];
  $location = implode(', ', array_slice($locationParts ?: [], -2));
  $approvedCount = (int) $creator->public_listings_count;
  $listingCountLabel = trans_choice(':count approved listing|:count approved listings', $approvedCount, ['count' => number_format($approvedCount)]);
@endphp

<article class="public-creator-card">
  <div class="public-creator-identity">
    <a class="public-creator-avatar" href="{{ $profileUrl }}" aria-label="View {{ $creator->name }} creator profile">
      <x-profile-avatar :user="$creator" public lazy size="28" :alt="$creator->name.' profile photo'"/>
    </a>
    <div class="public-creator-details">
      <h3><a href="{{ $profileUrl }}">{{ $creator->name }}</a></h3>
      @if(filled($specialty))
        <p>{{ $specialty }}</p>
      @endif
    </div>
  </div>
  @if($badges->isNotEmpty())
    <x-creator-badges :badges="$badges" variant="compact" mode="badge"/>
  @endif
  <footer class="public-creator-meta">
    <a class="public-creator-listings" href="{{ $profileUrl }}" aria-label="{{ $listingCountLabel }}" title="{{ $listingCountLabel }}">
      <x-icon name="briefcase" size="16"/>
      <span>{{ number_format($approvedCount) }}</span>
    </a>
    @if(filled($location))
      <span class="public-creator-location">
        <x-icon name="globe" size="16"/>
        <span>{{ $location }}</span>
      </span>
    @endif
  </footer>
</article>
