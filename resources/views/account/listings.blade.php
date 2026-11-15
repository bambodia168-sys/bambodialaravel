@extends('layouts.workspace')
@section('workspace','creator')
@section('title','My Listings')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Creator studio</div><h1>My listings</h1><p>Submit original work for review and track publication status.</p></div>
  <p><a class="btn subtle" href="{{ route('studio.products.index') }}">Manage digital products and licenses</a></p>
  <section class="panel">
    <div class="panel-head">
      <h2>{{ $archived ? 'Archived listings' : 'Submitted listings' }} <span class="count">{{ $listings->total() }}</span></h2>
      @if(\App\Models\FeatureFlag::isEnabled('creator_submissions'))
        <a class="btn primary" href="{{ route('builder', ['type' => 'project']) }}">Create listing</a>
      @endif
    </div>
    <div class="workspace-queue-toolbar">
      <nav class="workspace-queue-tabs" aria-label="Listings by status">
        <a href="{{ route('studio.listings') }}" @if(!$archived) class="is-active" aria-current="page" @endif>Listings</a>
        <a href="{{ route('studio.listings', ['status' => 'archived']) }}" @if($archived) class="is-active" aria-current="page" @endif>Archived</a>
      </nav>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Listing ID</th><th>Title</th><th>Type</th><th>Status</th><th>Submitted</th><th>Action</th></tr></thead>
        <tbody>
          @forelse($listings as $listing)
            @php
              $actions = array_filter([
                  'archived' => ['Archive', 'archive', 'Hide this listing from the marketplace and move it to Archived.'],
                  'draft' => ['Draft', 'edit', 'Move this listing to Draft so you can edit and resubmit it.'],
                  'deleted' => ['Delete', 'trash-2', 'Remove this listing from the marketplace and from your listings.'],
              ], fn ($status) => $listing->canCreatorSetStatus($status), ARRAY_FILTER_USE_KEY);
            @endphp
            <tr>
              <td class="mono">{{ $listing->public_id }}</td>
              <td><strong>{{ $listing->title }}</strong></td>
              <td>{{ ucfirst($listing->type) }}</td>
              <td><span class="role-pill">{{ ucfirst(str_replace('_', ' ', $listing->status)) }}</span></td>
              <td>{{ $listing->created_at->format('d M Y') }}</td>
              <td>
                <x-row-action-menu :id="'account-listing-'.$listing->id" :label="$listing->title">
                  <a href="{{ $listing->builderUrl() }}"><x-icon name="file-text" size="16"/> Details</a>
                  @if($publicListingIds->has($listing->id))
                    <a href="{{ $listing->publicUrl() }}"><x-icon name="eye" size="16"/> Views</a>
                  @endif
                  @foreach($actions as $status => [$label, $icon, $description])
                    <button type="button" @class(['is-danger' => $status === 'deleted'])
                      data-modal-open="listing-{{ $listing->id }}-{{ $status }}"
                      aria-haspopup="dialog" aria-controls="listing-{{ $listing->id }}-{{ $status }}">
                      <x-icon :name="$icon" size="16"/> {{ $label }}
                    </button>
                  @endforeach
                </x-row-action-menu>
                @foreach($actions as $status => [$label, $icon, $description])
                  @php($dialogId = 'listing-'.$listing->id.'-'.$status)
                  <dialog class="config-dialog" id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title">
                    <form method="post" action="{{ route($status === 'deleted' ? 'studio.listings.destroy' : 'studio.listings.status', $listing) }}">
                      @csrf
                      @method($status === 'deleted' ? 'DELETE' : 'PATCH')
                      @if($status === 'deleted' && $archived)
                        <input type="hidden" name="from" value="archived">
                      @endif
                      @if($status !== 'deleted')
                        <input type="hidden" name="status" value="{{ $status }}">
                      @endif
                      <h2 id="{{ $dialogId }}-title">{{ $status === 'draft' ? 'Move to draft' : $label.' listing' }}</h2>
                      <p><strong>{{ $listing->title }}</strong></p>
                      <p>{{ $description }}</p>
                      <div class="dialog-actions">
                        <button type="button" class="btn subtle" data-modal-close autofocus>Cancel</button>
                        <button type="submit" class="btn {{ $status === 'deleted' ? 'danger' : 'primary' }}">
                          {{ $status === 'draft' ? 'Move to draft' : $label }}
                        </button>
                      </div>
                    </form>
                  </dialog>
                @endforeach
              </td>
            </tr>
          @empty
            <tr><td colspan="6" class="empty-cell">{{ $archived ? 'No archived listings.' : 'No listings have been submitted by this creator.' }}</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$listings"/>
  </section>
</div>
@endsection
