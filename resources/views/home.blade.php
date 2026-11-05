@extends('layouts.main')
@section('title','Home')
@section('content')
<section class="hero container">
  <div class="eyebrow">A marketplace for independent work</div>
  <h1>{{ __('ui.hero_title') }}</h1>
  <p>{{ __('ui.hero_intro') }}</p>
  @if(\App\Models\FeatureFlag::isEnabled('marketplace_search'))
  <form class="hero-search" method="get" action="{{ url('/search') }}"><x-icon name="search" size="22"/><input type="search" name="q" aria-label="Search listings" placeholder="What are you looking for?"><button class="btn primary" type="submit">{{ __('ui.explore') }}</button></form>
  <div class="hero-links"><a href="{{ url('/search?type=product') }}">{{ __('ui.digital_products') }}</a><a href="{{ url('/search?type=project') }}">{{ __('ui.projects') }}</a><a href="{{ url('/search?type=service') }}">{{ __('ui.services') }}</a><a href="{{ url('/search?type=freelance') }}">{{ __('ui.freelance') }}</a></div>
  @endif
</section>
@endsection
