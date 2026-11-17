@extends($accountMedia ? 'layouts.workspace' : ($studio ? 'layouts.main' : 'layouts.dashboard'))
@if($accountMedia)
@section('workspace','account')
@endif
@section('title','Drag & Drop | Upload')
@section('content')
<link rel="stylesheet" href="{{ asset('css/media-upload.css') }}?v=1">
<script defer src="{{ asset('js/media-upload.js') }}?v=1"></script>
<div class="media-upload-page {{ $studio ? 'container inner-page' : '' }}">
  <div class="media-upload-heading">
    <div><div class="eyebrow">Media Library</div><h1>Drag &amp; Drop | Upload</h1><p>Add your images and videos in one place.</p></div>
    <a class="btn subtle" href="{{ route($routePrefix.'.index') }}">Back to Media Library</a>
  </div>
  <form data-media-upload action="{{ route($routePrefix.'.store') }}" method="post" enctype="multipart/form-data">
    @csrf
    <section class="panel media-upload-card">
      <div class="media-dropzone" data-dropzone>
        <span class="media-upload-icon"><x-icon name="upload" size="32"/></span>
        <h2>Drag &amp; drop your files here</h2><p>Choose multiple images and videos to upload.</p>
        <label class="btn primary media-file-picker">Choose files<input data-files type="file" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" aria-label="Choose images and videos"></label>
        <p class="hint">JPEG, PNG, WebP · 4 MB &nbsp; / &nbsp; GIF · 8 MB &nbsp; / &nbsp; MP4, WebM · 20 MB<br>Images up to 24 megapixels.</p>
      </div>
      <details class="media-advanced">
        <summary><x-icon name="settings" size="17"/> Advanced Settings <span>Optional</span></summary>
        <p class="hint">Apply optional details to the files you upload. You can edit each file later.</p>
        <div class="media-advanced-grid">
          <div><label for="upload-alt">Media description</label><input id="upload-alt" name="alt_text" maxlength="180" placeholder="Uses the filename when left empty"></div>
          <div><label for="upload-basis">Basis of rights</label><select id="upload-basis" name="rights_basis"><option value="">Not specified</option><option value="owned">Owned by uploader</option><option value="licensed">Licensed with permission</option></select></div>
          <div><label for="upload-holder">Legal rights holder</label><input id="upload-holder" name="rights_holder" maxlength="180"></div>
          <div><label for="upload-note">License evidence or permission</label><textarea id="upload-note" name="rights_note" maxlength="3000" rows="2"></textarea></div>
        </div>
      </details>
    </section>
    <section class="panel media-upload-card" aria-label="Upload queue">
      <div class="media-queue-heading"><h2>Upload queue <span data-count class="count">0</span></h2><button type="button" class="btn subtle" data-clear>Clear finished</button></div>
      <p data-empty class="media-queue-empty">Your selected files will appear here.</p>
      <ul data-queue class="media-upload-queue"></ul>
      <div class="media-upload-footer"><p data-summary role="status" aria-live="polite">Select files to get started.</p><button type="submit" class="btn primary" data-start>Upload files</button></div>
    </section>
    <noscript><p>Enable JavaScript to use the multiple-file upload queue.</p></noscript>
  </form>
</div>
@endsection
