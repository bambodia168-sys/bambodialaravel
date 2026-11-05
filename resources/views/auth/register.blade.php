@extends('layouts.auth')
@section('title','Register')
@section('heading',__('ui.create_account'))
@section('intro','Join a marketplace built around independent talent.')
@section('content')
<form action="{{ route('register.store') }}" method="post" class="auth-form">
  @csrf
  <div class="field"><x-icon name="user" size="19"/><input id="name" name="name" value="{{ old('name') }}" required autocomplete="name" maxlength="120" placeholder=" "><label for="name">Full name</label></div>
  <div class="field"><x-icon name="mail" size="19"/><input id="email" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" placeholder=" "><label for="email">{{ __('ui.email') }}</label></div>
  <div class="field"><x-icon name="lock" size="19"/><input id="password" type="password" name="password" required autocomplete="new-password" minlength="12" placeholder=" "><label for="password">Password (12+ characters)</label></div>
  <div class="field"><x-icon name="shield" size="19"/><input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" minlength="12" placeholder=" "><label for="password_confirmation">Confirm password</label></div>
  <button class="btn primary" type="submit">{{ __('ui.create_account') }}</button>
</form>
<p class="form-foot">Already registered? <a href="{{ route('login') }}">Sign in</a></p>
@endsection
