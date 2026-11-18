@extends('layouts.auth')
@section('title','Register')
@section('heading',__('ui.create_account'))
@section('intro','Join a marketplace built around independent talent.')
@section('content')
@include('auth.partials.register-form')
<p class="form-foot">Already registered? <a href="{{ route('login') }}">Sign in</a></p>
@endsection
