@extends('layouts.auth')
@section('title','Sign in')
@section('heading',__('ui.welcome_back'))
@section('intro','Access your Bambodia account securely.')
@section('content')
<form action="{{ route('login.store') }}" method="post" class="auth-form">
  @csrf
  <div class="field"><x-icon name="mail" size="19"/><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email" placeholder=" "><label for="email">{{ __('ui.email') }}</label></div>
  <div class="field"><x-icon name="lock" size="19"/><input id="password" type="password" name="password" required autocomplete="current-password" placeholder=" "><label for="password">{{ __('ui.password') }}</label></div>
  <div class="form-row"><label><input type="checkbox" name="remember"> Remember me</label><a href="{{ route('password.request') }}">{{ __('ui.forgot_password') }}</a></div>
  <button class="btn primary" type="submit">{{ __('ui.sign_in') }}</button>
</form>
@if(\App\Models\FeatureFlag::isEnabled('registration'))<p class="form-foot">New to Bambodia? <a href="{{ route('register') }}">Create your account</a></p>@endif
@endsection
