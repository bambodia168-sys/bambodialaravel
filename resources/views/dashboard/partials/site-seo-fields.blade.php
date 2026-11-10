@php $entry=$seoEntry??null; @endphp
<div class="settings-form" style="padding:0;border:0">
  <label>Public page path (without language prefix)
    <input name="path" required maxlength="180" placeholder="/ or /creators/34302348"
      value="{{ $entry?->path??'' }}">
  </label>
  <label>Language
    <select name="locale" required>
      @foreach(\App\Http\Middleware\SetLocale::SUPPORTED as $code)
        <option value="{{ $code }}" @selected(($entry?->locale??'en')===$code)>{{ strtoupper($code) }}</option>
      @endforeach
    </select>
  </label>
  <label>SEO title <input name="title" required minlength="4" maxlength="180" value="{{ $entry?->title??'' }}"></label>
  <label>Description <textarea name="description" required minlength="12" maxlength="320" rows="3">{{ $entry?->description??'' }}</textarea></label>
  <label>Keywords (optional) <input name="keywords" maxlength="400" value="{{ $entry?->keywords??'' }}"></label>
  <label>Canonical URL (optional, this site HTTPS only) <input type="url" name="canonical_url" maxlength="400" placeholder="https://example.com/page" value="{{ $entry?->canonical_url??'' }}"></label>
  <label>Social preview image (optional, HTTPS) <input type="url" name="og_image_url" maxlength="400" value="{{ $entry?->og_image_url??'' }}"></label>
  <label>Indexing
    <select name="is_indexable"><option value="1" @selected($entry?->is_indexable??true)>Index / Follow</option><option value="0" @selected($entry && !$entry->is_indexable)>Noindex / Nofollow</option></select>
  </label>
  <label>Status
    <select name="is_active"><option value="1" @selected($entry?->is_active??true)>Active</option><option value="0" @selected($entry && !$entry->is_active)>Inactive</option></select>
  </label>
</div>
