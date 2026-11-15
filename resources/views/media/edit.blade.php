@extends($accountMedia ? 'layouts.workspace' : ($studio ? 'layouts.main' : 'layouts.dashboard'))
@if($accountMedia)
@section('workspace','account')
@endif
@section('title','Edit media')
@section('content')
@php($usage = $asset->usageSummary())
<div class="{{ $studio ? 'container inner-page' : '' }}">
  <div class="page-intro"><div class="eyebrow">Media Library</div><h1>Edit media</h1><p>{{ $asset->original_name }}</p>
    <a class="btn subtle" href="{{ route($routePrefix.'.index') }}">Back to Media Library</a>
  </div>
  <section class="panel" style="padding:24px;max-width:900px">
    @if($errors->any())
      <div role="alert" class="hint">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif
    @if(str_starts_with($asset->mime_type, 'video/'))
      <video src="{{ $asset->previewUrl() }}" controls preload="metadata" style="width:100%;max-height:320px"></video>
    @else
      <img src="{{ $asset->previewUrl() }}" alt="{{ $asset->alt_text }}" style="display:block;max-width:100%;max-height:320px;object-fit:contain;border-radius:16px;margin-bottom:20px">
    @endif
    <p><strong>Usage:</strong> {{ $usage ? implode('; ', $usage) : 'Unused — ready to delete if no longer needed.' }}</p>
    <form id="media-edit-form" method="post" action="{{ route($routePrefix.'.update', $asset) }}">
      @csrf @method('PATCH')
      <label for="edit-alt">Media description</label><input id="edit-alt" name="alt_text" maxlength="180" value="{{ old('alt_text', $asset->alt_text) }}">
      <details style="margin:20px 0" @if($errors->hasAny(['rights_basis','rights_holder','rights_note'])) open @endif>
        <summary>Advanced Settings · Optional</summary>
        <label for="edit-basis">Basis of rights</label><select id="edit-basis" name="rights_basis">
          <option value="" @selected(!old('rights_basis', $asset->rights_basis))>Not specified</option>
          <option value="owned" @selected(old('rights_basis', $asset->rights_basis) === 'owned')>Owned by uploader</option>
          <option value="licensed" @selected(old('rights_basis', $asset->rights_basis) === 'licensed')>Licensed with permission</option>
        </select>
        <label for="edit-holder">Rights holder</label><input id="edit-holder" name="rights_holder" maxlength="180" value="{{ old('rights_holder', $asset->rights_holder) }}">
        <label for="edit-note">License evidence or permission</label><textarea id="edit-note" name="rights_note" maxlength="3000" rows="3">{{ old('rights_note', $asset->rights_note) }}</textarea>
      </details>
      <button class="btn primary" type="submit">Save media details</button>
    </form>
    @if($usage === [])
      <form id="media-delete-form" method="post" action="{{ route($routePrefix.'.destroy', $asset) }}" style="margin-top:24px">
        @csrf @method('DELETE')
        <button class="btn danger" type="submit">Delete unused media</button>
      </form>
    @else
      <p class="hint">Remove this media from the content listed above before deleting it. Editing its details remains available.</p>
    @endif
  </section>
</div>
@endsection
