@props(['freelancer', 'badges'])

@php
  $profileUrl = route('creators.show', $freelancer->public_id);
  $headline = $freelancer->creatorProfile->headline;
  if ($freelancer->publicExpertise->isNotEmpty()) {
    $headline = trim(\Illuminate\Support\Str::before($headline, '|')) ?: $headline;
  }
  $initials = \Illuminate\Support\Str::of($freelancer->name)->squish()->explode(' ')
    ->take(2)->map(fn ($part) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($part, 0, 1)))->implode('');
@endphp

<article class="freelancer-showcase-card">
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
    @if($freelancer->userProfile?->public_location)
      <p class="freelancer-showcase-location"><x-icon name="globe" size="15"/>{{ $freelancer->userProfile->public_location }}</p>
    @endif
    @if($badges->isNotEmpty())
      <x-creator-badges :badges="$badges" variant="compact"/>
    @endif
    <div class="freelancer-showcase-actions">
      <a class="freelancer-showcase-listings" href="{{ $profileUrl }}#published-work">
        <x-icon name="briefcase" size="18"/>
        {{ number_format($freelancer->public_listings_count) }} approved {{ \Illuminate\Support\Str::plural('listing', $freelancer->public_listings_count) }}
      </a>
      <a class="btn freelancer-showcase-profile" href="{{ $profileUrl }}" aria-label="View profile for {{ $freelancer->name }}">View profile</a>
    </div>
  </div>
</article>
