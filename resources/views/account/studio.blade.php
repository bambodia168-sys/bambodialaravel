@extends('layouts.workspace')
@section('workspace','creator')
@section('title','Creator Studio')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Creator workspace</div><h1>Creator Studio</h1>
    <p>Manage your submissions, digital products and private client conversations.</p></div>

  <div class="workspace-overview-grid" aria-label="Your creator activity">
    <x-workspace-metric label="Approved listings" :value="$stats['published']" icon="check" :href="route('studio.listings')" action="View listings"/>
    <x-workspace-metric label="Pending review" :value="$stats['pending']" icon="clipboard-check" :href="route('studio.listings')" action="Review status"/>
    <x-workspace-metric label="Drafts and rejections" :value="$stats['drafts']" icon="edit" :href="route('studio.listings')" action="Edit submissions"/>
    <x-workspace-metric label="Digital products" :value="$stats['products']" icon="package" :href="route('studio.products.index')" action="Manage products"/>
    <x-workspace-metric label="Product drafts and rejections" :value="$stats['product_drafts']" icon="edit" :href="route('studio.products.index')" action="Edit products"/>
    <x-workspace-metric label="Products awaiting review" :value="$stats['product_pending']" icon="clipboard-check" :href="route('studio.products.index')" action="Review status"/>
    <x-workspace-metric label="Open client inquiries" :value="$stats['inquiries']" icon="message" :href="route('studio.inquiries')" action="Reply to clients"/>
  </div>

  <section class="workspace-overview-block" aria-labelledby="creator-actions">
    <div class="workspace-section-head"><div><h2 id="creator-actions">Manage your work</h2>
      <p>Only your creator-owned resources are available here.</p></div></div>
    <div class="workspace-quick-actions">
      @if(\App\Models\FeatureFlag::isEnabled('creator_submissions'))
        <a class="btn primary" href="{{ route('builder', ['type'=>'product']) }}"><x-icon name="plus" size="17"/> Open Builder</a>
      @endif
      <a class="btn primary" href="{{ route('studio.products.index') }}"><x-icon name="package" size="17"/> Product Manager &amp; Releases</a>
      <a class="btn subtle" href="{{ route('studio.listings') }}"><x-icon name="layers" size="17"/> Listings &amp; Services</a>
      <a class="btn subtle" href="{{ route('studio.media.index') }}"><x-icon name="image" size="17"/> Media Library</a>
      <a class="btn subtle" href="{{ route('studio.profile.edit') }}"><x-icon name="user" size="17"/> Creator Profile</a>
      <a class="btn subtle" href="{{ route('contracts.index') }}"><x-icon name="file-check" size="17"/> Contracts</a>
      @if($user->role==='freelancer')
        <a class="btn subtle" href="{{ route('jobs.applied') }}"><x-icon name="send" size="17"/> My Proposals</a>
        <a class="btn subtle" href="{{ route('resume.edit') }}"><x-icon name="file-text" size="17"/> Resume Builder</a>
      @endif
    </div>
  </section>

  <div class="workspace-overview-columns">
    <section class="panel" aria-labelledby="creator-recent">
      <div class="panel-head"><div><h2 id="creator-recent">Your recent submissions</h2>
        <p>Publication status is taken from your own listings.</p></div>
        <a class="btn subtle compact" href="{{ route('studio.listings') }}">View all</a></div>
      <div class="workspace-overview-list">
        @forelse($recentListings as $listing)
          <a class="workspace-overview-row" href="{{ url('/builder?='.$listing->public_id) }}">
            <span class="workspace-overview-row-main"><strong>{{ $listing->title }}</strong>
            <small>{{ ucfirst($listing->type) }} · {{ ucfirst($listing->status) }}</small></span>
            <span class="workspace-overview-row-action">Manage <x-icon name="chevron-right" size="15"/></span>
          </a>
        @empty<p class="workspace-overview-empty">Your submitted listings will appear here.</p>
        @endforelse
      </div>
    </section>
    <section class="panel" aria-labelledby="creator-messages">
      <div class="panel-head"><div><h2 id="creator-messages">Open client conversations</h2>
        <p>Private inquiries assigned to your creator account.</p></div>
        <a class="btn subtle compact" href="{{ route('studio.inquiries') }}">View all</a></div>
      <div class="workspace-overview-list">
        @forelse($openInquiries as $inquiry)
          <a class="workspace-overview-row" href="{{ route('inquiries.show',$inquiry) }}">
            <span class="workspace-overview-row-main"><strong>{{ $inquiry->subject }}</strong>
            <small>Open inquiry · {{ $inquiry->updated_at->format('d M Y') }}</small></span>
            <span class="workspace-overview-row-action">Reply <x-icon name="chevron-right" size="15"/></span>
          </a>
        @empty<p class="workspace-overview-empty">No client inquiries are currently open.</p>
        @endforelse
      </div>
    </section>
  </div>
</div>
@endsection
