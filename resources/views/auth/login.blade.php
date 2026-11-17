@extends('layouts.auth')
@section('title','Sign in')
@section('heading',__('ui.welcome_back'))
@section('intro','Access your Bambodia account securely.')
@section('content')
@include('auth.partials.login-form')
@if(\App\Models\FeatureFlag::isEnabled('registration'))<p class="form-foot">New to Bambodia? <a href="{{ route('register') }}">Create your account</a></p>@endif
@endsection
