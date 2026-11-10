@extends('layouts.auth')
@section('title','Forgot password')
@section('heading','Reset your password')
@section('intro','Enter your account email to request a reset link.')
@section('content')
<form action="{{ route('password.email') }}" method="post" class="auth-form">
  @csrf
  <div class="field"><x-icon name="mail" size="19"/><input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" placeholder=" "><label for="email">Email address</label></div>
  <x-turnstile-widget action="reset"/>
  <button class="btn primary" type="submit">Send reset link</button>
</form>
<p class="form-foot"><a href="{{ route('login') }}">Back to sign in</a></p>
@endsection
