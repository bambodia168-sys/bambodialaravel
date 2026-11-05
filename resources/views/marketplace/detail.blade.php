@extends('layouts.main')
@section('title',$listing->title)
@section('content')
<div class="container inner-page listing-detail">
  <div class="eyebrow">{{ ucfirst($listing->type) }} · ID {{ $listing->public_id }}</div>
  <h1>{{ $listing->title }}</h1>
  <p class="text-muted">Published by {{ $listing->creator?->name }}</p>
  @if($listing->type === 'product' && $listing->coverAsset)
  <figure class="product-cover"><img src="{{ $listing->coverAsset->previewUrl() }}" alt="{{ $listing->coverAsset->alt_text }}" loading="lazy"></figure>
  @endif
  @if($listing->type === 'product' && $listing->price !== null)
  <p class="listed-price">Listed price: ${{ number_format((float)$listing->price, 2) }} USD</p>
  @endif
  <section class="panel">
    <div class="panel-head"><h2>Details</h2><span class="role-pill">Published</span></div>
    <div class="listing-body">{!! nl2br(e($listing->description)) !!}</div>
  </section>
  @if($listing->type === 'product')
    <section class="panel">
      <div class="panel-head"><h2>Product license</h2>
        @if($listing->licenseDefinition)<a class="btn subtle compact" href="{{ $listing->licenseDefinition->publicUrl() }}">Read full license</a>@endif
      </div>
      <div class="listing-body">
        @if($listing->licenseDefinition)<strong>{{ $listing->licenseDefinition->name }} · {{ $listing->licenseDefinition->version }}</strong>@endif
        @if($listing->license_terms)<p>{{ $listing->license_terms }}</p>@endif
        <p class="hint">Licensing rights are governed by the linked published license definition.</p>
      </div>
    </section>
  @endif
  <p class="text-muted">Purchasing and external payment acceptance are not enabled until independently verified transaction processing and license delivery are implemented.</p>
  <a class="btn subtle" href="{{ route('marketplace.search') }}">Back to listings</a>
</div>
@endsection
