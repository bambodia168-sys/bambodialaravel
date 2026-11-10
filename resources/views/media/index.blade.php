@extends($accountMedia ? 'layouts.workspace' : ($studio ? 'layouts.main' : 'layouts.dashboard'))
@if($accountMedia)
@section('workspace','account')
@endif
@section('title','Media Library')
@section('content')
<link rel="stylesheet" href="{{ asset('css/media-library-video.css') }}?v=1">
<script defer src="{{ asset('js/media-library-video.js') }}?v=1"></script>
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
            <td>
              @if(str_starts_with($asset->mime_type,'video/'))
                <button class="media-video-thumb-trigger" type="button"
                  data-modal-open="media-{{ $asset->id }}"
                  aria-label="Preview video: {{ $asset->original_name }}"
                  title="Preview {{ $asset->original_name }}">
                  <video class="media-video-thumb-frame" data-media-video-thumb
                    data-media-source="{{ $asset->previewUrl() }}#t=0.15"
                    muted playsinline preload="none" aria-hidden="true"></video>
                  <span class="media-video-thumb-play" aria-hidden="true"><x-icon name="play" size="16"/></span>
                  <span class="media-video-thumb-type" aria-hidden="true">{{ $asset->mime_type === 'video/webm' ? 'WEBM' : 'MP4' }}</span>
                </button>
              @else
                <img class="media-thumb" src="{{ $asset->previewUrl() }}" alt="{{ $asset->alt_text }}" loading="lazy" width="56" height="56">
              @endif
            </td>
            <td class="mono">{{ $asset->public_id }}</td>
            <td><strong>{{ $asset->original_name }}</strong><div class="text-muted">{{ $asset->alt_text }}</div><div class="text-muted">{{ $asset->width && $asset->height ? $asset->width.' × '.$asset->height.' · ' : '' }}{{ number_format($asset->bytes / 1024) }} KB</div></td>
            @unless($studio)<td>{{ $asset->owner?->name }}</td>@endunless
            <td>{{ ucfirst($asset->rights_basis) }}<div class="text-muted">{{ $asset->rights_holder }}</div></td>
            <td>{{ $asset->cover_listings_count }}</td>
            <td>
              <x-row-action-menu :id="'media-'.$asset->id" :label="$asset->original_name"><button type="button" data-modal-open="media-{{ $asset->id }}"><x-icon name="settings" size="16"/> Configure</button></x-row-action-menu>
              <dialog id="media-{{ $asset->id }}" class="config-dialog wide-dialog media-editor-dialog">
                <div class="dialog-heading"><h2>{{ $asset->original_name }}</h2><p>ID {{ $asset->public_id }} · {{ $asset->mime_type }}</p></div>
                @if(str_starts_with($asset->mime_type,'video/'))
                  <div class="media-player-shell" data-media-player tabindex="0" aria-label="Private video preview">
                    <div class="media-player-surface">
                      <video data-player-video src="{{ $asset->previewUrl() }}"
                        controls preload="none" playsinline aria-label="{{ $asset->alt_text }}"></video>
                      <button class="media-player-center" type="button" data-player-overlay
                        aria-label="Play video"><x-icon name="play" size="28"/></button>
                    </div>
                    <div class="media-player-controls" aria-label="Video playback controls">
                      <button type="button" class="media-player-tool" data-player-toggle aria-label="Play video">
                        <span data-player-play><x-icon name="play" size="18"/></span>
                        <span data-player-pause><x-icon name="pause" size="18"/></span>
                      </button>
                      <input class="media-player-seek" data-player-seek type="range"
                        min="0" max="1000" step="1" value="0" aria-label="Seek video">
                      <span class="media-player-clock" data-player-clock>0:00 / 0:00</span>
                      <button class="media-player-tool" type="button" data-player-mute aria-label="Mute video">
                        <span data-player-sound><x-icon name="volume-2" size="18"/></span>
                        <span data-player-muted><x-icon name="volume-x" size="18"/></span>
                      </button>
                      <select class="media-player-speed" data-player-speed aria-label="Playback speed">
                        <option value="0.75">0.75×</option>
                        <option value="1" selected>1×</option>
                        <option value="1.25">1.25×</option>
                        <option value="1.5">1.5×</option>
                        <option value="2">2×</option>
                      </select>
                      <button class="media-player-tool" type="button" data-player-pip aria-label="Picture in picture">
                        <x-icon name="picture-in-picture" size="18"/>
                      </button>
                      <button class="media-player-tool" type="button" data-player-fullscreen aria-label="Fullscreen video">
                        <x-icon name="maximize" size="18"/>
                      </button>
                    </div>
                  </div>
                  <p class="media-player-footnote" data-player-status>
                    Private preview. Only authorized users can watch unpublished media.
                  </p>
                @else
                  <img class="media-dialog-preview" src="{{ $asset->previewUrl() }}"
                    alt="{{ $asset->alt_text }}" loading="lazy">
                @endif
                <p><strong>Rights holder:</strong> {{ $asset->rights_holder }} · {{ ucfirst($asset->rights_basis) }}</p>
                @if($asset->rights_note)<p class="review-text">{{ $asset->rights_note }}</p>@endif
                
                @if($studio || in_array(auth()->user()->role,['super_admin','admin'],true))
                <form method="post" action="{{ route($routePrefix.'.update', $asset) }}">
                  @csrf @method('PATCH')
                  <label for="alt-{{ $asset->id }}">Accessible {{ str_starts_with($asset->mime_type, 'video/') ? 'video' : 'image' }} description</label>
                  <input id="alt-{{ $asset->id }}" name="alt_text" value="{{ $asset->alt_text }}" maxlength="180" required>
                  <label for="basis-{{ $asset->id }}">Basis of media rights</label>
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
      <label for="preview-alt">Accessible media description</label><input id="preview-alt" name="alt_text" minlength="4" maxlength="180" required>
      <label for="preview-basis">Basis of rights</label><select id="preview-basis" name="rights_basis"><option value="owned">Owned by uploader</option><option value="licensed">Licensed with permission</option></select>
      <label for="preview-holder">Legal rights holder</label><input id="preview-holder" name="rights_holder" minlength="2" maxlength="180" required>
      <label for="preview-note">License evidence or permission summary (required for licensed assets)</label><textarea id="preview-note" name="rights_note" rows="4" maxlength="3000"></textarea>
      <p class="hint">Uploading media does not publish it. You can use uploads immediately. Only publish media you own or are licensed to use.</p>
      <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Upload privately</button></div>
    </form>
  </dialog>
</div>
@endsection
