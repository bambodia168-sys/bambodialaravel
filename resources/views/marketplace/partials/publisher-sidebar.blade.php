@php
  $publicProfile = $creator?->creatorProfile?->is_public ? $creator->creatorProfile : null;
  $profileUrl = $creator?->isCreator() ? route('creators.show', $creator->public_id) : null;
  $publicLocation = $publicProfile ? trim((string) $creator->userProfile()->value('public_location')) : '';
  $locationParts = preg_split('/\s*,\s*/u', $publicLocation, -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $location = implode(', ', array_slice($locationParts, -2));
  $country = $locationParts ? end($locationParts) : '';
  $approvedCount = $creator ? (int) ($creator->public_listings_count ?? $creator->publishedListings()->count()) : 0;
  $listingCountLabel = trans_choice(':count approved listing|:count approved listings', $approvedCount, ['count' => number_format($approvedCount)]);
  $skills = $publicProfile
    ? \App\Models\CreatorProfileItem::query()
        ->where('user_id', $creator->id)->where('kind', 'expertise')->where('is_visible', true)
        ->whereNull('profile_section_id')
        ->whereNotExists(fn ($section) => $section->selectRaw('1')->from('creator_profile_sections')
            ->whereColumn('creator_profile_sections.user_id', 'creator_profile_items.user_id')
            ->where('section_key', 'expertise')->where('is_visible', false))
        ->orderBy('sort_order')->orderBy('id')->limit(3)->pluck('label')->unique()
    : collect();
  $headline = $publicProfile?->headline;
  $specialty = $skills->isNotEmpty() ? $skills->implode(' · ') : $headline;
@endphp
<link rel="stylesheet" href="{{ asset('css/listing-publisher-sidebar.css') }}?v=2">
<script type="module" src="{{ asset('js/listing-publisher-sidebar.js') }}?v=2"></script>
<section class="premium-side-card premium-side-creator" id="publisher" aria-labelledby="publisher-heading">
  <div class="premium-side-card-heading"><h2 id="publisher-heading">Published by</h2></div>
  <div class="premium-side-card-body listing-publisher-body">
    <div class="listing-publisher-identity">
      @if($profileUrl)
        <a class="listing-publisher-avatar" href="{{ $profileUrl }}" aria-label="View {{ $ownerName }}'s profile" title="View creator profile">
          <x-profile-avatar :user="$creator" public size="28"/>
        </a>
      @else
        <div class="listing-publisher-avatar" aria-hidden="true"><x-profile-avatar :user="$creator" public size="28"/></div>
      @endif
      <div class="listing-publisher-details">
        @if($profileUrl)
          <a class="premium-side-creator-name listing-publisher-line" href="{{ $profileUrl }}" title="{{ $ownerName }}" data-publisher-line><span>{{ $ownerName }}</span></a>
        @else
          <strong class="premium-side-creator-name listing-publisher-line" title="{{ $ownerName }}" data-publisher-line><span>{{ $ownerName }}</span></strong>
        @endif
        @if(filled($specialty))
          <p class="listing-publisher-skills listing-publisher-line" title="{{ $specialty }}" data-publisher-line><span>{{ $specialty }}</span></p>
        @endif
      </div>
    </div>
    @if($creatorBadges->isNotEmpty())
      <div class="listing-publisher-badges">
        <x-creator-badges :badges="$creatorBadges" variant="compact" mode="badge"/>
      </div>
    @endif
    @if($creator)
      <div class="listing-publisher-meta">
        @if($profileUrl)
          <a class="listing-publisher-listings" href="{{ $profileUrl }}" aria-label="{{ $listingCountLabel }}">
            <x-icon name="briefcase" size="16"/>
            <span>{{ number_format($approvedCount) }}</span>
          </a>
        @else
          <span class="listing-publisher-listings" role="group" aria-label="{{ $listingCountLabel }}">
            <x-icon name="briefcase" size="16"/>
            <span aria-hidden="true">{{ number_format($approvedCount) }}</span>
          </span>
        @endif
        @if(filled($location))
          <div class="listing-publisher-location" data-publisher-location>
            <button type="button" class="listing-publisher-location-trigger" aria-describedby="publisher-location-detail" aria-expanded="false">
              <x-icon name="globe" size="16"/>
              <span class="listing-publisher-location-label" data-publisher-province-country>{{ $location }}</span>
              <span class="listing-publisher-location-label" data-publisher-country>{{ $country }}</span>
            </button>
            <div class="listing-publisher-location-detail" id="publisher-location-detail" role="tooltip" hidden>{{ $publicLocation }}</div>
          </div>
        @endif
      </div>
    @endif
  </div>
</section>
