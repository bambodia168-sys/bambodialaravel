@extends('layouts.auth')
@section('title','Register')
@section('heading',__('ui.create_account'))
@section('intro','Join a marketplace built around independent talent.')
@section('content')
<form action="{{ route('register.store') }}" method="post" class="auth-form">
  @csrf
  <div class="field"><x-icon name="user" size="19"/><input id="name" name="name" value="{{ old('name') }}" required autocomplete="name" maxlength="120" placeholder=" "><label for="name">Full name</label></div>
  <div class="field"><x-icon name="mail" size="19"/><input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" maxlength="254" placeholder=" "><label for="email">{{ __('ui.email') }}</label></div>
  <div class="field"><x-icon name="lock" size="19"/><input id="password" type="password" name="password" required autocomplete="new-password" minlength="12" placeholder=" "><label for="password">Password (12+ characters)</label></div>
  <div class="field"><x-icon name="shield" size="19"/><input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" minlength="12" placeholder=" "><label for="password_confirmation">Confirm password</label></div>
  @if($termsPublication)
    <input type="hidden" name="terms_publication_id" value="{{ $termsPublication->id }}">
    <label class="auth-terms" for="terms_accepted">
      <input id="terms_accepted" type="checkbox" name="terms_accepted" value="1" @checked(old('terms_accepted')) required>
      <span>I have read and <strong>accept the <a href="{{ $termsPublication->policy->publicUrl() }}" target="_blank" rel="noopener noreferrer">Terms of Service</a></strong> (published revision {{ $termsPublication->revision }}).</span>
    </label>
    <button class="btn primary" type="submit">{{ __('ui.create_account') }}</button>
  @else
    <label class="auth-terms" for="terms_accepted">
      <input id="terms_accepted" type="checkbox" disabled aria-disabled="true">
      <span>Accept the <a href="{{ route('policy-center') }}" target="_blank" rel="noopener noreferrer">Terms of Service</a></span>
    </label>
    <p class="auth-terms-unavailable" role="status">Account registration is temporarily unavailable until the administrator publishes Terms of Service.</p>
    <button class="btn primary" type="submit" disabled>Registration unavailable</button>
  @endif
</form>
<p class="form-foot">Already registered? <a href="{{ route('login') }}">Sign in</a></p>
@endsection
