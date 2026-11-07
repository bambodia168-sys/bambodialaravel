@extends('layouts.dashboard')
@section('title', $policy->exists ? 'Edit Policy · '.$policy->title : 'New Policy')
@section('content')
@php
  $editing = $policy->exists;
  $content = old('body', $editing ? $policy->body : '');
  $format = old('body_format', $editing ? $policy->body_format : 'html');
  $editorHtml = \App\Support\PolicyContent::display($content, $format);
  $types = ['general'=>'General', 'privacy'=>'Privacy', 'cookies'=>'Cookies', 'gdpr'=>'Data protection', 'terms'=>'Terms', 'commerce'=>'Commerce', 'security'=>'Security'];
  $status = old('status', $policy->status ?: 'draft');
  $url = $editing ? $policy->publicUrl() : '';
@endphp
<div class="policy-builder" data-policy-builder>
  <div class="policy-builder-heading">
    <div>
      <a class="policy-back" href="{{ route('dashboard.policies') }}"><x-icon name="chevron-left" size="16"/> Back to Policy Center</a>
      <div class="eyebrow">Content &amp; Policies · Policy Builder</div>
      <h1>{{ $editing ? 'Edit legal policy' : 'Create legal policy' }}</h1>
      <p class="text-muted">Edit the full document, review its metadata and publish only after legal approval.</p>
    </div>
    <div class="policy-builder-top-actions">
      @if($editing)<a class="btn subtle" href="{{ route('dashboard.policies.preview',$policy) }}" target="_blank" rel="noopener"><x-icon name="eye" size="16"/> Preview</a>@endif
      <button class="btn primary" form="policy-builder-form" type="submit"><x-icon name="save" size="16"/> Save policy</button>
    </div>
  </div>
  @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
  @if($errors->any())
    <div class="notice error" role="alert"><strong>Please review these fields:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
  @endif
  <form id="policy-builder-form" action="{{ $editing ? route('dashboard.policies.update',$policy) : route('dashboard.policies.store') }}" method="post">
    @csrf @if($editing) @method('PATCH') @endif
    <input type="hidden" name="body_format" value="html">
    <div class="policy-builder-columns">
      <main class="policy-builder-main">
        <section class="panel policy-editor-panel">
          <label class="policy-builder-label" for="policy-builder-title">Policy title</label>
          <div class="policy-title-input-wrap">
            <input id="policy-builder-title" type="text" name="title" value="{{ old('title',$policy->title) }}" maxlength="180" minlength="4" placeholder="Enter policy title" required>
            <button type="button" class="policy-icon-button" data-policy-copy-url="{{ $url }}" aria-label="Copy the full policy URL" title="{{ $editing ? 'Copy public policy URL' : 'Save first to generate a public URL' }}" @disabled(!$editing)>
              <x-icon name="copy" size="18"/>
            </button>
          </div>
          <div class="policy-reference-row">
            <span>Policy ID: <strong class="mono">{{ $editing ? $policy->public_code : 'Assigned after saving' }}</strong></span>
            @if($editing)<span class="policy-copy-feedback" aria-live="polite"></span>@endif
          </div>
          <div class="policy-editor-heading"><label class="policy-builder-label" for="policy-editor-area">Policy content</label><small>Formatting is saved as sanitized HTML</small></div>
          <div class="policy-editor-toolbar" role="toolbar" aria-label="Policy rich text tools">
            <select data-policy-block aria-label="Paragraph or heading style" title="Text style"><option value="p">Paragraph</option><option value="h1">H1</option><option value="h2">H2</option><option value="h3">H3</option></select>
            @foreach([
              ['bold','bold','Bold'],['italic','italic','Italic'],['underline','underline','Underline'],
              ['strikeThrough','strikethrough','Strike'],['insertUnorderedList','list','Bulleted list'],
              ['insertOrderedList','list-ordered','Numbered list'],['justifyLeft','align-left','Align left'],
              ['justifyCenter','align-center','Align center'],['justifyRight','align-right','Align right'],
              ['justifyFull','align-justify','Justify'],['createLink','link','Insert link'],
              ['unlink','unlink','Remove link'],['table','table','Insert table'],
              ['blockquote','message','Quote'],['code','code','Code block'],
              ['horizontal','minus','Divider'],['checklist','clipboard-check','Checklist'],
              ['removeFormat','eraser','Clear formatting'],['undo','undo','Undo'],['redo','redo','Redo']
            ] as $action)
              <button type="button" class="policy-editor-tool" data-policy-command="{{ $action[0] }}" title="{{ $action[2] }}" aria-label="{{ $action[2] }}"><x-icon :name="$action[1]" size="17"/></button>
            @endforeach
            <button class="policy-editor-tool" type="button" data-policy-fullscreen title="Toggle fullscreen" aria-label="Toggle editor fullscreen"><x-icon name="eye" size="17"/></button>
          </div>
          <div id="policy-editor-area" class="policy-editor-area" data-policy-editor role="textbox" aria-label="Policy content" aria-multiline="true" contenteditable="true" spellcheck="true">{!! $editorHtml !!}</div>
          <textarea class="policy-editor-source" name="body" data-policy-body aria-label="Policy HTML source">{{ $content }}</textarea>
          <p class="policy-editor-hint">Format headings with the toolbar. Markdown headings in existing drafts appear as real headings rather than raw # characters.</p>
        </section>
      </main>
      <aside class="policy-builder-sidebar" aria-label="Policy settings">
        <section class="panel policy-side-panel">
          <div class="policy-side-head"><x-icon name="file-check" size="19"/><strong>Publication</strong></div>
          <label class="policy-builder-label" for="policy-status">Status</label>
          @if($editing)
            <select id="policy-status" name="status">
              @foreach(['draft'=>'Draft','published'=>'Published','archived'=>'Archived'] as $value=>$label)
                <option value="{{ $value }}" @selected($status===$value)>{{ $label }}</option>
              @endforeach
            </select>
          @else
            <input id="policy-status" value="Draft (save to begin review)" readonly>
          @endif
          <label class="policy-review-check"><input type="checkbox" name="confirm_legal_review" value="1"> Legal review completed and approved for publication</label>
          <p class="policy-help">New and amended terms are published as immutable revisions. Historical license grants stay unchanged.</p>
        </section>
        <section class="panel policy-side-panel">
          <div class="policy-side-head"><x-icon name="settings" size="19"/><strong>Policy details</strong></div>
          <label class="policy-builder-label" for="policy-category">Category</label>
          <select id="policy-category" name="category">@foreach($types as $key=>$label)<option value="{{ $key }}" @selected(old('category',$policy->category)===$key)>{{ $label }}</option>@endforeach</select>
          <label class="policy-builder-label" for="policy-jurisdiction">Jurisdiction</label>
          <input id="policy-jurisdiction" name="jurisdiction" maxlength="120" value="{{ old('jurisdiction',$policy->jurisdiction) }}" placeholder="Cambodia / international users">
          <label class="policy-builder-label" for="policy-version">Version</label>
          <input id="policy-version" name="version" maxlength="24" value="{{ old('version',$policy->version ?: '1.0.0') }}" pattern="[0-9]{1,4}\.[0-9]{1,4}\.[0-9]{1,4}" required>
          <label class="policy-builder-label" for="policy-pic">PIC / Legal contact</label>
          <input id="policy-pic" name="pic_email" type="email" maxlength="180" value="{{ old('pic_email',$policy->pic_email ?: 'legal@bambodia.com') }}" required>
          <div class="policy-side-metadata">
            <span>Published date <strong>{{ $policy->published_at?->format('d M Y') ?? 'Not published' }}</strong></span>
            <span>Updated date <strong>{{ $policy->updated_at?->format('d M Y H:i') ?? 'Not saved' }}</strong></span>
          </div>
        </section>
        @if($editing)
          <section class="panel policy-side-panel">
            <div class="policy-side-head"><x-icon name="bell" size="19"/><strong>Notify users</strong></div>
            <p class="policy-help">Optional. Select channels when publishing a new reviewed revision. Draft saves never notify users.</p>
            <label class="policy-check-option"><input type="checkbox" name="notify_account" value="1" @checked(old('notify_account'))> In-app account notification</label>
            <label class="policy-check-option"><input type="checkbox" name="notify_email" value="1" @checked(old('notify_email'))> Transactional email (verified provider required)</label>
            <label class="policy-check-option"><input type="checkbox" name="show_banner" value="1" @checked(old('show_banner'))> Public Policy Center banner</label>
            <label class="policy-builder-label" for="policy-notice-title">Notification title</label>
            <input id="policy-notice-title" name="notification_headline" maxlength="180" value="{{ old('notification_headline','Policy updated: '.$policy->title) }}">
            <label class="policy-builder-label" for="policy-notice-message">Notification summary</label>
            <textarea id="policy-notice-message" name="notification_message" rows="3" maxlength="500">{{ old('notification_message','Bambodia has published an updated policy. Please review the latest version.') }}</textarea>
            <label class="policy-builder-label" for="policy-banner-start">Banner start (optional)</label>
            <input type="datetime-local" id="policy-banner-start" name="banner_starts_at" value="{{ old('banner_starts_at') }}">
            <label class="policy-builder-label" for="policy-banner-end">Banner end (optional)</label>
            <input type="datetime-local" id="policy-banner-end" name="banner_ends_at" value="{{ old('banner_ends_at') }}">
            <p class="policy-help">Email and account notifications are queued in the <code>legal</code> worker. Nothing is sent unless a channel is checked.</p>
          </section>
        @endif
      </aside>
    </div>
  </form>
</div>
@endsection
