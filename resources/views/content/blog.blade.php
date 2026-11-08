@extends('layouts.main')
@section('title','Blog')
@section('content')
@php
  $gridUrl = route('blog', array_filter(['view' => 'grid', 'columns' => $columns, 'category' => $category], fn ($value) => $value !== ''));
  $listUrl = route('blog', array_filter(['view' => 'list', 'columns' => $columns, 'category' => $category], fn ($value) => $value !== ''));
@endphp
<div class="container inner-page blog-index">
  <div class="blog-view-toolbar">
    <nav class="blog-view-toggle" aria-label="Blog display mode">
      <a class="blog-view-link {{ $view === 'grid' ? 'is-active' : '' }}" href="{{ $gridUrl }}"
        @if($view === 'grid') aria-current="page" @endif><x-icon name="layout-grid" size="17"/> Grid</a>
      <a class="blog-view-link {{ $view === 'list' ? 'is-active' : '' }}" href="{{ $listUrl }}"
        @if($view === 'list') aria-current="page" @endif><x-icon name="list" size="17"/> List</a>
    </nav>
    @if($view === 'grid')
      <form class="blog-columns-form" method="get" action="{{ route('blog') }}">
        <input type="hidden" name="view" value="grid">
        @if($category !== '')<input type="hidden" name="category" value="{{ $category }}">@endif
        <label for="blog-columns">Cards per row</label>
        <select id="blog-columns" name="columns">
          @foreach([2, 3, 4, 5] as $option)
            <option value="{{ $option }}" @selected($columns === $option)>{{ $option }}</option>
          @endforeach
        </select>
        <button type="submit" class="btn subtle compact">Apply</button>
      </form>
    @endif
    <span class="blog-post-total">{{ $posts->total() }} {{ $posts->total() === 1 ? 'post' : 'posts' }}</span>
  </div>

  @if($category !== '')
    <div class="blog-active-filter">
      <span>Category: <strong>{{ $category }}</strong></span>
      <a href="{{ route('blog', ['view' => $view, 'columns' => $columns]) }}">Clear filter <x-icon name="x" size="14"/></a>
    </div>
  @endif

  @if($view === 'grid')
    <div class="blog-post-grid columns-{{ $columns }}" aria-label="Published blog posts">
      @forelse($posts as $post)
        <article class="blog-post-card">
          <a class="blog-card-media" href="{{ $post->publicUrl() }}" aria-label="Read {{ $post->title }}">
            @if($post->featured_type === 'image' && $post->featuredMedia && str_starts_with($post->featuredMedia->mime_type, 'image/'))
              <img src="{{ $post->featuredMedia->previewUrl() }}" alt="{{ $post->featuredMedia->alt_text }}" loading="lazy">
            @elseif($post->featured_type === 'video' && $post->featuredMedia && str_starts_with($post->featuredMedia->mime_type, 'video/'))
              <video src="{{ $post->featuredMedia->previewUrl() }}" preload="metadata" muted playsinline aria-label="{{ $post->featuredMedia->alt_text }}"></video>
              <span class="blog-card-video-badge"><x-icon name="play-circle" size="16"/> Video</span>
            @elseif($post->featured_type === 'youtube' && preg_match('~^https://www\.youtube\.com/watch\?v=([A-Za-z0-9_-]{11})$~D', (string) $post->featured_url, $video))
              <span class="blog-card-placeholder blog-card-youtube"><x-icon name="youtube" size="37"/><span>YouTube video</span></span>
              <img class="blog-card-youtube-thumbnail" src="{{ 'https://i.ytimg.com/vi/'.$video[1].'/hqdefault.jpg' }}" alt="" loading="lazy" referrerpolicy="no-referrer">
              <span class="blog-card-video-badge"><x-icon name="play-circle" size="16"/> YouTube</span>
            @elseif($post->featured_type === 'color' && preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $post->featured_color))
              <span class="blog-card-color" style="background-color:{{ $post->featured_color }}" role="img" aria-label="Featured color"></span>
            @else
              <span class="blog-card-placeholder"><x-icon name="file-text" size="35"/><span>Article</span></span>
            @endif
          </a>
          <div class="blog-card-content">
            @if($post->category)
              <a class="blog-card-category" href="{{ route('blog', ['category' => $post->category]) }}">{{ $post->category }}</a>
            @else
              <span class="blog-card-category">Blog</span>
            @endif
            <h2><a href="{{ $post->publicUrl() }}">{{ $post->title }}</a></h2>
            @if($post->excerpt)<p class="blog-card-excerpt">{{ \Illuminate\Support\Str::limit($post->excerpt, 120) }}</p>@endif
            <div class="blog-card-bottom">
              <time datetime="{{ $post->published_at?->toDateString() }}">{{ $post->published_at?->format('d M Y') ?? 'Published' }}</time>
              <a href="{{ $post->publicUrl() }}" class="blog-card-read">Read <x-icon name="arrow-up-right" size="16"/></a>
            </div>
          </div>
        </article>
      @empty
        <div class="panel blog-empty-state">No posts match this selection.</div>
      @endforelse
    </div>
  @else
    <section class="panel blog-list-panel" aria-label="Published posts list">
      <div class="table-scroll">
        <table class="data-table blog-list-table">
          <thead><tr><th>Post</th><th>Category</th><th>Author</th><th>Published</th><th>Action</th></tr></thead>
          <tbody>
          @forelse($posts as $post)
            <tr>
              <td>
                <div class="blog-list-title">
                  <span class="blog-list-number">{{ $posts->firstItem() + $loop->index }}.</span>
                  <a href="{{ $post->publicUrl() }}">{{ $post->title }}</a>
                </div>
              </td>
              <td>{{ $post->category ?: 'Blog' }}</td>
              <td>{{ $post->author?->name ?? '—' }}</td>
              <td>{{ $post->published_at?->format('d M Y') ?? '—' }}</td>
              <td><a class="btn subtle compact" href="{{ $post->publicUrl() }}">Read</a></td>
            </tr>
          @empty
            <tr><td class="empty-cell" colspan="5">No posts match this selection.</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </section>
  @endif
  <div class="blog-page-pagination"><x-pagination :paginator="$posts" :always="true"/></div>
</div>
@endsection
