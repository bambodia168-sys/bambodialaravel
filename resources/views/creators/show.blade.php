@extends('layouts.main')
@section('title',$creator->name.' · Creator Portfolio')
@section('content')
<div class="container inner-page creator-public-profile">
  <section class="creator-public-hero">
    <span class="eyebrow">Creator achievements · {{ $creator->public_id }}</span>
    <div class="creator-public-heading">
      <div class="creator-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($creator->name,0,1)) }}</div>
      <div>
        <h1>{{ $creator->name }}</h1>
        @if($creator->account_review_status==='verified' && $creator->account_status==='active')
          <span class="account-reviewed-public" title="This platform account was reviewed manually by an administrator. Email ownership, legal identity and professional credentials are separate checks.">
            <x-icon name="shield-check" size="15"/> Account Reviewed
          </span>
        @endif
        <x-creator-badges :badges="\App\Support\CreatorBadgeAwards::earned($creator)"/>
        @if($profile)
          <h2>{{ $profile->headline }}</h2>
        @endif
      </div>
    </div>
    @if($profile)
      <p class="review-text">{{ $profile->biography }}</p>
    @else
      <p class="hint">This public badge profile displays awarded achievements and approved marketplace work only. Private biography and portfolio information are not published.</p>
    @endif
    <div class="toolbar-actions">
      @if($profile?->website_url)
        <a class="btn subtle" href="{{ $profile->website_url }}" target="_blank" rel="noopener noreferrer"><x-icon name="globe" size="16"/> Portfolio website</a>
      @endif
      <a class="btn primary" href="{{ route('marketplace.search') }}">Explore all listings</a>
    </div>
    <p class="hint">Creator-submitted biographical information and external links are not independently verified. Badges represent either platform activity or an explicitly awarded platform review.</p>
  </section>
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
