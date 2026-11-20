@extends('layouts.main')
@section('title','Browse Creators')
@push('page-styles')
  <link rel="stylesheet" href="{{ asset('css/public-creator-cards.css') }}?v=2">
@endpush
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Bambodia talent network</div><h1>Explore Creators</h1>
    <p>Discover creators through their public portfolios and approved marketplace work.</p>
  </div>
  <section class="panel">
    <div class="panel-head"><h2>Public profiles <span class="count">{{ $creators->total() }}</span></h2>
      <form method="get" action="{{ route('creators.index') }}" class="table-search">
        <x-icon name="search" size="17"/>
        <input name="q" type="search" maxlength="120" value="{{ $search }}" aria-label="Search creator names and skills" placeholder="Name, skill or specialty">
        <button type="submit" class="btn subtle">Search</button>
      </form>
    </div>
    <div class="public-creator-grid public-creator-grid--directory">
      @forelse($creators as $creator)
        <x-public-creator-card :creator="$creator" :badges="\App\Support\CreatorBadgeAwards::earned($creator, $badgeCatalog)"/>
      @empty
        <div class="marketplace-empty">No public creator profiles match your search.</div>
      @endforelse
    </div>
    <x-pagination :paginator="$creators"/>
  </section>
  <p class="hint">Creator biographies and qualifications are self-reported. Public status does not indicate independent credential or identity verification.</p>
</div>
@endsection
