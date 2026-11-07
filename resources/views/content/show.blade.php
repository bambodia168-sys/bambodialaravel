@extends('layouts.main')
@section('title', $entry->type === 'post' ? ($entry->seo_title ?: $entry->title) : $entry->title)
@if($entry->type === 'post')
  @section('meta_description', $entry->seo_description ?: $entry->excerpt ?: \Illuminate\Support\Str::limit(\App\Support\PolicyContent::plain($entry->body), 155))
@endif
@section('content')
<div class="container inner-page">
  <article class="panel editorial-page">
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
      @if($entry->excerpt)<p class="post-public-excerpt">{{ $entry->excerpt }}</p>@endif
      @if($entry->featuredMedia?->isApproved())
        <img class="post-public-image" src="{{ $entry->featuredMedia->previewUrl() }}"
             alt="{{ $entry->featuredMedia->alt_text }}" loading="lazy">
      @endif
      <div class="editorial-copy policy-formatted-content">
        @if($entry->body_format === 'html')
          {!! \App\Support\PolicyContent::sanitize($entry->body) !!}
        @else
          {!! nl2br(e($entry->body)) !!}
        @endif
      </div>
      @if($entry->tags)
        <div class="post-public-tags" aria-label="Post tags">
          @foreach(array_filter(array_map('trim',explode(',',$entry->tags))) as $tag)<span>{{ $tag }}</span>@endforeach
        </div>
      @endif
    @else
      <div class="editorial-copy">{!! nl2br(e($entry->body)) !!}</div>
    @endif
  </article>
</div>
@endsection
