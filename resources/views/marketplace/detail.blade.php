@extends('layouts.main')
@section('title',$listing->title)
@section('content')
<div class="container inner-page listing-detail">
  <nav class="marketplace-breadcrumb" aria-label="Breadcrumb">
    <a href="{{ route('home') }}">Home</a><x-icon name="chevron-right" size="14"/>
    <a href="{{ route('marketplace.search',['type'=>$listing->type]) }}">{{ ucfirst($listing->type) }}</a>
    <x-icon name="chevron-right" size="14"/><span>{{ $listing->public_id }}</span>
  </nav>
  <div class="eyebrow">{{ ucfirst($listing->type) }} · ID {{ $listing->public_id }}</div>
  <h1>{{ $listing->title }}</h1>
  <p class="text-muted">Published by
    @if($listing->creator?->creatorProfile?->is_public)
      <a class="marketplace-creator-link" href="{{ route('creators.show',$listing->creator->public_id) }}">{{ $listing->creator->name }}</a>
    @else
      {{ $listing->creator?->name }}
    @endif
    @if($listing->category) · Category: {{ $listing->category->name }} @endif
  </p>
  @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
  <div class="marketplace-detail-actions">
    @auth
      @if($isSaved)
        <form method="post" action="{{ route('saved-listings.destroy',$listing) }}">
          @csrf @method('DELETE')
          <button class="btn subtle" type="submit"><x-icon name="star" size="16"/> Saved — remove from collection</button>
        </form>
      @else
        <form method="post" action="{{ route('saved-listings.store',$listing) }}">
          @csrf
          <button class="btn primary" type="submit"><x-icon name="star" size="16"/> Save listing</button>
        </form>
      @endif
      <a class="btn subtle" href="{{ route('account.saved-listings') }}">My saved listings</a>
    @else
      <a class="btn subtle" href="{{ route('login') }}">Sign in to save this listing</a>
    @endauth
  </div>
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
  @if(in_array($listing->type,['service','project','freelance'],true))
    @auth
      @if(in_array(auth()->user()->role,['customer','subscriber'],true) && (int)auth()->id() !== (int)$listing->user_id)
      <section class="panel">
        <div class="panel-head"><h2>Contact this creator privately</h2>
          <button type="button" class="btn primary" data-modal-open="request-service">Start an inquiry</button>
        </div>
        <div class="listing-body"><p>Describe your project scope and an optional budget. Only you and the creator can read your discussion.</p></div>
      </section>
      <dialog class="config-dialog wide-dialog" id="request-service">
        <form method="post" action="{{ route('inquiries.store',$listing) }}">@csrf
          <h2>Request a service or project discussion</h2>
          <label for="inquiry-subject">Subject</label><input id="inquiry-subject" name="subject" minlength="6" maxlength="180" required>
          <label for="inquiry-body">Project brief</label><textarea id="inquiry-body" name="body" minlength="40" maxlength="5000" rows="7" required></textarea>
          <label for="inquiry-budget">Proposed budget (USD, optional)</label><input id="inquiry-budget" name="proposed_budget" type="number" min="0" step="0.01">
          <p class="hint">The inquiry starts a private conversation only. There is no payment processing or automatic contract.</p>
          <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Send inquiry</button></div>
        </form>
      </dialog>
      @endif
    @else
      <p><a class="btn subtle" href="{{ route('login') }}">Sign in to contact this creator</a></p>
    @endauth
  @endif
  <p class="hint">Saving or contacting a creator does not constitute a purchase, reservation or payment. Checkout, escrow, payouts and real-money transactions remain disabled.</p>
  @if($relatedListings->isNotEmpty())
    <section class="marketplace-section">
      <div class="marketplace-section-heading">
        <div><span class="eyebrow">Continue exploring</span><h2>Related approved listings</h2></div>
      </div>
      <div class="marketplace-card-grid">
        @foreach($relatedListings as $related)
          <x-listing-card :listing="$related" :is-saved="in_array((int)$related->id,$savedRelatedIds,true)"/>
        @endforeach
      </div>
    </section>
  @endif
  <a class="btn subtle" href="{{ route('marketplace.search') }}">Back to listings</a>
</div>
@endsection
