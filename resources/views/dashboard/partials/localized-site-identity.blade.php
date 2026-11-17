@php
  use App\Models\SiteLanguage;
  use App\Support\SiteIdentity;

  $languages = SiteLanguage::CATALOG;
  $sharingImageGuidance = 'PNG, JPEG, or WebP · 1,200 × 630 px recommended · up to 2 MB';
  $activeLanguages = SiteLanguage::activeCodes();
  $sharingSlots = SiteIdentity::sharingSlots();
  $sharedImage = SiteIdentity::sharedImage($assets->get(SiteIdentity::SHARED_IMAGE));
@endphp
<section class="panel" style="margin-top:20px" aria-labelledby="localized-identity-heading">
  <div class="panel-head">
    <div>
      <h2 id="localized-identity-heading">Localized site identity</h2>
      <p>The tagline appears in the web app manifest. Meta title and description provide defaults for public pages.</p>
    </div>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Language</th><th>Tagline</th><th>Meta title</th><th>Meta description</th><th>Action</th></tr></thead>
      <tbody>
      @foreach($languages as $locale => $label)
        @php $identity = SiteIdentity::metadata($locale); @endphp
        <tr>
          <th scope="row">{{ $label }} <small>{{ strtoupper($locale) }}</small>
            @unless(in_array($locale, $activeLanguages, true))<br><small>Disabled in Language Manager</small>@endunless
          </th>
          <td lang="{{ $locale }}">{{ $identity['tagline'] }}</td>
          <td lang="{{ $locale }}">{{ $identity['title'] }}</td>
          <td lang="{{ $locale }}">{{ $identity['description'] }}</td>
          <td>
            <button class="btn subtle compact" type="button" data-modal-open="identity-locale-{{ $locale }}" aria-label="Edit {{ $label }} site identity">Edit</button>
            <dialog class="config-dialog wide-dialog" id="identity-locale-{{ $locale }}" aria-labelledby="identity-heading-{{ $locale }}">
              <form method="post" action="{{ route('dashboard.site-identity.update') }}">
                @csrf @method('PATCH')
                <input type="hidden" name="locale" value="{{ $locale }}">
                <h2 id="identity-heading-{{ $locale }}">{{ $label }} site identity</h2>
                <p>Leave a field blank to restore its default.</p>
                @foreach(['tagline' => 'Tagline', 'title' => 'Meta title', 'description' => 'Meta description'] as $field => $heading)
                  @php
                    $max = \App\Models\SiteSetting::DEFINITIONS[SiteIdentity::FIELDS[$field]]['max'];
                    $value = old('locale') === $locale ? old($field) : $identity[$field];
                  @endphp
                  <label for="identity-{{ $locale }}-{{ $field }}">{{ $heading }}</label>
                  @if($field === 'description')
                    <textarea id="identity-{{ $locale }}-{{ $field }}" name="{{ $field }}" rows="3" maxlength="{{ $max }}">{{ $value }}</textarea>
                  @else
                    <input id="identity-{{ $locale }}-{{ $field }}" name="{{ $field }}" maxlength="{{ $max }}" value="{{ $value }}">
                  @endif
                  @if(old('locale') === $locale)
                    @error($field)<p role="alert">{{ $message }}</p>@enderror
                  @endif
                @endforeach
                <div class="dialog-actions">
                  <button type="button" class="btn subtle" data-modal-close>Cancel</button>
                  <button class="btn primary" type="submit">Save</button>
                </div>
              </form>
            </dialog>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</section>

<section class="panel" style="margin-top:20px" aria-labelledby="site-sharing-heading">
  <div class="panel-head">
    <div>
      <h2 id="site-sharing-heading">Social Sharing</h2>
      <p>Public pages use their featured image first. The previews below show the fallback for each language.</p>
      <p>{{ $sharingImageGuidance }}</p>
    </div>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Placement</th><th>Preview</th><th>Source</th><th>Action</th></tr></thead>
      <tbody>
      @foreach($sharingSlots as $slot => $label)
        @php
          $ownImage = SiteIdentity::imageUrl($assets->get($slot));
          $shared = $slot === SiteIdentity::SHARED_IMAGE;
        @endphp
        <tr>
          <th scope="row">{{ $label }}</th>
          <td><img src="{{ $ownImage ?: $sharedImage }}" width="160" height="84" alt="{{ $label }} preview" loading="lazy"
              style="object-fit:contain;border-radius:8px"></td>
          <td>{{ $ownImage ? 'Custom upload' : ($shared ? 'Default image' : 'Shared fallback') }}</td>
          <td>
            <div class="dialog-actions" style="margin:0;justify-content:flex-start">
              <button class="btn subtle compact" type="button" data-modal-open="sharing-{{ $slot }}" aria-label="Upload {{ $label }}">Upload</button>
              @if($assets->has($slot))
                <form method="post" action="{{ route('dashboard.site-identity.assets.destroy', $slot) }}">
                  @csrf @method('DELETE')
                  <button class="btn subtle compact" type="submit">Reset</button>
                </form>
              @endif
            </div>
            <dialog class="config-dialog wide-dialog" id="sharing-{{ $slot }}" aria-labelledby="sharing-heading-{{ $slot }}">
              <form method="post" enctype="multipart/form-data" action="{{ route('dashboard.site-identity.assets.store') }}">
                @csrf
                <input type="hidden" name="slot" value="{{ $slot }}">
                <h2 id="sharing-heading-{{ $slot }}">{{ $label }}</h2>
                <p>{{ $sharingImageGuidance }}</p>
                <label for="sharing-file-{{ $slot }}">Sharing image</label>
                <input id="sharing-file-{{ $slot }}" type="file" accept="image/jpeg,image/png,image/webp" name="image" required>
                @if(old('slot') === $slot)
                  @error('image')<p role="alert">{{ $message }}</p>@enderror
                @endif
                <div class="dialog-actions">
                  <button type="button" class="btn subtle" data-modal-close>Cancel</button>
                  <button class="btn primary" type="submit">Upload</button>
                </div>
              </form>
            </dialog>
          </td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
</section>
@if($errors->any() && old('locale') && isset($languages[old('locale')]))
  <script>document.getElementById(@json('identity-locale-'.old('locale')))?.showModal();</script>
@elseif($errors->has('image') && old('slot') && isset($sharingSlots[old('slot')]))
  <script>document.getElementById(@json('sharing-'.old('slot')))?.showModal();</script>
@endif
