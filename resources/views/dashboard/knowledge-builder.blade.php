@extends('layouts.dashboard')
@section('title', $article->exists ? 'Edit Article · '.$article->title : 'New Knowledge Article')
@section('content')
@php
  $editing = $article->exists;
  $body = old('body', $editing ? $article->body : '');
  $format = old('body_format', $editing ? $article->body_format : 'html');
  $html = \App\Support\PolicyContent::display($body, $format);
  $state = old('status', $article->status ?: 'draft');
@endphp
<div class="policy-builder support-knowledge-builder">
  <div class="policy-builder-heading">
    <div>
      <a class="policy-back" href="{{ route('dashboard.knowledge.index') }}"><x-icon name="chevron-left" size="16"/> Knowledge Base</a>
      <div class="eyebrow">Customer Care · Knowledge Builder</div>
      <h1>{{ $editing ? 'Edit help article' : 'New help article' }}</h1>
      <p class="text-muted">Write clear guidance, check the preview, then publish when ready.</p>
    </div>
    <div class="policy-builder-top-actions">
      @if($editing)<a class="btn subtle" href="{{ route('dashboard.knowledge.preview',$article) }}" target="_blank" rel="noopener"><x-icon name="eye" size="16"/> Preview</a>@endif
      <button class="btn primary" type="submit" form="policy-builder-form"><x-icon name="save" size="16"/> Save article</button>
    </div>
  </div>
  <form method="post" id="policy-builder-form" action="{{ $editing ? route('dashboard.knowledge.update',$article) : route('dashboard.knowledge.store') }}">
    @csrf @if($editing) @method('PATCH') @endif
    <input name="body_format" type="hidden" value="html">
    <div class="policy-builder-columns">
      <main class="policy-builder-main">
        <section class="panel policy-editor-panel">
          <label class="policy-builder-label" for="knowledge-title">Article title</label>
          <input type="text" id="knowledge-title" name="title" value="{{ old('title',$article->title) }}" minlength="6" maxlength="180" placeholder="A clear, searchable question or title" required>
          <label class="policy-builder-label" for="knowledge-excerpt">Short summary</label>
          <textarea id="knowledge-excerpt" name="excerpt" minlength="25" maxlength="320" rows="3" required placeholder="Explain what the reader will learn.">{{ old('excerpt',$article->excerpt) }}</textarea>
          <div class="policy-editor-heading">
            <label class="policy-builder-label" for="knowledge-editor">Knowledge article content</label><small>Clean headings, lists, links and formatted text</small>
          </div>
          <div class="policy-editor-toolbar" role="toolbar" aria-label="Knowledge editor toolbar">
            <select data-policy-block aria-label="Paragraph and heading style"><option value="p">Paragraph</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option><option value="h4">Heading 4</option></select>
            @foreach([
              ['bold','bold','Bold'],['italic','italic','Italic'],['underline','underline','Underline'],
              ['insertUnorderedList','list','Bulleted list'],['insertOrderedList','list-ordered','Numbered list'],
              ['createLink','link','Insert link'],['unlink','unlink','Remove link'],
              ['blockquote','message','Quote'],['table','table','Table'],
              ['horizontal','minus','Horizontal divider'],['removeFormat','eraser','Clear formatting'],
              ['undo','undo','Undo'],['redo','redo','Redo']
            ] as $action)
              <button type="button" class="policy-editor-tool" data-policy-command="{{ $action[0] }}" title="{{ $action[2] }}" aria-label="{{ $action[2] }}"><x-icon :name="$action[1]" size="17"/></button>
            @endforeach
            <button type="button" class="policy-editor-tool" data-policy-fullscreen aria-label="Toggle fullscreen editor" title="Toggle fullscreen"><x-icon name="eye" size="17"/></button>
          </div>
          <div id="knowledge-editor" class="policy-editor-area" data-policy-editor contenteditable="true" role="textbox" aria-label="Article content" aria-multiline="true">{!! $html !!}</div>
          <textarea name="body" data-policy-body class="policy-editor-source" aria-label="Article HTML source">{{ $body }}</textarea>
          <p class="policy-editor-hint">Article content is sanitized on the server. Avoid including private passwords, keys or confidential customer data.</p>
        </section>
      </main>
      <aside class="policy-builder-sidebar">
        <section class="panel policy-builder-side-panel">
          <h2><x-icon name="file-check" size="17"/> Publication</h2>
          <label class="policy-builder-label" for="knowledge-status">Status</label>
          <select id="knowledge-status" name="status">
            @foreach(['draft'=>'Draft','published'=>'Published','archived'=>'Archived'] as $value=>$label)
              <option value="{{ $value }}" @selected($state === $value) @disabled(!$editing && $value !== 'draft')>{{ $label }}</option>
            @endforeach
          </select>
          @if($editing)
            <label class="check-label"><input type="checkbox" name="confirm_review" value="1"> I have reviewed the article and approve this publication state.</label>
            <p class="text-muted">Publishing or editing a published article requires fresh review. Archived and draft articles are not public.</p>
          @else
            <p class="text-muted">Save the draft before publishing. Articles are never published automatically.</p>
          @endif
        </section>
        <section class="panel policy-builder-side-panel">
          <h2><x-icon name="book-open" size="17"/> Article details</h2>
          <label class="policy-builder-label" for="knowledge-category">Category</label>
          <select id="knowledge-category" name="category" required>
            @foreach(\App\Support\SupportKnowledge::CATEGORIES as $key=>$label)
              <option value="{{ $key }}" @selected(old('category',$article->category)===$key)>{{ $label }}</option>
            @endforeach
          </select>
          <dl class="support-knowledge-facts">
            <dt>Article ID</dt><dd class="mono">{{ $editing ? $article->public_code : 'Assigned when saved' }}</dd>
            <dt>Published date</dt><dd>{{ $article->published_at?->format('d M Y') ?? 'Unpublished' }}</dd>
            <dt>Updated date</dt><dd>{{ $article->updated_at?->format('d M Y') ?? 'Not yet saved' }}</dd>
          </dl>
          @if($editing)
            <a class="btn subtle" href="{{ $article->status === 'published' ? $article->publicUrl() : route('dashboard.knowledge.preview',$article) }}" target="_blank" rel="noopener">View article</a>
          @endif
        </section>
      </aside>
    </div>
  </form>
</div>
@endsection
