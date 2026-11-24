@php($attachmentId = $attachmentId ?? 'ticket-attachments')
<div class="ticket-attachment-fields">
  <label for="{{ $attachmentId }}"><x-icon name="paperclip" size="16"/> Reference attachments (optional)</label>
  <input type="file" name="attachments[]" id="{{ $attachmentId }}" multiple
    accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,image/jpeg,image/png,image/webp,image/gif,application/pdf,text/plain">
  <p class="text-muted">Up to 4 JPG, PNG, WebP, GIF, PDF or TXT files per message (10 MB each, 20 MB total). Private download only. Files must pass security scanning; no SVG, HTML or executables.</p>
  @error('attachments')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror
  @error('attachments.*')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror
</div>
