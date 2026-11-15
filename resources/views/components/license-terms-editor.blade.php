@props(['id', 'field', 'label', 'value' => '', 'html' => null])
@php($html = is_string($html) ? $html : null)
<div class="license-terms-field" data-license-terms>
  <label for="{{ $id }}" id="{{ $id }}-label">{{ $label }}</label>
  <textarea id="{{ $id }}" name="{{ $field }}" minlength="30" maxlength="50000" rows="6" required data-license-terms-plain>{{ $value }}</textarea>
  <input type="hidden" name="{{ $field }}_html" disabled data-license-terms-html>
  <div class="license-terms-editor" hidden data-license-editor>
    <div class="license-terms-tools" role="group" aria-label="{{ $label }} formatting">
      @foreach([
        ['formatBlock', 'p', 'align-left', 'Paragraph'],
        ['formatBlock', 'h3', 'bold', 'Heading'],
        ['bold', '', 'bold', 'Bold'],
        ['italic', '', 'italic', 'Italic'],
        ['insertUnorderedList', '', 'list', 'Bulleted list'],
        ['insertOrderedList', '', 'list-ordered', 'Numbered list'],
        ['undo', '', 'undo', 'Undo'],
        ['redo', '', 'redo', 'Redo'],
      ] as [$command, $argument, $icon, $name])
        <button type="button" data-license-command="{{ $command }}" data-license-argument="{{ $argument }}"
          aria-label="{{ $name }}" title="{{ $name }}"><x-icon :name="$icon" size="16"/></button>
      @endforeach
    </div>
    <div id="{{ $id }}-editor" aria-describedby="{{ $id }}-error" class="license-terms-copy license-terms-input" data-license-terms-surface
      role="textbox" aria-labelledby="{{ $id }}-label" aria-multiline="true" aria-required="true"
      contenteditable="true" spellcheck="true">{!! \App\Support\LicenseTerms::render($value, $html) !!}</div>
  </div>
  <p id="{{ $id }}-error" class="builder-field-error" data-license-terms-error hidden role="alert">Enter between 30 and 50,000 characters of license terms.</p>
</div>
