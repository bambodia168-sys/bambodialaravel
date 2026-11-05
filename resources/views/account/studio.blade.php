@extends('layouts.workspace')
@section('workspace','creator')
@section('title','Creator Studio')
@section('content')
<div class="container inner-page"><div class="page-intro"><div class="eyebrow">Creator workspace</div><h1>Creator Studio</h1><p>Manage your marketplace submissions and their publication status.</p></div>
<div class="account-metrics" aria-label="Creator activity">
  <a class="account-metric" href="{{ route('studio.listings') }}"><span>Approved Listings</span><strong>{{ number_format($stats['published']) }}</strong><small>View listings <x-icon name="chevron-right" size="13"/></small></a>
  <a class="account-metric" href="{{ route('studio.listings') }}"><span>Pending Review</span><strong>{{ number_format($stats['pending']) }}</strong><small>View submissions <x-icon name="chevron-right" size="13"/></small></a>
  <a class="account-metric" href="{{ route('studio.inquiries') }}"><span>Open Inquiries</span><strong>{{ number_format($stats['inquiries']) }}</strong><small>View messages <x-icon name="chevron-right" size="13"/></small></a>
</div>
<div class="studio-actions">
<a class="btn subtle" href="{{ route('studio.profile.edit') }}"><x-icon name="user" size="17"/> Creator Profile &amp; Visibility</a>
<a class="btn primary" href="{{ route('studio.products.index') }}"><x-icon name="package" size="17"/> Product Manager &amp; Releases</a>
<a class="btn subtle" href="{{ route('contracts.index') }}"><x-icon name="file" size="17"/> My Contracts</a>
<a class="btn subtle" href="{{ route('account.saved-listings') }}"><x-icon name="star" size="17"/> Saved Listings</a>
<a class="btn subtle" href="{{ route('account.saved-jobs') }}"><x-icon name="briefcase" size="17"/> Saved Jobs</a>
<a class="btn subtle" href="{{ route('studio.media.index') }}"><x-icon name="image" size="17"/> Media Library</a>
<a class="btn subtle" href="{{ route('resume.edit') }}"><x-icon name="file" size="17"/> Resume Builder</a>
@if(auth()->user()->role==='freelancer')<a class="btn subtle" href="{{ route('jobs.applied') }}"><x-icon name="briefcase" size="17"/> My Job Proposals</a>@endif
<a class="btn subtle" href="{{ route('studio.listings') }}"><x-icon name="layers" size="17"/> Projects, freelance and services</a>
<a class="btn subtle" href="{{ route('studio.inquiries') }}"><x-icon name="message" size="17"/> Client Inquiries</a>
</div>
</div>
@endsection
