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
      <div><h2>Brand assets <span class="count">{{ count(\App\Models\SiteBrandAsset::SLOTS) }}</span></h2>
        <p>JPEG, PNG or WebP up to 3 MB. Uploaded files update the live logo or icon; reset restores the existing default.</p></div>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Placement</th><th>Live image</th><th>Source</th><th>Action</th></tr></thead>
        <tbody>
          @foreach(\App\Models\SiteBrandAsset::SLOTS as $slot => $details)
            @php $asset=$assets->get($slot); @endphp
            <tr>
              <td><strong>{{ $details['label'] }}</strong></td>
              <td><img src="{{ \App\Models\SiteBrandAsset::urlFor($slot) }}" alt="{{ $details['label'] }} preview"
                style="width:128px;max-height:66px;object-fit:contain;border-radius:8px"></td>
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
                    <p>Choose an image. This uploads to the public branding directory; avoid private or copyrighted files you cannot use.</p>
                    <label for="brand-file-{{ $slot }}">Branding image</label>
                    <input id="brand-file-{{ $slot }}" type="file" accept="image/png,image/jpeg,image/webp" name="image" required>
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
@endsection
