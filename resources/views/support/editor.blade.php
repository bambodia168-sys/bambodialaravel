@php
  $editorId = $editorId ?? 'ticket-message';
  $minimum = (int) ($minimum ?? 2);
@endphp
<div class="ticket-editor" data-ticket-editor data-minimum="{{ $minimum }}">
  <div class="ticket-editor-toolbar" role="toolbar" aria-label="Message formatting tools">
    <select data-ticket-block aria-label="Paragraph and heading format">
      <option value="p">Paragraph</option><option value="h2">Heading 2</option>
      <option value="h3">Heading 3</option><option value="h4">Heading 4</option>
      <option value="blockquote">Quotation</option>
    </select>
    @foreach([
      ['bold', 'bold', 'Bold'], ['italic', 'italic', 'Italic'],
      ['underline', 'underline', 'Underline'], ['strikeThrough', 'strikethrough', 'Strikethrough'],
      ['insertUnorderedList', 'list', 'Bulleted list'],
      ['insertOrderedList', 'list-ordered', 'Numbered list'],
      ['justifyLeft', 'align-left', 'Align left'], ['justifyCenter', 'align-center', 'Center'],
      ['createLink', 'link', 'Insert link'],
      ['unlink', 'unlink', 'Remove link'],
      ['table', 'table', 'Insert table'],
      ['divider', 'minus', 'Horizontal divider'],
      ['removeFormat', 'eraser', 'Remove formatting'],
      ['undo', 'undo', 'Undo'], ['redo', 'redo', 'Redo'],
    ] as $action)
      <button class="ticket-editor-tool" type="button" data-ticket-command="{{ $action[0] }}" title="{{ $action[2] }}" aria-label="{{ $action[2] }}"><x-icon :name="$action[1]" size="17"/></button>
    @endforeach
    <select data-ticket-language aria-label="Programming language for code block">
      @foreach(['plaintext'=>'Code','php'=>'PHP','html'=>'HTML','css'=>'CSS','javascript'=>'JavaScript','typescript'=>'TypeScript','python'=>'Python','java'=>'Java','sql'=>'SQL','bash'=>'Bash','json'=>'JSON','xml'=>'XML','go'=>'Go','rust'=>'Rust','c'=>'C','cpp'=>'C++','csharp'=>'C#','ruby'=>'Ruby','swift'=>'Swift','kotlin'=>'Kotlin'] as $language=>$label)
        <option value="{{ $language }}">{{ $label }}</option>
      @endforeach
    </select>
    <button class="ticket-editor-tool" type="button" data-ticket-code title="Insert code block" aria-label="Insert programming-language code"><x-icon name="code" size="17"/></button>
    <select data-ticket-emoji aria-label="Insert emoji"><option value="">Emoji</option>
      @foreach(['😀'=>'😀 Smile','🙏'=>'🙏 Thanks','✅'=>'✅ Done','❓'=>'❓ Question','⚠️'=>'⚠️ Warning','📎'=>'📎 Attachment','💡'=>'💡 Idea','❤️'=>'❤️ Heart','🚀'=>'🚀 Launch','🎉'=>'🎉 Celebrate'] as $emoji=>$label)
        <option value="{{ $emoji }}">{{ $label }}</option>
      @endforeach
    </select>
    <button class="ticket-editor-tool" type="button" data-ticket-media title="Insert image, audio, video or YouTube" aria-label="Insert media"><x-icon name="image" size="17"/></button>
    <button class="ticket-editor-source-toggle" type="button" data-ticket-toggle-source aria-pressed="false"><x-icon name="code" size="17"/> HTML</button>
  </div>
  <div id="{{ $editorId }}" data-ticket-surface class="ticket-editor-surface policy-formatted-content"
    contenteditable="true" role="textbox" aria-label="Formatted support message" aria-multiline="true" spellcheck="true" hidden>{!! \App\Support\SupportMessageContent::display((string) old('message', '')) !!}</div>
  <textarea name="message" data-ticket-message id="{{ $editorId }}-fallback"
    rows="10" maxlength="40000" minlength="{{ $minimum }}" required
    aria-label="Support message">{{ old('message') }}</textarea>
  <textarea data-ticket-source-panel class="ticket-editor-source" aria-label="Message HTML source" spellcheck="false" hidden></textarea>
  <div class="ticket-editor-footer">
    <span>Text · Headings · Lists · Links · Tables · Emoji · Images · Audio · Video · YouTube · Code · HTML</span>
    <span data-ticket-count aria-live="polite">0 characters</span>
  </div>
  <p class="text-muted ticket-editor-help">Use HTTPS media links. Never include passwords, API keys or payment-card information. HTML and media are sanitized before saving.</p>
  <dialog class="ticket-media-dialog" data-ticket-media-dialog aria-label="Insert media">
    <form method="dialog" data-ticket-media-form>
      <h3>Insert into your message</h3>
      <label for="{{ $editorId }}-type">Content type</label>
      <select id="{{ $editorId }}-type" data-ticket-media-type>
        <option value="link">Link</option>
        <option value="image">Image</option>
        <option value="audio">Audio</option>
        <option value="video">Video</option>
        <option value="youtube">YouTube video</option>
      </select>
      <label for="{{ $editorId }}-url">HTTPS URL</label>
      <input id="{{ $editorId }}-url" type="url" data-ticket-media-url placeholder="https://" maxlength="2048">
      <label for="{{ $editorId }}-label">Title / alternative text</label>
      <input id="{{ $editorId }}-label" data-ticket-media-label maxlength="160" placeholder="Describe the content">
      <p class="ticket-media-error" data-ticket-media-error role="alert" hidden></p>
      <div class="dialog-actions">
        <button class="btn subtle" type="button" data-ticket-media-cancel>Cancel</button>
        <button class="btn primary" type="submit" data-ticket-media-insert>Insert</button>
      </div>
    </form>
  </dialog>
</div>
