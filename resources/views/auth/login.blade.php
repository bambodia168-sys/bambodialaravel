@extends('layouts.auth')
@section('title','Sign in')
@section('heading','Welcome back')
@section('intro','Access your Bambodia account securely.')
@section('content')
<form action="{{ route('login.store') }}" method="post" class="auth-form">
  @csrf
  <div class="field"><x-icon name="mail" size="19"/><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email" placeholder=" "><label for="email">Email address</label></div>
  <div class="field"><x-icon name="lock" size="19"/><input id="password" type="password" name="password" required autocomplete="current-password" placeholder=" "><label for="password">Password</label></div>
  <div class="form-row"><label><input type="checkbox" name="remember"> Remember me</label><a href="{{ route('password.request') }}">Forgot password?</a></div>
  <button class="btn primary" type="submit">Sign in</button>
</form>
@if(\App\Models\FeatureFlag::isEnabled('registration'))<p class="form-foot">New to Bambodia? <a href="{{ route('register') }}">Create your account</a></p>@endif
@endsection
