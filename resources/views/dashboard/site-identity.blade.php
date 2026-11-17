@extends('layouts.dashboard')
@section('title','Site Identity')
@section('content')
  <div class="page-intro">
    <div class="eyebrow">Appearance &amp; Content / Branding</div>
    <h1>Site Identity</h1>
    <p>Set your official site details and upload real branding files for light, dark and mobile devices. Existing images remain in use until you replace them.</p>
  </div>
  @include('dashboard.partials.site-settings-table',['heading'=>'Site information','settingAction'=>route('dashboard.site-identity.update')])

  <section class="panel" style="margin-top:20px">
    <div class="panel-head">
      <div>
        <h2>Brand assets <span class="count">{{ count(\App\Models\SiteBrandAsset::SLOTS) }}</span></h2>
        <p>Each placement has its own recommended dimensions and supported file types. Maximum file size: 3 MB. Logo dimensions are a guide; icon dimensions are required.</p>
      </div>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead>
          <tr><th>Placement</th><th>Recommended size</th><th>File type</th><th>Live image</th><th>Source</th><th>Action</th></tr>
        </thead>
        <tbody>
          @foreach(\App\Models\SiteBrandAsset::SLOTS as $slot => $details)
            @php
              $asset = $assets->get($slot);
              $isIcon = ! str_starts_with($slot, 'logo_');
            @endphp
            <tr>
              <td><strong>{{ $details['label'] }}</strong></td>
              <td>
                <strong style="white-space:nowrap">{{ $details['size'] }}</strong>
                <div style="font-size:12px;opacity:.72">{{ $details['exact'] ? 'Exact size' : 'Recommended' }}</div>
              </td>
              <td>
                <span style="white-space:nowrap">{{ $details['file_type'] }}</span>
                @if(str_starts_with($slot, 'favicon_'))
                  <div style="font-size:12px;opacity:.72">Transparent corners</div>
                @endif
              </td>
              <td>
                <span style="display:inline-flex;align-items:center;justify-content:center;max-width:128px;min-width:{{ $isIcon ? '64' : '128' }}px;min-height:64px;{{ $isIcon ? 'background:repeating-conic-gradient(#dde3ec 0% 25%, #f5f7fa 0% 50%) 50% / 12px 12px;border-radius:8px;' : '' }}">
                  <img src="{{ \App\Models\SiteBrandAsset::urlFor($slot) }}" alt="{{ $details['label'] }} preview"
                    style="max-width:{{ $isIcon ? '64' : '128' }}px;max-height:64px;object-fit:contain">
                </span>
              </td>
              <td>{{ $asset ? 'Custom upload' : 'Existing default' }}</td>
              <td>
                <x-row-action-menu :id="'brand-slot-'.$slot" :label="$details['label']">
                  <button type="button" data-modal-open="brand-{{ $slot }}"><x-icon name="image" size="16"/> Upload / Replace</button>
                  @if($asset)
                    <form method="post" action="{{ route('dashboard.site-identity.assets.destroy',$slot) }}">
                      @csrf @method('DELETE')
                      <button type="submit"><x-icon name="trash-2" size="16"/> Reset to default</button>
                    </form>
                  @endif
                </x-row-action-menu>
                <dialog class="config-dialog wide-dialog" id="brand-{{ $slot }}">
                  <form method="post" enctype="multipart/form-data" action="{{ route('dashboard.site-identity.assets.store') }}">
                    @csrf
                    <input type="hidden" name="slot" value="{{ $slot }}">
                    <h2>{{ $details['label'] }}</h2>
                    <p><strong>{{ $details['size'] }}</strong> · {{ $details['file_type'] }} · Max 3 MB</p>
                    <p>{{ $details['hint'] }} {{ $details['exact'] ? 'Incorrect dimensions will be rejected.' : 'Other image dimensions are allowed for logos.' }}</p>
                    <label for="brand-file-{{ $slot }}">Choose branding image</label>
                    <input id="brand-file-{{ $slot }}" type="file" accept="{{ $details['accept'] }}" name="image" required
                      data-brand-file
                      data-width="{{ $details['width'] }}"
                      data-height="{{ $details['height'] }}"
                      data-exact="{{ $details['exact'] ? '1' : '0' }}"
                      data-transparent-corners="{{ str_starts_with($slot, 'favicon_') ? '1' : '0' }}">
                    <p data-brand-feedback role="status" aria-live="polite" style="font-size:13px"></p>
                    @if($errors->has('image') && old('slot') === $slot)
                      <p role="alert" style="color:#ef4444">{{ $errors->first('image') }}</p>
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

  @if($errors->has('image') && old('slot') && array_key_exists(old('slot'), \App\Models\SiteBrandAsset::SLOTS))
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        const dialog = document.getElementById(@json('brand-'.old('slot')));
        if (dialog && !dialog.open) dialog.showModal();
      });
    </script>
  @endif
  <script defer src="{{ asset('js/site-identity-assets.js') }}?v=1"></script>
@endsection
