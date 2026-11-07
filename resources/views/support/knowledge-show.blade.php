@extends('layouts.main')
@section('title', $article->title.' · Knowledge Base')
@section('content')
<div class="container inner-page support-article-layout">
  <aside class="panel support-article-sidebar" aria-label="Help navigation">
    <a href="{{ route('support') }}" class="support-back-link"><x-icon name="chevron-left" size="16"/> All help articles</a>
    <h2>Related guides</h2>
    @forelse($related as $item)
      <a href="{{ $item->publicUrl() }}" class="support-related-link">{{ $item->title }}</a>
    @empty
      <p class="text-muted">More guides will appear as they are published.</p>
    @endforelse
    <a class="btn subtle" href="{{ route('policy-center') }}">Read our policies</a>
  </aside>
  <article class="panel support-article-main policy-formatted-content">
    <div class="eyebrow">{{ \App\Support\SupportKnowledge::CATEGORIES[$article->category] ?? ucfirst($article->category) }}</div>
    <h1>{{ $article->title }}</h1>
    <div class="support-article-meta">
      <span>Article {{ $article->public_code }}</span>
      <span>Updated {{ $article->updated_at?->format('d M Y') }}</span>
    </div>
    <div class="policy-copy support-article-body">{!! $html !!}</div>
    <footer class="support-article-footer">
      <p>Was this guide useful? For unresolved issues, open a private support ticket.</p>
      <div class="support-article-action-row">
        @auth<a href="{{ route('support.tickets') }}" class="btn primary support-ticket-action"><x-icon name="life-buoy" size="16"/> Create support ticket</a>
        @else<a href="{{ route('login') }}" class="btn primary support-ticket-action"><x-icon name="life-buoy" size="16"/> Sign in to contact support</a>@endauth
        <div class="policy-single-actions" aria-label="Article sharing actions">
          <button type="button" class="btn subtle" data-policy-copy-url="{{ $article->publicUrl() }}"><x-icon name="copy" size="16"/> Copy link</button>
          <button type="button" class="btn subtle" data-policy-print><x-icon name="file-text" size="16"/> Print</button>
          <button type="button" class="btn subtle" data-policy-share="{{ $article->publicUrl() }}"><x-icon name="external-link" size="16"/> Share</button>
          <span class="policy-copy-feedback" aria-live="polite"></span>
        </div>
      </div>
    </footer>
  </article>
</div>
@endsection
