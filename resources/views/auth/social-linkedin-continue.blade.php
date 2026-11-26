@extends('layouts.auth')
@section('title', 'Continue with LinkedIn')
@section('heading', $pending['user_id'] === null ? 'Complete your LinkedIn registration' : 'Confirm your LinkedIn sign-in')
@section('intro', $pending['user_id'] === null ? 'Review your details and accept Bambodia’s terms.' : 'Confirm your Bambodia email once to securely connect LinkedIn.')
@section('content')
<div class="auth-form">
  <div class="field">
    <x-social-provider-icon provider="linkedin" />
    <p><strong>{{ $pending['name'] }}</strong><br><span>{{ $pending['email'] }}</span></p>
  </div>
</div>
@if($pending['user_id'] !== null)
  <p class="form-foot">To protect your existing account, we need to confirm access to its email address. You will not need your Bambodia password for this confirmation. Your existing two-factor authentication still applies.</p>
  <form method="post" action="{{ route('social.linkedin.code') }}" class="auth-form">
    @csrf
    <button class="btn {{ isset($pending['code_hash']) ? 'subtle' : 'primary' }}" type="submit">{{ isset($pending['code_hash']) ? 'Send a new code' : 'Send confirmation code' }}</button>
  </form>
  @if(isset($pending['code_hash']))
    <form method="post" action="{{ route('social.linkedin.store') }}" class="auth-form">
      @csrf
      <div class="field">
        <x-icon name="lock" size="19"/>
        <input id="confirmation_code" name="confirmation_code" type="text" inputmode="numeric" autocomplete="one-time-code" minlength="8" maxlength="8" pattern="[0-9]{8}" required placeholder=" ">
        <label for="confirmation_code">8-digit confirmation code</label>
      </div>
      <button class="btn primary" type="submit">Confirm and sign in</button>
    </form>
  @endif
@else
  <p class="form-foot">Only continue if you want to create a new Bambodia customer account with this LinkedIn identity. If you already have a Bambodia account with another email, sign in to that account and connect LinkedIn in Account Security instead.</p>
  <form action="{{ route('social.linkedin.store') }}" method="post" class="auth-form">
    @csrf
    <input type="hidden" name="terms_publication_id" value="{{ $termsPublication->id }}">
    @php
      $privacyPolicy = \App\Models\Policy::query()->where('status','published')->where('category','privacy')
          ->where('slug','privacy-policy')->orderByDesc('published_at')->latest('id')->first();
    @endphp
    <label class="auth-terms" for="terms_accepted">
      <input id="terms_accepted" type="checkbox" name="terms_accepted" value="1" @checked(old('terms_accepted')) required>
      <span>I have read and accept the <a href="{{ $termsPublication->policy->publicUrl() }}" target="_blank" rel="noopener noreferrer">Terms of Service</a>@if($privacyPolicy) and <a href="{{ $privacyPolicy->publicUrl() }}" target="_blank" rel="noopener noreferrer">Privacy Policy</a>@endif.</span>
    </label>
    <button class="btn primary" type="submit">Create account with LinkedIn</button>
  </form>
@endif
<p class="form-foot"><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
