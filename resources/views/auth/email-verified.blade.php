@extends('layouts.main')
@section('title', 'Your email verified')
@push('head')
<link rel="stylesheet" href="{{ asset('css/account-email-verification.css') }}?v=1">
@endpush
@section('content')
<div class="container inner-page email-verification-page">
  <section class="email-verification-card" aria-labelledby="verification-title">
    <span class="email-verification-icon is-success"><x-icon name="check-circle" size="32"/></span>
    <div class="email-verification-eyebrow">ACCOUNT SECURITY</div>
    <h1 id="verification-title">Your email verified</h1>
    <p>Your email address has been successfully verified.</p>
    <div class="email-verification-address">{{ auth()->user()->email }}</div>
    <p>You can now use your verified Bambodia account.</p>
    @if($continueCheckout)
      <a class="btn primary" href="{{ route('checkout') }}">Continue checkout</a>
      <a class="btn subtle" href="{{ auth()->user()->defaultWorkspaceUrl() }}">Return to your account</a>
    @else
      <a class="btn primary" href="{{ auth()->user()->defaultWorkspaceUrl() }}">Continue to your account</a>
    @endif
  </section>
</div>
@endsection
