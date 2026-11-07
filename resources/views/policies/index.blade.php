@extends('layouts.main')
@section('title','Policy Center')
@section('content')
@php
  $policyIcons = [
    'Regular License'=>'shield','Extended License'=>'key-round','Terms of Service'=>'scroll-text',
    'Privacy Policy'=>'lock','Cookie Policy'=>'settings','Refund and Cancellation Policy'=>'refresh-cw',
    'Digital Product Delivery and Download Policy'=>'download','Creator and Seller Agreement'=>'users',
    'Acceptable Use and Prohibited Content Policy'=>'clipboard-check',
    'Intellectual Property and Takedown Policy'=>'scale',
    'Support, Updates and Maintenance Policy'=>'life-buoy',
    'Freelance Services and Dispute Policy'=>'briefcase',
    'Security and Vulnerability Disclosure Policy'=>'alert',
  ];
@endphp
<div class="container inner-page policy-index policy-center-premium">
  <section class="policy-hero" aria-labelledby="policy-center-heading">
    <div class="policy-hero-copy">
      <div class="eyebrow">Bambodia Policies</div>
      <h1 id="policy-center-heading">Policy Center</h1>
      <p>Understand your rights, responsibilities, and the commitments that guide the Bambodia marketplace.</p>
      <form method="get" action="{{ route('policy-center') }}" class="hero-search">
        <x-icon name="search" size="19"/>
        <input name="q" value="{{ $search }}" type="search" placeholder="Find a policy" aria-label="Find policy">
        <button class="btn primary" type="submit">Search policies</button>
      </form>
    </div>
    <div class="policy-trust-shield" aria-hidden="true">
      <span class="policy-trust-ring"></span><x-icon name="shield" size="225"/>
    </div>
  </section>

  @foreach($notices as $notice)
    <dialog class="policy-update-dialog" data-policy-notice="{{ $notice->id }}" aria-labelledby="legal-notice-title-{{ $notice->id }}">
      <div class="policy-notice-top"><span class="policy-notice-icon"><x-icon name="shield" size="28"/></span><button class="policy-icon-button" type="button" data-policy-notice-dismiss aria-label="Dismiss legal update"><x-icon name="x" size="19"/></button></div>
      <div class="eyebrow">Important policy update</div>
      <h2 id="legal-notice-title-{{ $notice->id }}">{{ $notice->headline }}</h2>
      <p>{{ $notice->message }}</p>
      <div class="policy-notice-actions">
        <a class="btn primary" href="{{ $notice->policy->publicUrl() }}">Read {{ $notice->policy->title }}</a>
        <button type="button" class="btn subtle" data-policy-notice-dismiss>Dismiss</button>
      </div>
    </dialog>
  @endforeach

  <div class="policy-directory-header">
    <div><span class="eyebrow">Legal directory</span><h2>Explore our policies</h2></div>
    <span class="policy-directory-count">{{ $policies->total() }} {{ $policies->total() === 1 ? 'policy' : 'policies' }}</span>
  </div>
  <div class="policy-tiles policy-card-grid">
    @forelse($policies as $policy)
      <a class="policy-card" href="{{ $policy->publicUrl() }}">
        <div class="policy-card-top">
          <span class="policy-card-number">{{ str_pad((string)($loop->iteration + ($policies->currentPage()-1)*15), 2, '0', STR_PAD_LEFT) }}</span>
          <x-icon :name="$policyIcons[$policy->title] ?? 'file-text'" size="39"/>
        </div>
        <h3>{{ $policy->title }}</h3>
        <div class="policy-card-footer"><span>{{ ucfirst($policy->category) }}</span><span>v{{ $policy->version }}</span></div>
      </a>
    @empty
      <div class="policy-card-empty">
        <x-icon name="file-text" size="32"/>
        <h2>No published policies match this search.</h2>
        <p>Only administrator-reviewed and published policy versions appear here.</p>
      </div>
    @endforelse
  </div>
  <x-pagination :paginator="$policies"/>
</div>
@endsection
