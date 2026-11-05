@extends('layouts.dashboard')
@section('title','Review Queue')
@section('content')
<div class="page-intro"><div class="eyebrow">Marketplace moderation</div><h1>Review Queue</h1><p>Inspect submissions and approve or reject them before publication.</p></div>
<section class="panel">
  <div class="panel-head"><h2>Pending listings <span class="count">{{ $listings->total() }}</span></h2></div>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Listing ID</th><th>Title</th><th>Creator</th><th>Type</th><th>Submitted</th><th>Action</th></tr></thead><tbody>
  @forelse($listings as $listing)
    <tr><td class="mono">{{ $listing->public_id }}</td><td><strong>{{ $listing->title }}</strong></td><td>{{ $listing->creator?->name }}</td><td>{{ ucfirst($listing->type) }}</td><td>{{ $listing->created_at->format('d M Y') }}</td><td>
      <button type="button" class="btn subtle compact" data-modal-open="review-{{ $listing->id }}"><x-icon name="settings" size="15"/> Configure</button>
      <dialog id="review-{{ $listing->id }}" class="config-dialog wide-dialog">
        <div class="dialog-heading"><h2>{{ $listing->title }}</h2><p>Submission {{ $listing->public_id }} by {{ $listing->creator?->name }}</p></div>
        <strong>Submitted description</strong><p class="review-text">{{ $listing->description }}</p>
        @if($listing->type === 'product')<strong>Product license</strong><p class="review-text">{{ $listing->license_terms }}</p>@endif
        <form method="post" action="{{ route('dashboard.reviews.update',$listing) }}">
          @csrf @method('PATCH')
          <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button>
            <button type="submit" class="btn subtle" name="decision" value="rejected">Reject</button>
            <button type="submit" class="btn primary" name="decision" value="published">Publish</button></div>
        </form>
      </dialog>
    </td></tr>
  @empty<tr><td colspan="6" class="empty-cell">No listings currently await review.</td></tr>@endforelse
  </tbody></table></div><x-pagination :paginator="$listings"/>
</section>
@endsection
