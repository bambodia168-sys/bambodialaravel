@extends('layouts.dashboard')
@section('title',ucfirst($type).' Manager')
@section('content')
<div class="page-intro"><div class="eyebrow">Marketplace Governance</div><h1>{{ ucfirst($type) }} Manager</h1>
<p>Review submitted {{ $type }} listings from real creator accounts. Published listings are accessible through their nine-digit canonical URLs.</p></div>
<section class="panel"><div class="panel-head"><h2>{{ ucfirst($type) }} listings <span class="count">{{ $listings->total() }}</span></h2>
<form method="get" class="table-search"><input name="q" value="{{ $search }}" placeholder="Search title or public ID" aria-label="Search listings">
<select name="status" class="table-select" aria-label="Status">
@foreach(['all'=>'All','pending'=>'Pending','published'=>'Published','rejected'=>'Rejected','draft'=>'Draft'] as $key=>$value)<option value="{{ $key }}" @selected($status===$key)>{{ $value }}</option>@endforeach
</select><button class="btn subtle" type="submit">Filter</button></form></div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Public ID</th><th>Title</th><th>Creator</th><th>Category</th><th>Price</th><th>Status</th><th>Action</th></tr></thead><tbody>
@forelse($listings as $listing)<tr>
<td class="mono">{{ $listing->public_id }}</td><td><strong>{{ $listing->title }}</strong></td><td>{{ $listing->creator?->name }}</td><td>{{ $listing->category?->name ?? 'Uncategorized' }}</td>
<td>{{ $listing->price===null?'—':'$'.number_format((float)$listing->price,2) }}</td><td><span class="role-pill">{{ ucfirst($listing->status) }}</span></td>
<td><button type="button" class="btn subtle compact" data-modal-open="listing-{{ $listing->id }}"><x-icon name="settings" size="16"/> Configure</button>
<dialog class="config-dialog wide-dialog" id="listing-{{ $listing->id }}"><h2>{{ $listing->title }}</h2><p>ID {{ $listing->public_id }} · {{ $listing->creator?->name }}</p>
<p class="review-text">{{ $listing->description }}</p>
@if($listing->status==='pending')
<form method="post" action="{{ route('dashboard.reviews.update',$listing) }}">@csrf @method('PATCH')
<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn subtle" type="submit" name="decision" value="rejected">Reject</button><button class="btn primary" type="submit" name="decision" value="published">Publish</button></div></form>
@else<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Close</button>@if($listing->status==='published')<a class="btn primary" href="{{ $listing->publicUrl() }}">View</a>@endif</div>@endif
</dialog></td></tr>
@empty<tr><td colspan="7" class="empty-cell">There are no {{ $type }} listings matching this filter.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$listings"/></section>
@endsection
