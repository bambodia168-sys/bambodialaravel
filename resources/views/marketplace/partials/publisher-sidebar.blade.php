@php
  $publicProfile = $creator?->creatorProfile?->is_public ? $creator->creatorProfile : null;
  $profileUrl = $creator?->isCreator() ? route('creators.show', $creator->public_id) : null;
  $location = $publicProfile ? $creator->userProfile()->value('public_location') : null;
  $locationParts = preg_split('/\s*,\s*/u', trim((string) $location), -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $location = implode(', ', array_slice($locationParts, -2));
  $approvedCount = $creator ? (int) ($creator->public_listings_count ?? $creator->publishedListings()->count()) : 0;
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
<link rel="stylesheet" href="{{ asset('css/listing-publisher-sidebar.css') }}?v=1">
<section class="premium-side-card premium-side-creator" id="publisher" aria-labelledby="publisher-heading">
  <div class="premium-side-card-heading"><h2 id="publisher-heading">Published by</h2></div>
  <div class="premium-side-card-body listing-publisher-body">
    <div class="listing-publisher-identity">
      @if($profileUrl)
        <a class="listing-publisher-avatar" href="{{ $profileUrl }}" aria-label="View {{ $ownerName }}'s profile">
          <x-profile-avatar :user="$creator" public size="26"/>
        </a>
      @else
        <div class="listing-publisher-avatar" aria-hidden="true"><x-profile-avatar :user="$creator" public size="26"/></div>
      @endif
      <div class="listing-publisher-details">
        @if($profileUrl)
          <a class="premium-side-creator-name" href="{{ $profileUrl }}" title="View creator profile">{{ $ownerName }}</a>
        @else
          <strong class="premium-side-creator-name">{{ $ownerName }}</strong>
        @endif
        @if(filled($specialty))
          <p class="listing-publisher-skills">{{ $specialty }}</p>
        @endif
      </div>
    </div>
    @if($creatorBadges->isNotEmpty())
      <div class="listing-publisher-badges">
        <x-creator-badges :badges="$creatorBadges" variant="compact" mode="badge"/>
      </div>
    @endif
    @if($creator)
      <dl class="listing-publisher-meta">
        <div class="listing-publisher-listings">
          <dt>Approved listings</dt>
          <dd>
            @if($profileUrl)
              <a href="{{ $profileUrl }}" aria-label="{{ trans_choice(':count approved listing|:count approved listings', $approvedCount, ['count' => number_format($approvedCount)]) }}">
                <x-icon name="briefcase" size="16"/> {{ number_format($approvedCount) }}
              </a>
            @else
              <x-icon name="briefcase" size="16"/> {{ number_format($approvedCount) }}
            @endif
          </dd>
        </div>
        @if(filled($location))
          <div class="listing-publisher-location">
            <dt>Location</dt>
            <dd><x-icon name="globe" size="16"/><span>{{ $location }}</span></dd>
          </div>
        @endif
      </dl>
    @endif
  </div>
</section>
