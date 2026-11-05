@extends('layouts.main')
@section('title','My Listings')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Creator studio</div><h1>My listings</h1><p>Submit original work for review and track publication status.</p></div>
  @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
  <section class="panel">
    <div class="panel-head"><h2>Submitted listings <span class="count">{{ $listings->total() }}</span></h2><button type="button" class="btn primary" data-modal-open="create-listing">Submit listing</button></div>
    <div class="table-scroll">
      <table class="data-table"><thead><tr><th>Listing ID</th><th>Title</th><th>Type</th><th>Status</th><th>Submitted</th><th>Action</th></tr></thead><tbody>
      @forelse($listings as $listing)
      <tr><td class="mono">{{ $listing->public_id }}</td><td><strong>{{ $listing->title }}</strong></td><td>{{ ucfirst($listing->type) }}</td><td><span class="role-pill">{{ ucfirst($listing->status) }}</span></td><td>{{ $listing->created_at->format('d M Y') }}</td><td>
      @if($listing->status === 'published')<a class="btn subtle compact" href="{{ $listing->publicUrl() }}">View</a>@else<span class="text-muted">In review workflow</span>@endif
      </td></tr>
      @empty<tr><td colspan="6" class="empty-cell">No listings have been submitted by this creator.</td></tr>@endforelse
      </tbody></table>
    </div><x-pagination :paginator="$listings"/>
  </section>
  <dialog id="create-listing" class="config-dialog wide-dialog">
    <form method="post" action="{{ route('studio.listings.store') }}">
      @csrf
      <div class="dialog-heading"><h2>Submit a listing</h2><p>Listings are reviewed before publication.</p></div>
      <label for="listing-type">Type</label>
      <select id="listing-type" name="type" required>@foreach(\App\Models\MarketplaceListing::TYPES as $type)<option value="{{ $type }}">{{ ucfirst($type) }}</option>@endforeach</select>
      <label for="listing-title">Title</label><input id="listing-title" name="title" required minlength="4" maxlength="180">
      <label for="listing-description">Description (40+ characters)</label><textarea id="listing-description" name="description" rows="5" minlength="40" required></textarea>
      <label for="listing-price">Listed price, USD (optional; checkout unavailable)</label><input id="listing-price" name="price" type="number" min="0" step="0.01">
      <label for="listing-license">License terms (required for digital products)</label><textarea id="listing-license" name="license_terms" rows="4" placeholder="Specify the rights granted, usage limitations, and redistribution rules."></textarea>
      <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Submit for review</button></div>
    </form>
  </dialog>
</div>
@endsection
