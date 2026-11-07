@extends('layouts.workspace')
@section('workspace','account')
@section('title','Saved Listings')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Account · Private collection</div>
    <h1>Saved Listings</h1>
    <p>Keep track of digital products, creators' services, projects, and freelance offers. Your collection is visible only to your account.</p>
  </div>
  <section class="panel">
    <div class="panel-head"><h2>My collection <span class="count">{{ $saved->total() }}</span></h2>
      <a href="{{ route('marketplace.search') }}" class="btn subtle"><x-icon name="search" size="16"/> Explore marketplace</a>
    </div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Listing</th><th>Category</th><th>Creator</th><th>Saved on</th><th>Actions</th></tr></thead>
      <tbody>
      @forelse($saved as $entry)
        <tr>
          @if($entry->listing)
            <td><strong>{{ $entry->listing->title }}</strong>
              <div class="text-muted">{{ ucfirst($entry->listing->type) }} · {{ $entry->listing->public_id }}</div></td>
            <td>{{ $entry->listing->category?->name ?? 'Uncategorized' }}</td>
            <td>
              @if($entry->listing->creator?->creatorProfile?->is_public)
                <a href="{{ route('creators.show',$entry->listing->creator->public_id) }}">{{ $entry->listing->creator->name }}</a>
              @else
                {{ $entry->listing->creator?->name }}
              @endif
            </td>
          @else
            <td><strong>Listing unavailable</strong><div class="text-muted">No longer publicly approved or published</div></td>
            <td>—</td><td>—</td>
          @endif
          <td>{{ $entry->created_at->format('d M Y') }}</td>
          <td><div class="toolbar-actions">
            @if($entry->listing)<a class="btn subtle compact" href="{{ $entry->listing->publicUrl() }}">View</a>@endif
            <form method="post" action="{{ route('saved-listings.destroy',$entry->marketplace_listing_id) }}">
              @csrf @method('DELETE')
              <button class="btn subtle compact" type="submit">Remove</button>
            </form>
          </div></td>
        </tr>
      @empty
        <tr><td colspan="5" class="empty-cell">You have not saved a listing yet. Use Save on an approved listing to add it here.</td></tr>
      @endforelse
      </tbody>
    </table></div>
    <x-pagination :paginator="$saved"/>
  </section>
  <p class="hint">Saving a listing is a private bookmark. It does not grant file access, reserve a service, confirm a purchase, or initiate a transaction.</p>
</div>
@endsection
