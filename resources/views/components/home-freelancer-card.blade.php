@props(['freelancer', 'badges'])

@php
  $profileUrl = route('creators.show', $freelancer->public_id);
  $headline = $freelancer->creatorProfile->headline;
  if ($freelancer->publicExpertise->isNotEmpty()) {
    $headline = trim(\Illuminate\Support\Str::before($headline, '|')) ?: $headline;
  }
  $listingCount = number_format($freelancer->public_listings_count);
  $listingDescription = $listingCount.' approved '.\Illuminate\Support\Str::plural('listing', $freelancer->public_listings_count);
  $locationParts = preg_split('/\s*,\s*/u', trim($freelancer->userProfile?->public_location ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
  $location = count($locationParts) >= 2 ? implode(', ', array_slice($locationParts, -2)) : null;
  $initials = \Illuminate\Support\Str::of($freelancer->name)->squish()->explode(' ')
    ->take(2)->map(fn ($part) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($part, 0, 1)))->implode('');
@endphp

<article class="freelancer-showcase-card">
  <div class="freelancer-showcase-identity">
    <a class="freelancer-showcase-portrait" href="{{ $profileUrl }}" aria-label="View {{ $freelancer->name }}’s profile">
      @if($freelancer->userProfile?->avatar_path)
        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($freelancer->userProfile->avatar_path) }}"
             alt="{{ $freelancer->name }} profile photo" loading="lazy" decoding="async" width="360" height="360">
      @else
        <span aria-hidden="true">{{ $initials }}</span>
      @endif
    </a>
    <div class="freelancer-showcase-body">
      <h3><a href="{{ $profileUrl }}">{{ $freelancer->name }}</a></h3>
      <p class="freelancer-showcase-headline">{{ $headline }}</p>
      @if($freelancer->publicExpertise->isNotEmpty())
        <ul class="freelancer-showcase-skills" aria-label="Expertise">
          @foreach($freelancer->publicExpertise->unique('label') as $skill)
            <li>{{ $skill->label }}</li>
          @endforeach
        </ul>
      @endif
    </div>
  </div>
  @if($badges->isNotEmpty())
    <x-creator-badges :badges="$badges" variant="compact"/>
  @endif
  <footer class="freelancer-showcase-footer">
    <a class="freelancer-showcase-listings" href="{{ $profileUrl }}#published-work"
       title="{{ $listingDescription }}" aria-label="{{ $listingDescription }}">{{ $listingCount }}</a>
    @if($location)
      <p class="freelancer-showcase-location">
        <x-icon name="globe" size="14"/><span>{{ $location }}</span>
      </p>
    @endif
  </footer>
</article>
