@extends('layouts.main')
@section('title', $entry->type === 'post' ? ($entry->seo_title ?: $entry->title) : $entry->title)
@if($entry->type === 'post')
  @section('meta_description', $entry->seo_description ?: $entry->excerpt ?: \Illuminate\Support\Str::limit(\App\Support\PolicyContent::plain($entry->body), 155))
@endif
@section('content')
<div class="container inner-page">
  <div class="{{ $entry->type === 'post' && empty($preview) ? 'blog-article-layout' : '' }}">
    <article class="panel editorial-page {{ $entry->type === 'post' ? 'blog-article-main' : '' }}">
      @if($entry->type === 'post')
        @if(in_array($entry->featured_type, ['image', 'video'], true) && $entry->featuredMedia)
          @if($entry->featured_type === 'video' && str_starts_with($entry->featuredMedia->mime_type, 'video/'))
            <figure class="post-featured-figure blog-article-cover">
              <video class="post-public-video" controls preload="metadata" playsinline
                src="{{ $entry->featuredMedia->previewUrl() }}" aria-label="{{ $entry->featuredMedia->alt_text }}"></video>
            </figure>
          @elseif($entry->featured_type === 'image' && str_starts_with($entry->featuredMedia->mime_type, 'image/'))
            <figure class="post-featured-figure blog-article-cover">
              <img class="post-public-image" src="{{ $entry->featuredMedia->previewUrl() }}"
                alt="{{ $entry->featuredMedia->alt_text }}" loading="eager">
            </figure>
          @endif
        @elseif($entry->featured_type === 'youtube' && preg_match('~^https://www\.youtube\.com/watch\?v=([A-Za-z0-9_-]{11})$~D', (string) $entry->featured_url, $youtubeMatches))
          <figure class="post-featured-figure blog-article-cover">
            <iframe class="post-public-youtube" loading="lazy" title="Featured YouTube video"
              referrerpolicy="strict-origin-when-cross-origin"
              src="{{ 'https://www.youtube-nocookie.com/embed/'.$youtubeMatches[1] }}"
              allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture"
              allowfullscreen></iframe>
          </figure>
        @elseif($entry->featured_type === 'color' && preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $entry->featured_color))
          <figure class="post-featured-figure blog-article-cover">
            <div class="post-public-color" style="background-color:{{ $entry->featured_color }}" role="img" aria-label="Featured color {{ $entry->featured_color }}"></div>
          </figure>
        @endif
        <div class="blog-article-content">
          @if(!empty($preview))
            <p class="blog-preview-label"><strong>Private preview — this article is not public until published.</strong></p>
          @endif
          <h1>{{ $entry->title }}</h1>
          @php
            $authorName = $entry->author?->name ?: 'Bambodia';
            $authorInitial = mb_strtoupper(mb_substr($authorName, 0, 1));
          @endphp
          <div class="blog-article-byline" aria-label="Article details">
            <span class="blog-author-avatar" aria-hidden="true">{{ $authorInitial }}</span>
            <strong class="blog-author-name">{{ $authorName }}</strong>
            <span class="blog-meta-divider" aria-hidden="true">·</span>
            <span>{{ $entry->published_at ? 'Published '.$entry->published_at->format('d M Y') : 'Not published' }}</span>
            <span class="blog-meta-divider" aria-hidden="true">·</span>
            <span>{{ $entry->category ?: 'Blog' }}</span>
            <span class="blog-meta-divider" aria-hidden="true">·</span>
            <strong class="blog-post-id">{{ $entry->public_id }}</strong>
            <button type="button" class="blog-share-button" data-article-share
              data-share-url="{{ $entry->publicUrl() }}" data-share-title="{{ $entry->title }}"
              aria-label="Share article" title="Share article"><x-icon name="share-2" size="17"/></button>
            <span class="blog-share-feedback" data-article-share-status role="status" aria-live="polite"></span>
          </div>
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
          @if(empty($preview))
            <section class="blog-comments" id="comments" aria-label="Article comments">
              <div class="blog-comments-heading">
                <h2>Comments <span class="blog-comments-count">{{ $comments->total() }}</span></h2>
              </div>
              @auth
                <form action="{{ route('blog.comments.store', ['content' => $entry->public_id.'_'.$entry->slug]) }}" method="post" class="blog-comment-form">
                  @csrf
                  <label for="blog-comment-body">Add a comment</label>
                  <textarea id="blog-comment-body" name="body" rows="4" maxlength="1500"
                    placeholder="Share a constructive thought about this article…" required>{{ old('body') }}</textarea>
                  @error('body')<p class="blog-comment-error" role="alert">{{ $message }}</p>@enderror
                  <div class="blog-comment-form-footer">
                    <span>Keep it respectful. 1,500 characters maximum.</span>
                    <button class="btn primary compact" type="submit"><x-icon name="send" size="16"/> Post comment</button>
                  </div>
                </form>
              @else
                <p class="blog-comment-login">Join the conversation. <a href="{{ route('login') }}">Sign in to comment</a>.</p>
              @endauth
              <div class="blog-comment-list">
                @forelse($comments as $comment)
                  <article class="blog-comment">
                    <span class="blog-comment-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($comment->user?->name ?: 'U', 0, 1)) }}</span>
                    <div class="blog-comment-body">
                      <div class="blog-comment-topline">
                        <strong>{{ $comment->user?->name ?: 'Former member' }}</strong>
                        <time datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->format('d M Y, H:i') }}</time>
                      </div>
                      <p>{{ $comment->body }}</p>
                      @auth
                        @if(auth()->id() === $comment->user_id || in_array(auth()->user()->role, ['admin', 'super_admin'], true))
                          <form method="post" action="{{ route('blog.comments.destroy', $comment) }}"
                            onsubmit="return confirm('Delete this comment?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="blog-comment-delete">Delete comment</button>
                          </form>
                        @endif
                      @endauth
                    </div>
                  </article>
                @empty
                  <p class="blog-comment-empty">No comments yet. Be the first to contribute.</p>
                @endforelse
              </div>
              @if($comments->hasPages())
                <nav class="blog-comment-pagination" aria-label="Comment pages">
                  @if($comments->onFirstPage())<span>Previous</span>@else<a href="{{ $comments->previousPageUrl() }}#comments">Previous</a>@endif
                  <span>{{ $comments->currentPage() }} / {{ $comments->lastPage() }}</span>
                  @if($comments->hasMorePages())<a href="{{ $comments->nextPageUrl() }}#comments">Next</a>@else<span>Next</span>@endif
                </nav>
              @endif
            </section>
          @endif
        </div>
      @else
        <div class="eyebrow">{{ ucfirst($entry->type) }} · {{ $entry->public_id }}</div>
        <h1>{{ $entry->title }}</h1>
        <div class="post-public-meta">
          <span>{{ $entry->published_at ? 'Published '.$entry->published_at->format('d M Y') : 'Not published' }}</span>
          @if($entry->author)<span>· {{ $entry->author->name }}</span>@endif
        </div>
        <div class="editorial-copy">{!! nl2br(e($entry->body)) !!}</div>
      @endif
    </article>
    @if($entry->type === 'post' && empty($preview))
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
