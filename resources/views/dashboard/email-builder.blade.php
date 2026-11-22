@extends('layouts.workspace')
@section('workspace', 'admin')
@section('title', $template['label'].' · Email Builder')
@push('head')
<link rel="stylesheet" href="{{ asset('css/email-configuration.css') }}?v=2">
<script src="{{ asset('js/email-builder.js') }}?v=1" defer></script>
@endpush
@section('content')
<div class="container inner-page email-configuration email-builder" data-email-builder>
  <div class="email-builder-top">
    <div>
      <a class="email-builder-back" href="{{ route('dashboard.email-templates.index') }}">← Email Templates</a>
      <h1>{{ $template['label'] }}</h1>
      @if($event === \App\Support\EmailTemplates::VERIFY)
        <p>Sent with a signed link when a member requests email verification.</p>
      @elseif($template['category'] === 'Workflow required')
        <p>Draft only. This template needs a workflow before it can send automatically.</p>
      @elseif($template['category'] === 'Reminders')
        <p>{{ $template['enabled'] ? 'On' : 'Off' }} · Sends to verified members who opted in to marketplace news.</p>
      @else
        <p>{{ $template['category'] }} · {{ $template['enabled'] ? 'On' : 'Off' }}</p>
      @endif
    </div>
    <div class="email-actions">
      <a class="btn subtle compact" href="{{ route('dashboard.email-templates.preview', $event) }}" target="_blank" rel="noopener">Preview saved</a>
      <button type="button" class="btn subtle compact" data-email-preview hidden>Preview draft</button>
      <button type="submit" form="email-copy" class="btn primary compact">Save template</button>
    </div>
  </div>

  <div class="email-builder-columns">
    <form id="email-copy" class="panel email-edit-form" method="post" action="{{ route('dashboard.email-templates.update', $event) }}">
      @csrf @method('PUT')
      @if($errors->any())
        <ul class="email-builder-errors" role="alert">
          @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
      @endif
      <label>Subject <input name="subject" maxlength="180" required value="{{ $fields['subject'] }}"></label>
      <label>Heading <input name="heading" maxlength="180" required value="{{ $fields['heading'] }}"></label>
      <div class="email-message">
        <label id="email-message-label" for="email-body">Message</label>
        <div class="email-editor-tools" data-email-tools hidden role="toolbar" aria-label="Message formatting">
          <select data-email-format aria-label="Paragraph style">
            <option value="p">Paragraph</option>
            <option value="h2">Heading</option>
            <option value="h3">Subheading</option>
            <option value="blockquote">Quote</option>
          </select>
          @foreach([
            ['bold', 'bold', 'Bold'], ['italic', 'italic', 'Italic'], ['underline', 'underline', 'Underline'],
            ['insertUnorderedList', 'list', 'Bulleted list'], ['insertOrderedList', 'list-ordered', 'Numbered list'],
            ['justifyLeft', 'align-left', 'Align left'], ['justifyCenter', 'align-center', 'Align center'],
            ['justifyRight', 'align-right', 'Align right'], ['undo', 'undo', 'Undo'], ['redo', 'redo', 'Redo'],
          ] as [$command, $icon, $label])
            <button type="button" class="icon-button" data-email-command="{{ $command }}" aria-label="{{ $label }}" title="{{ $label }}">
              <x-icon :name="$icon" size="17"/>
            </button>
          @endforeach
        </div>
        <div id="email-surface" class="email-message-surface" data-email-surface contenteditable="true" role="textbox"
          aria-labelledby="email-message-label" aria-multiline="true" hidden>{!! $bodyHtml !!}</div>
        <textarea id="email-body" data-email-fallback name="body" maxlength="6000" rows="10" required>{{ $fields['body'] }}</textarea>
        <input type="hidden" name="body_html" data-email-html>
        <p class="email-field-help" data-email-counter hidden></p>
      </div>
      <label>Button label
        <input name="button" maxlength="90" value="{{ $fields['button'] }}"
          @required($event === \App\Support\EmailTemplates::VERIFY)>
      </label>
      <p class="email-field-help">The button opens the page associated with this email.</p>
    </form>

    <aside class="email-builder-sidebar">
      <section class="panel email-builder-card" data-email-palette hidden aria-label="Email builder elements">
        <h2>Elements</h2>
        <div class="email-element-grid">
          @foreach([
            ['p', 'edit', 'Text'], ['h2', 'file', 'Heading'], ['ul', 'list', 'Bulleted list'],
            ['ol', 'list-ordered', 'Numbered list'], ['blockquote', 'message', 'Quote'], ['hr', 'minus', 'Divider'],
          ] as [$tag, $icon, $label])
            <button type="button" class="email-element" data-email-element="{{ $tag }}">
              <x-icon :name="$icon" size="17"/> {{ $label }}
            </button>
          @endforeach
        </div>
        <div class="email-link-box">
          <label for="email-builder-link">Link URL</label>
          <div class="email-actions">
            <input id="email-builder-link" type="url" maxlength="1000" placeholder="https://example.com">
            <button type="button" class="icon-button" data-email-link aria-label="Insert link" title="Insert link"><x-icon name="link" size="18"/></button>
          </div>
          <p class="email-field-help" data-email-link-message role="status">Select text to make it a link.</p>
        </div>
      </section>
      <section class="panel email-builder-card" data-email-variables hidden>
        <h2>Personalization</h2>
        <div class="email-actions">
          @foreach(\App\Support\EmailTemplates::variables($event) as $key)
            @php($token = str_repeat('{', 2).$key.str_repeat('}', 2))
            <button type="button" class="email-token" data-email-token="{{ $token }}"
              aria-label="Insert {{ str_replace('_', ' ', $key) }}">{{ $token }}</button>
          @endforeach
        </div>
      </section>
      <section class="panel email-builder-card">
        <h2>Template actions</h2>
        <form method="post" action="{{ route('dashboard.email-templates.test', $event) }}">
          @csrf
          <button class="btn subtle compact" @disabled(!$deliveryReady)>Send test to me</button>
        </form>
        @if($customized)
          <details class="email-delete">
            <summary>Restore default copy</summary>
            <p>Removes the saved custom message and formatting.</p>
            <form method="post" action="{{ route('dashboard.email-templates.destroy', $event) }}">
              @csrf @method('DELETE')
              <button class="btn danger compact">Restore default copy</button>
            </form>
          </details>
        @endif
      </section>
    </aside>
  </div>
  <form data-email-draft method="post" action="{{ route('dashboard.email-templates.preview-draft', $event) }}" target="_blank" rel="noopener" hidden>@csrf</form>
</div>
@endsection
