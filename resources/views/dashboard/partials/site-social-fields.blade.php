@php $editLink=$socialLink??null; @endphp
<div class="settings-form" style="padding:0;border:0">
  <label>Platform
    <select name="platform" required>
      @foreach($platforms as $code=>$name)
        <option value="{{ $code }}" @selected(($editLink?->platform??'website')===$code)>{{ $name }}</option>
      @endforeach
    </select>
  </label>
  <label>Accessible title <input name="label" required minlength="2" maxlength="100" placeholder="Our official social page" value="{{ $editLink?->label??'' }}"></label>
  <label>Public HTTPS link <input name="url" type="url" required maxlength="400" placeholder="https://..." value="{{ $editLink?->url??'' }}"></label>
  <label>Display order <input name="sort_order" type="number" min="0" max="1000" required value="{{ $editLink?->sort_order??10 }}"></label>
  <label>Visibility
    <select name="is_active"><option value="1" @selected($editLink?->is_active??true)>Visible</option><option value="0" @selected($editLink && !$editLink->is_active)>Hidden</option></select>
  </label>
</div>
