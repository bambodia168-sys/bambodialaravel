@extends('layouts.main')
@section('title','Support Center & Knowledge Base')
@section('content')
<div class="support-knowledge">
  <section class="support-knowledge-hero">
    <div class="support-hero-agent-art" aria-hidden="true">
      <img src="{{ asset('images/support-agent-photo.webp') }}" alt="" width="380" height="319" decoding="async" fetchpriority="low">
    </div>
    <div class="container support-knowledge-hero-inner">
      <div class="eyebrow">Bambodia Support</div>
      <h1>How can we help?</h1>
      <p>Explore practical guides, find answers, or contact our support team privately.</p>
      <form method="get" action="{{ route('support') }}" class="support-search" role="search">
        @if($category !== '')<input type="hidden" name="category" value="{{ $category }}">@endif
        <x-icon name="search" size="19"/>
        <input type="search" name="q" value="{{ $search }}" maxlength="120" placeholder="Search help articles and how-to guides" aria-label="Search the knowledge base">
        <button type="submit" class="btn primary">Search articles</button>
      </form>
      <div class="support-hero-links">
        @auth
          <a class="btn subtle" href="{{ route('support.tickets') }}"><x-icon name="life-buoy" size="17"/> My support tickets</a>
        @else
          <a class="btn subtle" href="{{ route('login') }}"><x-icon name="user" size="17"/> Sign in to create a ticket</a>
        @endauth
        <a class="btn subtle" href="{{ route('policy-center') }}"><x-icon name="shield-check" size="17"/> Policy Center</a>
      </div>
    </div>
  </section>
  <div class="container inner-page support-knowledge-main">
    <div class="support-section-heading"><div><div class="eyebrow">Browse by topic</div><h2>Help categories</h2></div></div>
    @php
      $categoryIcons = [
        'getting-started' => 'book-open',
        'accounts' => 'shield',
        'licenses' => 'key-round',
        'downloads' => 'download',
        'creators' => 'palette',
        'freelance' => 'briefcase',
        'payments' => 'credit-card',
        'policies' => 'scale',
      ];
    @endphp
    <nav class="support-category-grid" aria-label="Knowledge base categories">
      @foreach(\App\Support\SupportKnowledge::CATEGORIES as $key => $label)
        <a href="{{ route('support',['category'=>$key]) }}" class="support-category-card{{ $category === $key ? ' is-active' : '' }}" @if($category === $key)aria-current="page"@endif>
          <span class="support-category-number" aria-hidden="true">{{ sprintf('%02d', $loop->iteration) }}</span>
          <span class="support-category-glyph" aria-hidden="true"><x-icon :name="$categoryIcons[$key]" size="50"/></span>
          <strong>{{ $label }}</strong>
          <small>{{ (int)$categoryCounts->get($key,0) }} {{ (int)$categoryCounts->get($key,0) === 1 ? 'article' : 'articles' }}</small>
        </a>
      @endforeach
    </nav>
    <div class="support-section-heading support-article-heading">
      <div>
        <div class="eyebrow">Knowledge base</div>
        <h2>{{ $search !== '' ? 'Search results' : ($category !== '' ? \App\Support\SupportKnowledge::CATEGORIES[$category] : 'Explore help articles') }}</h2>
        <p>{{ $articles->total() }} published {{ $articles->total() === 1 ? 'article' : 'articles' }}</p>
      </div>
      @if($search !== '' || $category !== '')
        <a href="{{ route('support') }}" class="btn subtle">Clear filters</a>
      @endif
    </div>
    <div class="support-article-grid">
      @forelse($articles as $article)
        <a class="support-article-card" href="{{ $article->publicUrl() }}">
          <span class="support-article-card-number" aria-hidden="true">{{ sprintf('%02d', ($articles->currentPage() - 1) * $articles->perPage() + $loop->iteration) }}</span>
          <span class="support-article-card-glyph" aria-hidden="true"><x-icon :name="$categoryIcons[$article->category] ?? 'book-open'" size="76"/></span>
          <span class="support-article-category">{{ \App\Support\SupportKnowledge::CATEGORIES[$article->category] ?? ucfirst($article->category) }}</span>
          <strong>{{ $article->title }}</strong>
          <p class="support-article-excerpt">{{ $article->excerpt }}</p>
          <span class="support-article-open">Read article <x-icon name="chevron-right" size="16"/></span>
        </a>
      @empty
        <div class="support-empty-state">
          <x-icon name="book-open" size="30"/>
          <h3>No published articles match your search.</h3>
          <p>Knowledge base articles appear here after review and publication. You can still contact the support team.</p>
          @auth<a class="btn primary" href="{{ route('support.tickets') }}">Open my tickets</a>@else<a class="btn subtle" href="{{ route('login') }}">Sign in for support</a>@endauth
        </div>
      @endforelse
    </div>
    <x-pagination :paginator="$articles"/>
    <section class="support-contact-cta" aria-label="Private support assistance">
      <div><div class="eyebrow">Personal assistance</div><h2>Still need help?</h2>
        <p>Your support ticket stays private between your account and authorized support staff.</p>
      </div>
      @auth<a class="btn primary" href="{{ route('support.tickets') }}"><x-icon name="life-buoy" size="17"/> View or create a ticket</a>
      @else<a class="btn primary" href="{{ route('login') }}">Sign in for private support</a>@endauth
    </section>
  </div>
</div>
@endsection
