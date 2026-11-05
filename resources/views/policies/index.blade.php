@extends('layouts.main')
@section('title','Policy Center')
@section('content')
<div class="container inner-page policy-index">
  <div class="policy-hero"><div class="eyebrow">Bambodia policies</div><h1>Policy Center</h1><p>Read the current policies published by Bambodia's administrators.</p>
    <form method="get" action="{{ route('policy-center') }}" class="hero-search"><x-icon name="search"/><input name="q" value="{{ $search }}" type="search" placeholder="Find a policy" aria-label="Find policy"><button class="btn primary" type="submit">Search policies</button></form>
  </div>
  <div class="policy-tiles">
  @forelse($policies as $policy)
    <a class="policy-tile" href="{{ $policy->publicUrl() }}"><x-icon name="book" size="32"/><div><strong>{{ $policy->title }}</strong><span>{{ ucfirst($policy->category) }}</span></div><span class="policy-number">{{ str_pad((string)($loop->iteration + ($policies->currentPage()-1)*10),2,'0',STR_PAD_LEFT) }}</span></a>
  @empty
    <p class="text-muted">No published policies match this search.</p>
  @endforelse
  </div>
  <x-pagination :paginator="$policies"/>
</div>
@endsection
