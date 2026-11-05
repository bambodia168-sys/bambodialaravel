@extends('layouts.dashboard')
@section('title',$type === 'page' ? 'Page Manager' : 'Post Manager')
@section('content')
<div class="page-intro"><div class="eyebrow">Content & Policies</div><h1>{{ $type === 'page' ? 'Page Manager' : 'Post Manager' }}</h1><p>Manage published {{ $type }} content and its public URL.</p></div>
<section class="panel">
  <div class="panel-head"><h2>{{ ucfirst($type) }}s <span class="count">{{ $entries->total() }}</span></h2><button type="button" class="btn primary" data-modal-open="content-new">New {{ $type }}</button></div>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Public ID</th><th>Title</th><th>Status</th><th>Published</th><th>URL</th><th>Action</th></tr></thead><tbody>
  @forelse($entries as $entry)
    <tr><td class="mono">{{ $entry->public_id }}</td><td><strong>{{ $entry->title }}</strong></td><td><span class="role-pill">{{ ucfirst($entry->status) }}</span></td><td>{{ $entry->published_at?->format('d M Y') ?? 'Not published' }}</td>
    <td>@if($entry->status === 'published')<a class="btn subtle compact" href="{{ $entry->publicUrl() }}" target="_blank" rel="noopener noreferrer">View</a>@else<span class="text-muted">Draft</span>@endif</td>
    <td><button type="button" class="btn subtle compact" data-modal-open="content-{{ $entry->id }}"><x-icon name="settings" size="15"/> Configure</button>
      <dialog id="content-{{ $entry->id }}" class="config-dialog wide-dialog">
        <form method="post" action="{{ route($prefix.'.update',$entry) }}">@csrf @method('PATCH')
        <h2>Configure {{ $type }}</h2>
        <label for="content-title-{{ $entry->id }}">Title</label><input name="title" id="content-title-{{ $entry->id }}" value="{{ $entry->title }}" required maxlength="180">
        <label for="content-body-{{ $entry->id }}">Content</label><textarea name="body" id="content-body-{{ $entry->id }}" rows="12" minlength="20" required>{{ $entry->body }}</textarea>
        <label for="content-status-{{ $entry->id }}">Publication status</label><select name="status" id="content-status-{{ $entry->id }}"><option value="draft">Draft</option><option value="published" @selected($entry->status === 'published')>Published</option></select>
        <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Save changes</button></div>
        </form>
      </dialog>
    </td></tr>
  @empty<tr><td class="empty-cell" colspan="6">No {{ $type }} records have been created.</td></tr>@endforelse
  </tbody></table></div>
  <x-pagination :paginator="$entries"/>
</section>
<dialog class="config-dialog wide-dialog" id="content-new">
  <form method="post" action="{{ route($prefix.'.store') }}">@csrf
    <h2>Create {{ $type }}</h2>
    <label for="content-new-title">Title</label><input id="content-new-title" name="title" maxlength="180" required>
    <label for="content-new-body">Content</label><textarea id="content-new-body" name="body" rows="12" minlength="20" required></textarea>
    <label for="content-new-status">Publication status</label><select name="status" id="content-new-status"><option value="draft">Draft</option><option value="published">Published</option></select>
    <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save {{ $type }}</button></div>
  </form>
</dialog>
@endsection
