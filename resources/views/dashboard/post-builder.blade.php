@extends('layouts.dashboard')
@section('title', $post->exists ? 'Edit Post · '.$post->title : 'New Post · Bambodia')
@section('content')
@php
  $editing = $post->exists;
  $state = old('status', $post->status ?: 'draft');
  $body = old('body', $editing ? $post->body : '');
  $editorHtml = $post->body_format === 'html' || old('body')
    ? \App\Support\PolicyContent::sanitize($body)
    : nl2br(e($body));
  $selectedMedia = old('featured_media_id', $post->featured_media_id);
@endphp
<div class="policy-builder post-builder" data-post-builder>
  <div class="policy-builder-heading">
    <div>
      <a class="policy-back" href="{{ route('dashboard.posts') }}"><x-icon name="chevron-left" size="16"/> Back to Post Manager</a>
      <div class="eyebrow">Content &amp; Publishing · Post Builder</div>
      <h1>{{ $editing ? 'Edit blog post' : 'Create blog post' }}</h1>
      <p class="text-muted">Write and format your story, then review its publication details.</p>
    </div>
    <div class="policy-builder-top-actions">
      @if($editing)
        <a class="btn subtle" href="{{ route('dashboard.posts.preview', $post) }}" target="_blank" rel="noopener">
          <x-icon name="eye" size="16"/> Preview
        </a>
        @if($post->status === 'published')
          <a class="btn subtle" href="{{ $post->publicUrl() }}" target="_blank" rel="noopener">
            <x-icon name="external-link" size="16"/> View published
          </a>
        @endif
      @endif
      <button class="btn primary" form="policy-builder-form" type="submit"><x-icon name="save" size="16"/> {{ $editing ? 'Save changes' : 'Create post' }}</button>
    </div>
  </div>
  @if($errors->any())
    <div class="post-builder-errors" role="alert">
      <strong>Please correct the following:</strong>
      <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
  @endif
  <form id="policy-builder-form" data-editor-min-length="20" method="post"
    action="{{ $editing ? route('dashboard.posts.update', $post) : route('dashboard.posts.store') }}">
    @csrf @if($editing) @method('PATCH') @endif
    <div class="policy-builder-columns">
      <main class="policy-builder-main" aria-label="Main Content">
        <section class="panel policy-editor-panel post-editor-panel">
          <div class="post-builder-section-title"><x-icon name="file-text" size="19"/><h2>Main Content</h2></div>
          <label class="policy-builder-label" for="post-title">Post title</label>
          <div class="policy-title-input-wrap">
            <input id="post-title" type="text" name="title" value="{{ old('title', $post->title) }}"
                   minlength="4" maxlength="180" placeholder="Give your post a clear, engaging title" required>
          </div>
          <div class="policy-reference-row">
            <span>Post ID: <strong class="mono">{{ $editing ? $post->public_id : 'Assigned when saved' }}</strong></span>
            <span>Public URL: <strong>{{ $editing && $post->status === 'published' ? $post->publicUrl() : 'Available after publishing' }}</strong></span>
          </div>
          <label class="policy-builder-label" for="post-excerpt">Short summary</label>
          <textarea id="post-excerpt" class="post-builder-excerpt" name="excerpt" maxlength="320"
            rows="3" placeholder="Summarize the post for readers and search results.">{{ old('excerpt', $post->excerpt) }}</textarea>
          <div class="policy-editor-heading">
            <label class="policy-builder-label" for="post-body-editor">Post content</label>
            <small>Headings, paragraphs, lists, quotes, tables and links</small>
          </div>
          <div class="policy-editor-toolbar" role="toolbar" aria-label="Post formatting toolbar">
            <select data-policy-block aria-label="Paragraph or heading style">
              <option value="p">Paragraph</option><option value="h2">Heading 2</option>
              <option value="h3">Heading 3</option><option value="h4">Heading 4</option>
            </select>
            @foreach([
              ['bold','bold','Bold'], ['italic','italic','Italic'], ['underline','underline','Underline'],
              ['insertUnorderedList','list','Bulleted list'], ['insertOrderedList','list-ordered','Numbered list'],
              ['createLink','link','Insert link'], ['unlink','unlink','Remove link'],
              ['blockquote','message','Quote'], ['table','table','Insert table'],
              ['horizontal','minus','Divider'], ['removeFormat','eraser','Clear formatting'],
              ['undo','undo','Undo'], ['redo','redo','Redo']
            ] as $tool)
              <button class="policy-editor-tool" type="button" data-policy-command="{{ $tool[0] }}"
                title="{{ $tool[2] }}" aria-label="{{ $tool[2] }}"><x-icon :name="$tool[1]" size="17"/></button>
            @endforeach
            <button type="button" class="policy-editor-tool" data-policy-fullscreen
              title="Toggle fullscreen" aria-label="Toggle fullscreen editor"><x-icon name="eye" size="17"/></button>
          </div>
          <div id="post-body-editor" class="policy-editor-area post-body-editor"
               data-policy-editor role="textbox" contenteditable="true" aria-multiline="true"
               aria-label="Post content" spellcheck="true">{!! $editorHtml !!}</div>
          <textarea name="body" data-policy-body class="policy-editor-source" aria-label="Post HTML source">{{ $body }}</textarea>
          <p class="policy-editor-hint">Your formatted content is sanitized when saved. Scripts, unsafe links, and embedded forms are not allowed.</p>
        </section>
      </main>
      <aside class="policy-builder-sidebar post-builder-sidebar" aria-label="Right Sidebar">
        <section class="panel policy-side-panel">
          <div class="policy-side-head"><x-icon name="file-check" size="19"/><strong>Publication</strong></div>
          <label class="policy-builder-label" for="post-status">Status</label>
          <select id="post-status" name="status">
            @foreach(['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'] as $key => $label)
              <option value="{{ $key }}" @selected($state === $key)>{{ $label }}</option>
            @endforeach
          </select>
          <p class="policy-help">Drafts and archived posts are private. Select Published to make this article visible in the public blog.</p>
          <dl class="post-builder-meta">
            <div><dt>Author</dt><dd>{{ $post->author?->name ?? auth()->user()?->name }}</dd></div>
            <div><dt>Published</dt><dd>{{ $post->published_at?->format('d M Y') ?? 'Not published' }}</dd></div>
            <div><dt>Last updated</dt><dd>{{ $post->updated_at?->format('d M Y H:i') ?? 'Not saved' }}</dd></div>
          </dl>
        </section>
        <section class="panel policy-side-panel">
          <div class="policy-side-head"><x-icon name="settings" size="19"/><strong>Post details</strong></div>
          <label class="policy-builder-label" for="post-category">Category</label>
          <input id="post-category" name="category" value="{{ old('category', $post->category) }}"
                 maxlength="80" placeholder="e.g. Marketplace updates">
          <label class="policy-builder-label" for="post-tags">Tags</label>
          <input id="post-tags" name="tags" value="{{ old('tags', $post->tags) }}"
                 maxlength="320" placeholder="News, Product, Creators">
          <p class="policy-help">Use commas to separate up to 10 tags (35 characters per tag).</p>
        </section>
        <section class="panel policy-side-panel">
          <div class="policy-side-head"><x-icon name="image" size="19"/><strong>Featured image</strong></div>
          <label class="policy-builder-label" for="post-featured-media">Choose an approved image</label>
          <select id="post-featured-media" name="featured_media_id" data-post-featured-select>
            <option value="">No featured image</option>
            @foreach($media as $asset)
              <option value="{{ $asset->id }}" data-image-url="{{ $asset->previewUrl() }}"
                data-alt="{{ $asset->alt_text }}" @selected((string)$selectedMedia === (string)$asset->id)>
                {{ \Illuminate\Support\Str::limit($asset->original_name, 55) }}
              </option>
            @endforeach
          </select>
          <div class="post-featured-preview" data-post-featured-preview aria-live="polite">
            <img alt="" hidden><span>Choose an approved Media Library image to preview it.</span>
          </div>
          <p class="policy-help">Only images approved by Media Library review are selectable. The selection is saved with the post.</p>
          <a class="post-builder-side-link" href="{{ route('dashboard.media.index') }}"><x-icon name="image" size="15"/> Open Media Library</a>
        </section>
        <section class="panel policy-side-panel">
          <div class="policy-side-head"><x-icon name="search" size="19"/><strong>Search metadata</strong></div>
          <label class="policy-builder-label" for="post-seo-title">SEO title</label>
          <input id="post-seo-title" name="seo_title" value="{{ old('seo_title', $post->seo_title) }}"
                 maxlength="180" placeholder="Optional search title">
          <label class="policy-builder-label" for="post-seo-description">Meta description</label>
          <textarea id="post-seo-description" name="seo_description" rows="3" maxlength="320"
            placeholder="Optional description for search engines.">{{ old('seo_description', $post->seo_description) }}</textarea>
          <p class="policy-help">If omitted, the post title and summary are used.</p>
        </section>
        @if($editing)
          <section class="panel policy-side-panel post-danger-panel">
            <div class="policy-side-head"><x-icon name="archive" size="19"/><strong>Delete post</strong></div>
            <p class="policy-help">Deletion removes this post and its public URL permanently. Media files remain in Media Library.</p>
            <button type="button" class="btn subtle" data-modal-open="post-delete-dialog">Delete post…</button>
          </section>
        @endif
      </aside>
    </div>
  </form>
  @if($editing)
    <dialog class="config-dialog wide-dialog post-delete-dialog" id="post-delete-dialog">
      <form method="post" action="{{ route('dashboard.posts.destroy', $post) }}">
        @csrf @method('DELETE')
        <h2>Delete this post?</h2>
        <p>This action cannot be undone. Type the exact post title to confirm.</p>
        <label for="post-confirm-title">Post title: <strong>{{ $post->title }}</strong></label>
        <input id="post-confirm-title" name="confirm_title" required maxlength="180" autocomplete="off">
        <div class="dialog-actions">
          <button class="btn subtle" type="button" data-modal-close>Cancel</button>
          <button class="btn primary post-delete-confirm" type="submit">Delete permanently</button>
        </div>
      </form>
    </dialog>
  @endif
</div>
@endsection
