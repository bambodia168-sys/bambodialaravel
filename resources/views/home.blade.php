@extends('layouts.main')
@section('title','Home')
@section('content')
<section class="hero container">
  <div class="eyebrow">{{ \App\Models\SiteSetting::setting('appearance.hero_eyebrow') }}</div>
  <h1>{{ app()->getLocale()==='en' ? \App\Models\SiteSetting::setting('appearance.hero_title_en') : __('ui.hero_title') }}</h1>
  <p>{{ app()->getLocale()==='en' ? \App\Models\SiteSetting::setting('appearance.hero_intro_en') : __('ui.hero_intro') }}</p>
  @if(\App\Models\FeatureFlag::isEnabled('marketplace_search') && \App\Models\SiteSetting::enabled('appearance.hero_search_enabled'))
  <form class="hero-search" method="get" action="{{ url('/search') }}"><x-icon name="search" size="22"/><input type="search" name="q" aria-label="Search listings" placeholder="What are you looking for?"><button class="btn primary" type="submit">{{ __('ui.explore') }}</button></form>
  @endif
  @if(\App\Models\FeatureFlag::isEnabled('marketplace_search') && \App\Models\SiteSetting::enabled('appearance.hero_categories_enabled'))
  <div class="hero-links"><a href="{{ url('/search?type=product') }}">{{ __('ui.digital_products') }}</a><a href="{{ url('/search?type=project') }}">{{ __('ui.projects') }}</a><a href="{{ url('/search?type=service') }}">{{ __('ui.services') }}</a><a href="{{ url('/search?type=freelance') }}">{{ __('ui.freelance') }}</a></div>
  @endif
</section>
@endsection
