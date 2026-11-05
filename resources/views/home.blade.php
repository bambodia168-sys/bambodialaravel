@extends('layouts.main')
@section('title','Home')
@section('content')
<section class="hero container">
  <div class="eyebrow">A marketplace for independent work</div>
  <h1>Digital creations and expert talent, <span>in one place.</span></h1>
  <p>Discover licensed themes, templates, source code, presentations, and professional freelance services from independent creators.</p>
  <form class="hero-search" method="get" action="{{ url('/search') }}"><x-icon name="search" size="22"/><input type="search" name="q" aria-label="Search listings" placeholder="What are you looking for?"><button class="btn primary" type="submit">Explore marketplace</button></form>
  <div class="hero-links"><a href="{{ url('/search?type=product') }}">Digital products</a><a href="{{ url('/search?type=project') }}">Projects</a><a href="{{ url('/search?type=service') }}">Services</a><a href="{{ url('/search?type=freelance') }}">Freelancers</a></div>
</section>
@endsection
