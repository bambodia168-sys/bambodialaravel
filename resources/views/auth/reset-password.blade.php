@extends('layouts.auth')
@section('title','Reset password')
@section('heading','Choose a new password')
@section('intro','Use a new password of at least 12 characters.')
@section('content')
<form action="{{ route('password.update') }}" method="post" class="auth-form">
  @csrf
  <input type="hidden" name="token" value="{{ $token }}">
  <div class="field"><x-icon name="mail" size="19"/><input id="email" type="email" name="email" value="{{ old('email',$email) }}" required autocomplete="email" placeholder=" "><label for="email">Email address</label></div>
  <div class="field"><x-icon name="lock" size="19"/><input id="password" type="password" name="password" required minlength="12" autocomplete="new-password" placeholder=" "><label for="password">New password</label></div>
  <div class="field"><x-icon name="shield" size="19"/><input id="password_confirmation" type="password" name="password_confirmation" required minlength="12" autocomplete="new-password" placeholder=" "><label for="password_confirmation">Confirm password</label></div>
  <button class="btn primary" type="submit">Update password</button>
</form>
@endsection
