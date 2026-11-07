@extends('layouts.workspace')
@section('workspace','creator')
@section('title','My Listings')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Creator studio</div><h1>My listings</h1><p>Submit original work for review and track publication status.</p></div>
  <p><a class="btn subtle" href="{{ route('studio.products.index') }}">Manage digital products and licenses</a></p><section class="panel">
    <div class="panel-head"><h2>Submitted listings <span class="count">{{ $listings->total() }}</span></h2>@if(\App\Models\FeatureFlag::isEnabled('creator_submissions'))<a class="btn primary" href="{{ route('builder', ['type' => 'project']) }}">Create listing</a>@endif</div>
    <div class="table-scroll">
      <table class="data-table"><thead><tr><th>Listing ID</th><th>Title</th><th>Type</th><th>Status</th><th>Submitted</th><th>Action</th></tr></thead><tbody>
      @forelse($listings as $listing)
      <tr><td class="mono">{{ $listing->public_id }}</td><td><strong>{{ $listing->title }}</strong></td><td>{{ ucfirst($listing->type) }}</td><td><span class="role-pill">{{ ucfirst($listing->status) }}</span></td><td>{{ $listing->created_at->format('d M Y') }}</td><td>
      <x-row-action-menu :id="'account-listing-'.$listing->id" :label="$listing->title">
        <a href="{{ url('/builder?='.$listing->public_id) }}"><x-icon name="edit" size="16"/> {{ in_array($listing->status,['draft','rejected'],true) ? 'Edit in builder' : 'Details' }}</a>
        @if($listing->status === 'published')<a href="{{ $listing->publicUrl() }}"><x-icon name="eye" size="16"/> View</a>@endif
      </x-row-action-menu>
      </td></tr>
      @empty<tr><td colspan="6" class="empty-cell">No listings have been submitted by this creator.</td></tr>@endforelse
      </tbody></table>
    </div><x-pagination :paginator="$listings"/>
  </section>
</div>
@endsection
