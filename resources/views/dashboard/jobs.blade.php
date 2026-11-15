@extends('layouts.dashboard')
@section('title', 'Job Manager')
@section('content')
  @include('dashboard.partials.marketplace-manager', ['records' => $jobs])
@endsection
