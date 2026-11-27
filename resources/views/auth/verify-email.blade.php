@extends('layouts.main')
@section('title', 'Verify your email')
@push('page-styles')
<link rel="stylesheet" href="{{ asset('css/account-email-verification.css') }}?v=2">
@endpush
@section('content')
<div class="container inner-page email-verification-page">
  <section class="email-verification-card" aria-labelledby="verification-title">
    <span class="email-verification-icon"><x-icon name="mail" size="26"/></span>
    <div class="email-verification-eyebrow">ACCOUNT SECURITY</div>
    <h1 id="verification-title">Verify your email</h1>
    <p>Confirm your email address to protect your Bambodia account.</p>
    <div class="email-verification-address">{{ auth()->user()->email }}</div>
    <p>Open the verification link in your inbox. The link expires in one hour. Check your spam folder if you cannot find it.</p>
    @if($continueCheckout)<p class="email-verification-hint">Your cart is saved. Verify your email before proceeding to checkout.</p>@endif
    @if(session('status'))<p class="email-verification-status" role="status">{{ session('status') }}</p>@endif
    @error('email')<p class="email-verification-error" role="alert">{{ $message }}</p>@enderror
    <form method="post" action="{{ route('verification.send') }}">
      @csrf
      <button class="btn primary" type="submit">{{ session('status') ? 'Resend verification email' : 'Send verification email' }}</button>
    </form>
    <a class="btn subtle" href="{{ auth()->user()->defaultWorkspaceUrl() }}">Return to your account</a>
  </section>
</div>
@endsection
