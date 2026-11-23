@extends('layouts.auth')
@section('title', 'Complete Google registration')
@section('heading', 'Complete your Google registration')
@section('intro', 'Your Google account is verified. Review the details and accept Bambodia\'s terms to create your marketplace account.')
@section('content')
<div class="auth-form">
  <div class="field">
    <x-social-provider-icon provider="google" />
    <p><strong>{{ $pending['name'] }}</strong><br><span>{{ $pending['email'] }}</span></p>
  </div>
  <p>Only continue if this is the Google account you want to use for Bambodia. If you already have an account with a different email, sign in to it and connect Google from Account Security instead.</p>
</div>
<form action="{{ route('social.google.registration.store') }}" method="post" class="auth-form">
  @csrf
  <input type="hidden" name="terms_publication_id" value="{{ $termsPublication->id }}">
  @php
    $privacyPolicy = \App\Models\Policy::query()
        ->where('status', 'published')
        ->where('category', 'privacy')
        ->where('slug', 'privacy-policy')
        ->orderByDesc('published_at')
        ->latest('id')
        ->first();
  @endphp
  <label class="auth-terms" for="terms_accepted">
    <input id="terms_accepted" type="checkbox" name="terms_accepted" value="1"
      @checked(old('terms_accepted')) required>
    <span>I have read and accept the
      <a href="{{ $termsPublication->policy->publicUrl() }}" target="_blank" rel="noopener noreferrer">Terms of Service</a>@if($privacyPolicy)
      and <a href="{{ $privacyPolicy->publicUrl() }}" target="_blank" rel="noopener noreferrer">Privacy Policy</a>@endif.
    </span>
  </label>
  <button class="btn primary" type="submit">Create account with Google</button>
</form>
<p class="form-foot"><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
