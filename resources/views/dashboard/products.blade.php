@extends('layouts.dashboard')
@section('title', 'Product Manager')
@section('content')
  @include('dashboard.partials.marketplace-manager', ['records' => $products])
@endsection
