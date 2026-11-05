@extends('layouts.main')
@section('title','Creator Product Manager')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Creator Studio · Digital Products</div><h1>Product Manager</h1>
    <p>Create and manage digital product listings. Only products with an approved preview and an active license can be submitted for publication.</p></div>
  @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
  <section class="panel">
    <div class="panel-head">
      <h2>My products <span class="count">{{ $products->total() }}</span></h2>
      <div class="toolbar-actions">
        <a class="btn subtle" href="{{ route('studio.media.index') }}">Media Library</a>
        @if(\App\Models\FeatureFlag::isEnabled('creator_submissions'))<button type="button" class="btn primary" data-modal-open="product-new">Create product</button>@endif
      </div>
    </div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Product ID</th><th>Title</th><th>License</th><th>Preview</th><th>Status</th><th>Submitted</th><th>Action</th></tr></thead>
      <tbody>
        @forelse($products as $product)
          <tr>
            <td class="mono">{{ $product->public_id }}</td><td><strong>{{ $product->title }}</strong></td>
            <td>{{ $product->licenseDefinition?->name ?? 'Not selected' }}</td>
            <td>@if($product->coverAsset)<img class="media-thumb" src="{{ $product->coverAsset->previewUrl() }}" alt="{{ $product->coverAsset->alt_text }}" width="52" height="52">@else<span class="text-muted">Not selected</span>@endif</td>
            <td><span class="role-pill">{{ ucfirst($product->status) }}</span></td><td>{{ $product->created_at->format('d M Y') }}</td>
            <td>
              @if(in_array($product->status, ['draft','rejected'], true))
                <button class="btn subtle compact" type="button" data-modal-open="product-{{ $product->id }}"><x-icon name="settings" size="15"/> Configure</button>
                <dialog id="product-{{ $product->id }}" class="config-dialog wide-dialog">
                  <form method="post" action="{{ route('studio.products.update',$product) }}">
                    @csrf @method('PATCH')
                    <h2>Configure product</h2><p>Public ID {{ $product->public_id }} · {{ ucfirst($product->status) }}</p>
                    <label for="product-title-{{ $product->id }}">Product title</label><input id="product-title-{{ $product->id }}" name="title" value="{{ $product->title }}" required maxlength="180">
                    <label for="product-description-{{ $product->id }}">Product details</label><textarea name="description" id="product-description-{{ $product->id }}" rows="7" minlength="40" required>{{ $product->description }}</textarea>
                    <label for="product-price-{{ $product->id }}">Displayed price (USD, checkout disabled)</label><input name="price" id="product-price-{{ $product->id }}" type="number" min="0" max="9999999999.99" step="0.01" value="{{ $product->price }}">
                    <label for="product-license-{{ $product->id }}">Active product license</label><select name="license_definition_id" id="product-license-{{ $product->id }}"><option value="">Not selected</option>
                      @foreach($licenses as $license)<option value="{{ $license->id }}" @selected((int)$product->license_definition_id === $license->id)>{{ $license->name }} · {{ $license->version }}</option>@endforeach
                    </select>
                    <label for="product-cover-{{ $product->id }}">Approved cover image</label><select name="cover_media_id" id="product-cover-{{ $product->id }}"><option value="">Not selected</option>
                      @foreach($assets as $asset)<option value="{{ $asset->id }}" @selected((int)$product->cover_media_id === $asset->id)>{{ $asset->alt_text }} · {{ $asset->public_id }}</option>@endforeach
                    </select>
                    <label for="product-notes-{{ $product->id }}">Additional product terms (optional; cannot expand license rights)</label><textarea name="license_terms" id="product-notes-{{ $product->id }}" rows="4">{{ $product->license_terms }}</textarea>
                    <label for="product-status-{{ $product->id }}">Next action</label><select name="status" id="product-status-{{ $product->id }}"><option value="draft">Save draft</option><option value="pending">Submit for review</option></select>
                    <p class="hint">Submitting requires an active license and approved image. Published products cannot be silently changed.</p>
                    <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save product</button></div>
                  </form>
                </dialog>
              @elseif($product->status === 'published')
                <div class="table-actions"><a class="btn subtle compact" href="{{ $product->publicUrl() }}">View</a><a class="btn primary compact" href="{{ route('studio.vault.index', $product) }}">Releases</a></div>
              @else<span class="text-muted">Under review</span>@endif
            </td>
          </tr>
        @empty<tr><td class="empty-cell" colspan="7">No digital products have been created.</td></tr>@endforelse
      </tbody>
    </table></div>
    <x-pagination :paginator="$products"/>
  </section>
  <dialog id="product-new" class="config-dialog wide-dialog">
    <form method="post" action="{{ route('studio.products.store') }}">
      @csrf
      <h2>Create digital product</h2><p>Save a draft or submit complete product details for review.</p>
      <label for="product-new-title">Product title</label><input id="product-new-title" name="title" minlength="4" maxlength="180" required>
      <label for="product-new-description">Product description</label><textarea id="product-new-description" name="description" minlength="40" maxlength="10000" rows="7" required></textarea>
      <label for="product-new-price">Displayed price (USD, checkout disabled)</label><input id="product-new-price" name="price" type="number" min="0" max="9999999999.99" step="0.01">
      <label for="product-new-license">Product license</label><select id="product-new-license" name="license_definition_id"><option value="">Not selected</option>
        @foreach($licenses as $license)<option value="{{ $license->id }}">{{ $license->name }} · {{ $license->version }}</option>@endforeach
      </select>
      <label for="product-new-cover">Approved cover image</label><select id="product-new-cover" name="cover_media_id"><option value="">Not selected</option>
        @foreach($assets as $asset)<option value="{{ $asset->id }}">{{ $asset->alt_text }} · {{ $asset->public_id }}</option>@endforeach
      </select>
      <label for="product-new-notes">Additional terms (optional)</label><textarea id="product-new-notes" name="license_terms" rows="4"></textarea>
      <label for="product-new-status">Next action</label><select id="product-new-status" name="status"><option value="draft">Save draft</option><option value="pending">Submit for review</option></select>
      <p class="hint">Upload and have your image reviewed in Media Library before submitting. Purchases and file downloads are not enabled.</p>
      <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save product</button></div>
    </form>
  </dialog>
</div>
@endsection
