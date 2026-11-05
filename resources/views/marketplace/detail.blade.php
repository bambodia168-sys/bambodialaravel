@extends('layouts.main')
@section('title',$listing->title)
@section('content')
<div class="container inner-page listing-detail">
  <div class="eyebrow">{{ ucfirst($listing->type) }} · ID {{ $listing->public_id }}</div>
  <h1>{{ $listing->title }}</h1>
  <p class="text-muted">Published by {{ $listing->creator?->name }}</p>
  <section class="panel">
    <div class="panel-head"><h2>Details</h2><span class="role-pill">Published</span></div>
    <div class="listing-body">{!! nl2br(e($listing->description)) !!}</div>
  </section>
  @if($listing->type === 'product')
    <section class="panel"><div class="panel-head"><h2>License terms</h2></div>
      <div class="listing-body">{!! nl2br(e($listing->license_terms ?? '')) !!}</div>
    </section>
  @endif
  <p class="text-muted">Purchasing and external payment acceptance are not enabled until independently verified transaction processing and license delivery are implemented.</p>
  <a class="btn subtle" href="{{ route('marketplace.search') }}">Back to listings</a>
</div>
@endsection
