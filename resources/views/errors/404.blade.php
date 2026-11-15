@extends('layouts.main', ['errors' => $errors ?? new \Illuminate\Support\ViewErrorBag])

@section('title', 'Page not found')
@section('meta_robots', 'noindex,follow')

@section('content')
<link rel="stylesheet" href="{{ asset('css/error-404.css') }}?v=1">
<section class="container site-not-found" aria-labelledby="page-title">
  <svg class="site-not-found-mark" width="332" height="130" viewBox="0 0 332 130" role="img" aria-label="404"
       fill="none" stroke="currentColor" stroke-width="12" stroke-linecap="round" stroke-linejoin="round">
    <path d="M72 17 15 86h83M72 17v96"/>
    <ellipse cx="166" cy="65" rx="37" ry="48" class="site-not-found-zero"/>
    <path d="M291 17 234 86h83M291 17v96"/>
  </svg>
  <h1 id="page-title">Page not found</h1>
  <p>The page you’re looking for may have moved, or the link may be incomplete.</p>
  <nav class="site-not-found-actions" aria-label="Where to go next">
    <a class="btn primary" href="{{ route('home') }}">Back to homepage</a>
    @if(\App\Models\FeatureFlag::isEnabled('marketplace_search'))
      <a class="btn subtle" href="{{ route('marketplace.search') }}">Search marketplace</a>
    @else
      <a class="btn subtle" href="{{ route('support') }}">Support Center</a>
    @endif
  </nav>
</section>
@endsection
