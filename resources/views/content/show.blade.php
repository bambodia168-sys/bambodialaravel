@extends('layouts.main')
@section('title', $entry->type === 'post' ? ($entry->seo_title ?: $entry->title) : $entry->title)
@if($entry->type === 'post')
  @section('meta_description', $entry->seo_description ?: $entry->excerpt ?: \Illuminate\Support\Str::limit(\App\Support\PolicyContent::plain($entry->body), 155))
@endif
@section('content')
<div class="container inner-page">
  <div class="{{ $entry->type === 'post' ? 'blog-article-layout' : '' }}">
    <article class="panel editorial-page {{ $entry->type === 'post' ? 'blog-article-main' : '' }}">
      @if($entry->type === 'post')
        <nav class="blog-article-breadcrumb" aria-label="Breadcrumb">
          <a href="{{ route('blog') }}"><x-icon name="arrow-left" size="15"/> All posts</a>
        </nav>
      @endif
      <div class="eyebrow">
        {{ $entry->type === 'post' ? ($entry->category ?: 'Bambodia Blog') : ucfirst($entry->type) }}
        · {{ $entry->public_id }}
        @if(!empty($preview)) · Private preview @endif
      </div>
      @if(!empty($preview))
        <p class="post-public-excerpt"><strong>Administrator preview — this page is not publicly available unless the post is published.</strong></p>
      @endif
      <h1>{{ $entry->title }}</h1>
      <div class="post-public-meta">
        @if($entry->type === 'post')<span>{{ ucfirst($entry->status) }}</span>@endif
        <span>{{ $entry->published_at ? 'Published '.$entry->published_at->format('d M Y') : 'Not published' }}</span>
        @if($entry->author)<span>· {{ $entry->author->name }}</span>@endif
      </div>
      @if($entry->type === 'post')
        @if(in_array($entry->featured_type, ['image', 'video'], true) && $entry->featuredMedia)
          @if($entry->featured_type === 'video' && str_starts_with($entry->featuredMedia->mime_type, 'video/'))
            <figure class="post-featured-figure">
              <video class="post-public-video" controls preload="metadata" playsinline
                src="{{ $entry->featuredMedia->previewUrl() }}" aria-label="{{ $entry->featuredMedia->alt_text }}"></video>
            </figure>
          @elseif($entry->featured_type === 'image' && str_starts_with($entry->featuredMedia->mime_type, 'image/'))
            <figure class="post-featured-figure">
              <img class="post-public-image" src="{{ $entry->featuredMedia->previewUrl() }}"
                alt="{{ $entry->featuredMedia->alt_text }}" loading="eager">
            </figure>
          @endif
        @elseif($entry->featured_type === 'youtube' && preg_match('~^https://www\.youtube\.com/watch\?v=([A-Za-z0-9_-]{11})$~D', (string) $entry->featured_url, $youtubeMatches))
          <figure class="post-featured-figure">
            <iframe class="post-public-youtube" loading="lazy" title="Featured YouTube video"
              referrerpolicy="strict-origin-when-cross-origin"
              src="{{ 'https://www.youtube-nocookie.com/embed/'.$youtubeMatches[1] }}"
              allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
              allowfullscreen></iframe>
          </figure>
        @elseif($entry->featured_type === 'color' && preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $entry->featured_color))
          <figure class="post-featured-figure">
            <div class="post-public-color" style="background-color:{{ $entry->featured_color }}" role="img" aria-label="Featured color {{ $entry->featured_color }}"></div>
          </figure>
        @endif
        @if($entry->excerpt)<p class="post-public-excerpt">{{ $entry->excerpt }}</p>@endif
        <div class="editorial-copy policy-formatted-content">
          @if($entry->body_format === 'html')
            {!! \App\Support\PolicyContent::sanitize($entry->body) !!}
          @else
            {!! nl2br(e($entry->body)) !!}
          @endif
        </div>
        @if($entry->tags)
          <div class="post-public-tags" aria-label="Post tags">
            @foreach(array_filter(array_map('trim', explode(',', $entry->tags))) as $tag)<span>{{ $tag }}</span>@endforeach
          </div>
        @endif
      @else
        <div class="editorial-copy">{!! nl2br(e($entry->body)) !!}</div>
      @endif
    </article>
    @if($entry->type === 'post')
      <aside class="blog-article-sidebar" aria-label="Blog sidebar">
        <section class="panel blog-sidebar-panel">
          <h2>More articles</h2>
          @forelse($relatedPosts as $related)
            <a class="blog-sidebar-story" href="{{ $related->publicUrl() }}">
              <span>{{ $related->title }}</span>
              <small>{{ $related->published_at?->format('d M Y') ?? 'Published' }}</small>
            </a>
          @empty
            <p class="blog-sidebar-empty">More articles will appear here when published.</p>
          @endforelse
        </section>
        @if($blogCategories->isNotEmpty())
          <section class="panel blog-sidebar-panel">
            <h2>Categories</h2>
            <nav aria-label="Blog categories" class="blog-sidebar-categories">
              @foreach($blogCategories as $item)
                <a href="{{ route('blog', ['category' => $item->category]) }}">
                  <span>{{ $item->category }}</span><span class="blog-category-count">{{ $item->total }}</span>
                </a>
              @endforeach
            </nav>
          </section>
        @endif
        <a class="blog-sidebar-back" href="{{ route('blog') }}"><x-icon name="arrow-left" size="16"/> Back to all posts</a>
      </aside>
    @endif
  </div>
</div>
@endsection
