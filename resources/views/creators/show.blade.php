@extends('layouts.main')
@section('title',$creator->name.' · Creator Portfolio')
@section('content')
@php
  $personal=$profile ? $creator->userProfile : null;
  $awards=\App\Support\CreatorBadgeAwards::earned($creator);
  $expertise=$items->get('expertise',collect());
  $languages=$items->get('language',collect());
  $links=$items->get('link',collect());
@endphp
<div class="container inner-page creator-public-profile profile-v3">
  <div class="profile-v3-grid">
    <aside class="profile-v3-sidebar" aria-label="Creator profile and achievements">
      <div class="profile-v3-avatar">
        @if($personal?->avatar_path)
          <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($personal->avatar_path) }}" alt="{{ $creator->name }} profile photo" width="360" height="360" decoding="async">
        @else
          <span aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($creator->name,0,1)) }}</span>
        @endif
      </div>
      <div class="profile-v3-name-viewport" data-profile-name-viewport title="{{ $creator->name }}">
        <h1 data-profile-scroll-name>{{ $creator->name }}</h1>
      </div>
      <button type="button" class="profile-v3-copy-id" data-profile-copy="{{ $creator->public_id }}" aria-label="Copy user ID {{ $creator->public_id }}">
        <span>User ID</span><strong>{{ $creator->public_id }}</strong><small>Click to copy</small>
      </button>
      <div class="profile-v3-side-facts">
        @if($creator->account_review_status==='verified' && $creator->account_status==='active')
          <div><x-icon name="shield-check" size="17"/><span title="Account manually reviewed; identity and professional credentials require separate verification.">Account Reviewed</span></div>
        @endif
        @if($personal?->public_location)
          <div><x-icon name="map-pin" size="17"/><span>{{ $personal->public_location }}</span></div>
        @endif
        <div><x-icon name="calendar" size="17"/><span>Joined {{ $creator->created_at->format('M Y') }}</span></div>
        @if($personal?->availability)
          <div><x-icon name="circle-dot" size="17"/><span>{{ ucfirst($personal->availability) }} for projects</span></div>
        @endif
      </div>
      <section class="profile-v3-awards" aria-label="Awarded creator badges">
        <div class="profile-v3-section-head"><h2>Creator Badges</h2><span>{{ $awards->count() }}</span></div>
        @if($awards->isNotEmpty())
          <x-creator-badges :badges="$awards"/>
        @else
          <p class="profile-v3-muted">No badges awarded yet.</p>
        @endif
      </section>
      @if($contactListing)
        <a class="btn primary profile-v3-contact" href="{{ $contactListing->publicUrl() }}"><x-icon name="message-square" size="17"/> Contact via listing</a>
        <p class="profile-v3-note">Open the listing to send a private inquiry.</p>
      @else
        <div class="profile-v3-contact-disabled" aria-disabled="true"><x-icon name="message-square" size="17"/> Contact unavailable</div>
        <p class="profile-v3-note">Contact is available through eligible published service listings.</p>
      @endif
      @auth
        @if((int)auth()->id()===(int)$creator->id)
          <a href="{{ route('account.profile.edit') }}" class="btn subtle profile-v3-edit"><x-icon name="pencil" size="16"/> Edit my profile</a>
        @endif
      @endauth
    </aside>
    <main class="profile-v3-main" id="profile-content">
      <section class="profile-v3-panel profile-v3-intro">
        <div class="profile-v3-eyebrow"><x-icon name="user-round" size="15"/> Creator portfolio</div>
        @if($profile)
          <h2>{{ $profile->headline }}</h2>
          <p class="profile-v3-biography">{{ $profile->biography }}</p>
          @if($profile->website_url)
            <a href="{{ $profile->website_url }}" class="profile-v3-external" rel="noopener noreferrer nofollow" target="_blank"><x-icon name="globe" size="17"/> Visit portfolio website</a>
          @endif
        @else
          <h2>Creator achievements &amp; portfolio</h2>
          <p class="profile-v3-muted">This creator has not published a personal biography. Awarded achievements and approved marketplace work remain available below.</p>
        @endif
      </section>
      <section class="profile-v3-panel" aria-label="Profile highlights">
        <div class="profile-v3-section-head"><h2><x-icon name="chart-no-axes-column" size="19"/> Profile Highlights</h2></div>
        <div class="profile-v3-metrics">
          <div><x-icon name="layers" size="23"/><strong>{{ number_format($listings->total()) }}</strong><span>Published work</span></div>
          <div><x-icon name="award" size="23"/><strong>{{ $awards->count() }}</strong><span>Earned awards</span></div>
          <div><x-icon name="calendar-check" size="23"/><strong>{{ $creator->created_at->format('Y') }}</strong><span>Member since</span></div>
        </div>
      </section>
      @if($profile && $expertise->isNotEmpty())
        <section class="profile-v3-panel">
          <div class="profile-v3-section-head"><h2><x-icon name="briefcase" size="19"/> Expertise &amp; Services</h2></div>
          <div class="profile-v3-tags">
            @foreach($expertise as $entry)
              <span class="profile-v3-tag"><x-icon name="check" size="15"/><span>{{ $entry->label }}</span>@if($entry->detail)<small>{{ $entry->detail }}</small>@endif</span>
            @endforeach
          </div>
        </section>
      @endif
      @if($profile && ($languages->isNotEmpty() || $links->isNotEmpty()))
        <div class="profile-v3-pair">
          @if($languages->isNotEmpty())
            <section class="profile-v3-panel"><div class="profile-v3-section-head"><h2><x-icon name="languages" size="19"/> Languages</h2></div>
              <div class="profile-v3-simple-list">
                @foreach($languages as $entry)
                  <div><strong>{{ $entry->label }}</strong>@if($entry->detail)<span>{{ $entry->detail }}</span>@endif</div>
                @endforeach
              </div>
            </section>
          @endif
          @if($links->isNotEmpty())
            <section class="profile-v3-panel"><div class="profile-v3-section-head"><h2><x-icon name="link" size="19"/> Contact &amp; Links</h2></div>
              <div class="profile-v3-link-list">
                @foreach($links as $entry)
                  <a href="{{ $entry->url }}" target="_blank" rel="noopener noreferrer nofollow"><x-icon name="external-link" size="16"/><span>{{ $entry->label }}</span></a>
                @endforeach
              </div>
            </section>
          @endif
        </div>
      @endif
      <p class="profile-v3-disclosure">Creator-submitted information and external links are not independently verified. Badges represent platform activity or explicit administrator awards.</p>
    </main>
  </div>
  <section class="marketplace-section">
    <div class="marketplace-section-heading">
      <div><span class="eyebrow">Approved marketplace activity</span><h2>Published work <span class="count">{{ $listings->total() }}</span></h2></div>
    </div>
    @if($listings->isNotEmpty())
      <div class="marketplace-card-grid">
        @foreach($listings as $listing)
          <x-listing-card :listing="$listing" :is-saved="in_array((int)$listing->id,$savedIds,true)"/>
        @endforeach
      </div>
    @else
      <div class="marketplace-empty">This creator has not published an eligible listing yet.</div>
    @endif
    <x-pagination :paginator="$listings"/>
  </section>
</div>
@endsection
