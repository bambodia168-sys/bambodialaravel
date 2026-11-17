@extends('layouts.main')
@section('title','Browse Creators')
@push('page-styles')
  <link rel="stylesheet" href="{{ asset('css/freelancer-discovery.css') }}?v=1">
@endpush
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Bambodia talent network</div><h1>Explore Creators</h1>
    <p>Discover people who have chosen to publish their professional introductions and see their approved marketplace work.</p>
  </div>
  <section class="panel">
    <div class="panel-head"><h2>Public profiles <span class="count">{{ $creators->total() }}</span></h2></div>
    <form method="get" action="{{ route('creators.index') }}" class="freelancer-directory-filters" aria-label="Filter public profiles">
      <label>Search creators
        <input name="q" type="search" maxlength="120" value="{{ $search }}" placeholder="Name, skill or specialty">
      </label>
      <label>Skill
        <input name="skill" type="search" maxlength="120" value="{{ $skill }}" placeholder="e.g. Laravel">
      </label>
      <label>Category
        <select name="category">
          <option value="">All categories</option>
          @foreach($categories as $option)
            <option value="{{ $option->public_id }}" @selected($category === (string) $option->public_id)>{{ $option->name }} · {{ ucfirst($option->type) }}</option>
          @endforeach
        </select>
      </label>
      <label>Location
        <input name="location" type="search" maxlength="120" value="{{ $location }}" placeholder="City or country">
      </label>
      <div class="freelancer-directory-filter-actions">
        <button type="submit" class="btn primary"><x-icon name="search" size="16"/> Search</button>
        @if($search !== '' || $skill !== '' || $category !== '' || $location !== '')
          <a class="btn subtle" href="{{ route('creators.index') }}">Clear filters</a>
        @endif
      </div>
    </form>
    <div class="creator-directory-grid">
      @forelse($creators as $creator)
        <article class="creator-directory-card">
          <div class="creator-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($creator->name,0,1)) }}</div>
          <div class="eyebrow">Public ID {{ $creator->public_id }}</div>
          <h3><a href="{{ route('creators.show',$creator->public_id) }}">{{ $creator->name }}</a></h3>
          <strong>{{ $creator->creatorProfile?->is_public ? $creator->creatorProfile->headline : 'Creator achievements' }}</strong>
          <x-creator-badges :badges="\App\Support\CreatorBadgeAwards::earned($creator)" variant="compact"/>
          @if($creator->creatorProfile?->is_public)
            <p>{{ \Illuminate\Support\Str::limit($creator->creatorProfile->biography,160) }}</p>
          @endif
          <div class="creator-directory-foot">
            <span>{{ $creator->public_listings_count }} approved listings</span>
            <a href="{{ route('creators.show',$creator->public_id) }}" class="btn subtle compact">View portfolio</a>
          </div>
        </article>
      @empty
        <div class="marketplace-empty">No public creator profiles match your search.</div>
      @endforelse
    </div>
    <x-pagination :paginator="$creators"/>
  </section>
  <p class="hint">Creator biographies and qualifications are self-reported. Public status does not indicate independent credential or identity verification.</p>
</div>
@endsection
