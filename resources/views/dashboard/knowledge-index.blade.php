@extends('layouts.dashboard')
@section('title','Knowledge Base')
@section('content')
<div class="page-intro">
  <div class="eyebrow">Customer Care · Articles</div><h1>Knowledge Base</h1>
  <p>Create, review, and publish helpful guidance. Only reviewed, published articles appear on the public Support Center.</p>
</div>
<section class="panel">
  <div class="panel-head">
    <div><h2>Articles <span class="count">{{ $articles->total() }}</span></h2><p class="text-muted">Drafts are private. Published articles are visible to everyone.</p></div>
    <div class="toolbar-actions">
      <form method="post" action="{{ route('dashboard.knowledge.seed') }}" onsubmit="return confirm('Prepare nine starter help articles as unpublished drafts? Existing articles are preserved.')">
        @csrf <button class="btn subtle" type="submit"><x-icon name="book-open" size="16"/> Create starter drafts</button>
      </form>
      <a class="btn primary" href="{{ route('dashboard.knowledge.create') }}"><x-icon name="edit" size="16"/> New article</a>
    </div>
  </div>
  <form class="support-admin-search" method="get" action="{{ route('dashboard.knowledge.index') }}">
    <input type="search" name="q" value="{{ $search }}" maxlength="120" placeholder="Search article title" aria-label="Search knowledge articles">
    <select name="status" aria-label="Publication status"><option value="">All statuses</option>
      @foreach(['draft'=>'Draft','published'=>'Published','archived'=>'Archived'] as $value=>$label)
        <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
      @endforeach
    </select>
    <button class="btn subtle" type="submit">Filter</button>
    @if($search !== '' || $status !== '')<a class="btn subtle" href="{{ route('dashboard.knowledge.index') }}">Clear</a>@endif
  </form>
  <div class="table-scroll">
    <table class="data-table"><thead><tr><th>Article ID</th><th>Title</th><th>Category</th><th>Status</th><th>Author</th><th>Updated</th><th>Action</th></tr></thead><tbody>
      @forelse($articles as $item)
      <tr>
        <td class="mono">{{ $item->public_code }}</td>
        <td><a href="{{ route('dashboard.knowledge.edit',$item) }}"><strong>{{ $item->title }}</strong></a></td>
        <td>{{ \App\Support\SupportKnowledge::CATEGORIES[$item->category] ?? ucfirst($item->category) }}</td>
        <td><span class="role-pill">{{ ucfirst($item->status) }}</span></td>
        <td>{{ $item->author?->name ?? 'Unknown' }}</td>
        <td>{{ $item->updated_at?->format('d M Y') }}</td>
        <td><a href="{{ route('dashboard.knowledge.edit',$item) }}" class="btn subtle compact">Edit</a>
          @if($item->status === 'published')<a href="{{ $item->publicUrl() }}" class="btn subtle compact" target="_blank" rel="noopener">View</a>
          @else<a href="{{ route('dashboard.knowledge.preview',$item) }}" class="btn subtle compact" target="_blank" rel="noopener">Preview</a>@endif
        </td>
      </tr>
      @empty<tr><td colspan="7" class="empty-cell">No knowledge articles yet. Create an article or prepare the starter drafts.</td></tr>@endforelse
    </tbody></table>
  </div>
  <x-pagination :paginator="$articles"/>
</section>
@endsection
