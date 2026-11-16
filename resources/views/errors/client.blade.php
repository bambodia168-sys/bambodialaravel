@php
  $code = $exception->getStatusCode();
  [$heading, $description] = match ($code) {
    400 => ['Invalid request', 'Check the link or return to the previous page and try again.'],
    401 => ['Sign-in required', 'Sign in to Bambodia to continue.'],
    402 => ['Payment required', 'This action requires a payment. Review the payment details or contact support.'],
    403 => ['Access denied', 'Your account does not have permission to access this page. Contact support if you need access.'],
    404 => ['Page not found', 'The page you’re looking for may have moved, or the link may be incomplete.'],
    405 => ['Action unavailable', 'Return to the page where you started and use the available controls to continue.'],
    409 => ['Action could not be completed', 'The record may have changed. Reopen the original page and check its current status before trying again.'],
    410 => ['Page no longer available', 'This page has been removed. You can browse the marketplace or contact support for help.'],
    419 => ['Session expired', 'Reopen the form before trying again. Text you entered may need to be added again.'],
    422 => ['Request could not be processed', 'Review the details on the original page and try again.'],
    429 => ['Too many requests', 'Wait a moment before trying again.'],
    default => ['Request could not be completed', 'Check the link or contact support if the issue continues.'],
  };
@endphp

@extends('layouts.main', ['errors' => $errors ?? new \Illuminate\Support\ViewErrorBag])

@section('title', $heading)
@section('meta_robots', $code === 404 ? 'noindex,follow' : 'noindex,nofollow')

@section('content')
<link rel="stylesheet" href="{{ asset('css/error-404.css') }}?v=2">
<section class="container site-not-found" aria-labelledby="page-title">
  @include('errors.status-mark', ['code' => $code])
  <h1 id="page-title">{{ $heading }}</h1>
  <p>{{ $description }}</p>
  <nav class="site-not-found-actions" aria-label="Where to go next">
    @if($code === 401 && !auth()->check())
      <a class="btn primary" href="{{ route('login') }}">Sign in</a>
    @else
      <a class="btn primary" href="{{ route('home') }}">Back to homepage</a>
    @endif
    @if($code === 404 && \App\Models\FeatureFlag::isEnabled('marketplace_search'))
      <a class="btn subtle" href="{{ route('marketplace.search') }}">Search marketplace</a>
    @else
      <a class="btn subtle" href="{{ route('support') }}">Support Center</a>
    @endif
  </nav>
</section>
@endsection
