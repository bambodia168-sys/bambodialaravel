@extends('layouts.auth')
@section('title','Two-factor verification')
@section('heading','Verify your sign-in')
@section('intro','Enter an authenticator code or one unused recovery code to access your account.')
@section('content')
<form method="post" action="{{ route('login.two-factor.verify') }}" class="auth-form">
  @csrf
  <div class="field">
    <x-icon name="shield-check" size="19"/>
    <input id="two-factor-code" type="text" name="code" required autocomplete="one-time-code"
      inputmode="text" maxlength="40" placeholder=" " autofocus>
    <label for="two-factor-code">Verification code</label>
  </div>
  <button class="btn primary" type="submit">Verify &amp; sign in</button>
</form>
<p class="form-foot">Lost access? Use one of your saved recovery codes.</p>
@endsection
