@extends('layouts.main')
@section('title','Search Marketplace')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Marketplace discovery</div><h1>Browse listings</h1><p>Only approved, published listings appear here.</p></div>
  <section class="panel">
    <div class="panel-head">
      <h2>Listings <span class="count">{{ $listings->total() }}</span></h2>
      <form class="table-search" action="{{ route('marketplace.search') }}" method="get">
        <x-icon name="search" size="18"/>
        <input name="q" type="search" value="{{ $query }}" placeholder="Search listings" aria-label="Search marketplace">
        <select name="type" aria-label="Listing type" class="table-select">
          <option value="">All types</option>
          @foreach(\App\Models\MarketplaceListing::TYPES as $option)
            <option value="{{ $option }}" @selected($type===$option)>{{ ucfirst($option) }}</option>
          @endforeach
        </select>
        <select name="category" class="table-select" aria-label="Marketplace category">
          <option value="">All categories</option>
          @foreach($categories as $category)
            <option value="{{ $category->public_id }}" @selected($selectedCategory===$category->public_id)>{{ ucfirst($category->type) }} · {{ $category->name }}</option>
          @endforeach
        </select>
        <button type="submit" class="btn subtle">Search</button>
      </form>
    </div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Title</th><th>Type / Category</th><th>Creator</th><th>Listed Price</th><th>Published</th><th>View</th></tr></thead>
      <tbody>
        @forelse($listings as $listing)
          <tr>
            <td><div class="catalog-row-title">
              @if($listing->type==='product' && $listing->coverAsset)
                <img class="media-thumb" src="{{ $listing->coverAsset->previewUrl() }}" alt="{{ $listing->coverAsset->alt_text }}" width="56" height="56" loading="lazy">
              @endif
              <strong>{{ $listing->title }}</strong>
            </div></td>
            <td><span class="role-pill">{{ ucfirst($listing->type) }}</span><div class="text-muted">{{ $listing->category?->name ?? 'Uncategorized' }}</div></td>
            <td>{{ $listing->creator?->name }}</td>
            <td>{{ $listing->price===null ? '—' : 'USD '.number_format((float)$listing->price,2) }}</td>
            <td>{{ $listing->updated_at->format('d M Y') }}</td>
            <td><a class="btn subtle compact" href="{{ $listing->publicUrl() }}">View listing</a></td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty-cell">There are no published listings matching these filters.</td></tr>
        @endforelse
      </tbody>
    </table></div>
    <x-pagination :paginator="$listings"/>
  </section>
</div>
@endsection
