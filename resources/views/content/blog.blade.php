@extends('layouts.main')
@section('title','Blog')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Insights & Updates</div><h1>Bambodia Blog</h1><p>Articles published by the Bambodia team.</p></div>
  <section class="panel"><div class="panel-head"><h2>Published articles <span class="count">{{ $posts->total() }}</span></h2></div>
    <div class="table-scroll"><table class="data-table"><thead><tr><th>Article</th><th>Author</th><th>Published</th><th>Read</th></tr></thead><tbody>
      @forelse($posts as $post)<tr><td><strong>{{ $post->title }}</strong></td><td>{{ $post->author?->name }}</td><td>{{ $post->published_at?->format('d M Y') }}</td><td><a class="btn subtle compact" href="{{ $post->publicUrl() }}">Read article</a></td></tr>
      @empty<tr><td class="empty-cell" colspan="4">No articles have been published.</td></tr>@endforelse
    </tbody></table></div><x-pagination :paginator="$posts"/>
  </section>
</div>
@endsection
