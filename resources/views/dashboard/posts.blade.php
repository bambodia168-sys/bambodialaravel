@extends('layouts.dashboard')
@section('title', 'Post Manager')
@section('content')
@php
  $statuses = ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'];
@endphp
<div class="page-intro">
  <div class="eyebrow">Appearance &amp; Content · Publishing</div>
  <h1>Post Manager</h1>
  <p>Create, edit and publish Bambodia news, announcements and editorial articles.</p>
</div>
<section class="panel">
  <header class="panel-head post-manager-heading">
    <div>
      <h2>Posts <span class="count">{{ $posts->total() }}</span></h2>
      <p class="text-muted post-manager-counts">
        Published {{ $counts['published'] ?? 0 }} · Drafts {{ $counts['draft'] ?? 0 }} · Archived {{ $counts['archived'] ?? 0 }}
      </p>
    </div>
    <a class="btn primary" href="{{ route('dashboard.posts.create') }}"><x-icon name="plus" size="17"/> New post</a>
  </header>
  <form method="get" action="{{ route('dashboard.posts') }}" class="post-manager-filters">
    <label class="sr-only" for="post-search">Search posts</label>
    <input id="post-search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="120" placeholder="Search by post title">
    <label class="sr-only" for="post-filter-status">Publication status</label>
    <select id="post-filter-status" name="status">
      <option value="">All statuses</option>
      @foreach($statuses as $key => $label)<option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>@endforeach
    </select>
    <button type="submit" class="btn subtle">Filter</button>
    @if(!empty($filters['q']) || !empty($filters['status']))<a class="btn subtle" href="{{ route('dashboard.posts') }}">Clear</a>@endif
  </form>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Post ID</th><th>Title</th><th>Category</th><th>Author</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead>
      <tbody>
      @forelse($posts as $post)
        <tr>
          <td class="mono">{{ $post->public_id }}</td>
          <td>
            <strong>{{ $post->title }}</strong>
            @if($post->excerpt)<div class="text-muted post-manager-excerpt">{{ $post->excerpt }}</div>@endif
          </td>
          <td>{{ $post->category ?: 'Uncategorized' }}</td>
          <td>{{ $post->author?->name ?? 'Unknown' }}</td>
          <td><span class="role-pill">{{ $statuses[$post->status] ?? ucfirst($post->status) }}</span></td>
          <td>{{ $post->updated_at?->format('d M Y') ?? '—' }}</td>
          <td>
            <x-row-action-menu :id="'post-'.$post->id" :label="$post->title">
              <a href="{{ route('dashboard.posts.edit', $post) }}"><x-icon name="edit" size="16"/> Edit</a>
              <a href="{{ route('dashboard.posts.preview', $post) }}" target="_blank" rel="noopener"><x-icon name="eye" size="16"/> Preview</a>
              @if($post->status === 'published')
                <a href="{{ $post->publicUrl() }}" target="_blank" rel="noopener"><x-icon name="external-link" size="16"/> Public page</a>
              @endif
            </x-row-action-menu>
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="empty-cell">No posts match your search. Select New post to write the first article.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <x-pagination :paginator="$posts"/>
</section>
@endsection
