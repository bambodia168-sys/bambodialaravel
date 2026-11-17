@extends('layouts.main')
@section('title', 'Verify your email')
@section('content')
<div class="container inner-page storefront">
  <section class="storefront-panel storefront-verification" aria-labelledby="verification-title">
    <span class="storefront-icon"><x-icon name="mail" size="24"/></span>
    <h1 id="verification-title">Verify your email</h1>
    <p>Confirm <strong>{{ auth()->user()->email }}</strong> to proceed to payment. The verification link will return you to your saved cart.</p>
    @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
    @error('email')<p class="storefront-notice" role="alert">{{ $message }}</p>@enderror
    <form method="post" action="{{ route('checkout.verification.send') }}">
      @csrf
      <button class="btn primary">{{ session('status') ? 'Resend verification email' : 'Send verification email' }}</button>
    </form>
    <a href="{{ route('cart') }}">Back to cart</a>
  </section>
</div>
@endsection
