@extends('layouts.dashboard')
@section('title', ucfirst($type).' Manager')
@section('content')
  @include('dashboard.partials.marketplace-manager', ['records' => $listings])
@endsection
