@extends('layouts.main')
@section('title','Blog')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Insights &amp; Updates</div><h1>Bambodia Blog</h1><p>Articles published by the Bambodia team.</p></div>
  <section class="panel">
    <div class="panel-head"><h2>Published articles <span class="count">{{ $posts->total() }}</span></h2></div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Article</th><th>Category</th><th>Author</th><th>Published</th><th>Read</th></tr></thead>
        <tbody>
          @forelse($posts as $post)
            <tr>
              <td>
                <div class="post-blog-entry">
                  @if($post->featuredMedia?->isApproved())
                    <img src="{{ $post->featuredMedia->previewUrl() }}" alt="{{ $post->featuredMedia->alt_text }}" loading="lazy">
                  @endif
                  <div>
                    <strong>{{ $post->title }}</strong>
                    @if($post->excerpt)<p class="text-muted">{{ \Illuminate\Support\Str::limit($post->excerpt, 160) }}</p>@endif
                  </div>
                </div>
              </td>
              <td>{{ $post->category ?: 'Blog' }}</td>
              <td>{{ $post->author?->name }}</td>
              <td>{{ $post->published_at?->format('d M Y') }}</td>
              <td><a class="btn subtle compact" href="{{ $post->publicUrl() }}">Read article</a></td>
            </tr>
          @empty
            <tr><td class="empty-cell" colspan="5">No articles have been published.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$posts"/>
  </section>
</div>
@endsection
