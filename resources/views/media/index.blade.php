@extends($accountMedia ? 'layouts.workspace' : ($studio ? 'layouts.main' : 'layouts.dashboard'))
@if($accountMedia)
@section('workspace','account')
@endif
@section('title','Media Library')
@section('content')
<div class="{{ $studio ? 'container inner-page' : '' }}">
  <div class="page-intro"><div class="eyebrow">Rights-aware media management</div><h1>Media Library</h1>
    <p>Upload JPEG, PNG, WebP, GIF animation, MP4 or WebM. Media stays private until attached to published content. Admins manage all media; other users manage their own files.</p></div>
  <section class="panel">
    <div class="panel-head"><h2>Images and videos <span class="count">{{ $assets->total() }}</span></h2>
      <button class="btn primary" type="button" data-modal-open="media-new"><x-icon name="upload" size="17"/> Upload media</button>
    </div>
    <div class="table-scroll">
      <table class="data-table"><thead><tr><th>Preview</th><th>Public ID</th><th>Asset / description</th>@unless($studio)<th>Owner</th>@endunless<th>Rights</th><th>Products</th><th>Action</th></tr></thead>
        <tbody>
          @forelse($assets as $asset)
          <tr>
            <td>@if(str_starts_with($asset->mime_type,'video/'))<video class="media-thumb" src="{{ $asset->previewUrl() }}" preload="none" muted playsinline width="56" height="56" aria-label="{{ $asset->alt_text }}"></video>@else<img class="media-thumb" src="{{ $asset->previewUrl() }}" alt="{{ $asset->alt_text }}" loading="lazy" width="56" height="56">@endif</td>
            <td class="mono">{{ $asset->public_id }}</td>
            <td><strong>{{ $asset->original_name }}</strong><div class="text-muted">{{ $asset->alt_text }}</div><div class="text-muted">{{ $asset->width && $asset->height ? $asset->width.' × '.$asset->height.' · ' : '' }}{{ number_format($asset->bytes / 1024) }} KB</div></td>
            @unless($studio)<td>{{ $asset->owner?->name }}</td>@endunless
            <td>{{ ucfirst($asset->rights_basis) }}<div class="text-muted">{{ $asset->rights_holder }}</div></td>
            <td>{{ $asset->cover_listings_count }}</td>
            <td>
              <x-row-action-menu :id="'media-'.$asset->id" :label="$asset->original_name"><button type="button" data-modal-open="media-{{ $asset->id }}"><x-icon name="settings" size="16"/> Configure</button></x-row-action-menu>
              <dialog id="media-{{ $asset->id }}" class="config-dialog wide-dialog">
                <div class="dialog-heading"><h2>{{ $asset->original_name }}</h2><p>ID {{ $asset->public_id }} · {{ $asset->mime_type }}</p></div>
                @if(str_starts_with($asset->mime_type,'video/'))<video class="media-dialog-preview" src="{{ $asset->previewUrl() }}" controls preload="metadata" playsinline></video>@else<img class="media-dialog-preview" src="{{ $asset->previewUrl() }}" alt="{{ $asset->alt_text }}" loading="lazy">@endif
                <p><strong>Rights holder:</strong> {{ $asset->rights_holder }} · {{ ucfirst($asset->rights_basis) }}</p>
                @if($asset->rights_note)<p class="review-text">{{ $asset->rights_note }}</p>@endif
                
                @if($studio || in_array(auth()->user()->role,['super_admin','admin'],true))
                <form method="post" action="{{ route($routePrefix.'.update', $asset) }}">
                  @csrf @method('PATCH')
                  <label for="alt-{{ $asset->id }}">Accessible image description</label>
                  <input id="alt-{{ $asset->id }}" name="alt_text" value="{{ $asset->alt_text }}" maxlength="180" required>
                  <label for="basis-{{ $asset->id }}">Basis of image rights</label>
                  <select id="basis-{{ $asset->id }}" name="rights_basis">
                    <option value="owned" @selected($asset->rights_basis === 'owned')>Owned</option>
                    <option value="licensed" @selected($asset->rights_basis === 'licensed')>Licensed</option>
                  </select>
                  <label for="holder-{{ $asset->id }}">Rights holder</label><input id="holder-{{ $asset->id }}" name="rights_holder" value="{{ $asset->rights_holder }}" maxlength="180" required>
                  <label for="rights-note-{{ $asset->id }}">License documentation / usage permission</label><textarea id="rights-note-{{ $asset->id }}" name="rights_note" maxlength="3000" rows="3">{{ $asset->rights_note }}</textarea>
                  <div class="dialog-actions"><button class="btn primary" type="submit">Save media details</button></div>
                </form>
                @endif
                @if($asset->cover_listings_count === 0 && !$asset->featuredListings()->exists() && !$asset->featuredJobs()->exists())
                  <form method="post" action="{{ route($routePrefix.'.destroy', $asset) }}">
                    @csrf @method('DELETE')
                    <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn danger" type="submit">Delete unused media</button></div>
                  </form>
                @else
                  <p class="hint">This media is linked to a product, listing or job; deletion is disabled.</p>
                  <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Close</button></div>
                @endif
              </dialog>
            </td>
          </tr>
          @empty
          <tr><td class="empty-cell" colspan="{{ $studio ? 6 : 7 }}">No media has been uploaded.</td></tr>
          @endforelse
        </tbody></table>
    </div><x-pagination :paginator="$assets"/>
  </section>
  <dialog id="media-new" class="config-dialog wide-dialog">
    <form method="post" action="{{ route($routePrefix.'.store') }}" enctype="multipart/form-data">
      @csrf
      <h2>Upload marketplace media</h2>
      <p>JPEG, PNG, WebP (4 MB), animated GIF (8 MB), or MP4/WebM (20 MB). Images are limited to 24 megapixels. No SVG or executable files.</p>
      <label for="preview-upload">Media file</label><input id="preview-upload" type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" required>
      <label for="preview-alt">Accessible image description</label><input id="preview-alt" name="alt_text" minlength="4" maxlength="180" required>
      <label for="preview-basis">Basis of rights</label><select id="preview-basis" name="rights_basis"><option value="owned">Owned by uploader</option><option value="licensed">Licensed with permission</option></select>
      <label for="preview-holder">Legal rights holder</label><input id="preview-holder" name="rights_holder" minlength="2" maxlength="180" required>
      <label for="preview-note">License evidence or permission summary (required for licensed assets)</label><textarea id="preview-note" name="rights_note" rows="4" maxlength="3000"></textarea>
      <p class="hint">Uploading media does not publish it. You can use uploads immediately. Only publish media you own or are licensed to use.</p>
      <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Upload privately</button></div>
    </form>
  </dialog>
</div>
@endsection
