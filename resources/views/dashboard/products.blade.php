@extends('layouts.dashboard')
@section('title','Product Manager')
@section('content')
<div class="page-intro"><div class="eyebrow">Marketplace · Catalog Governance</div><h1>Product Manager</h1>
  <p>Review real digital product records. Published listings require a verified license definition and an approved preview from their creator.</p></div>
<section class="panel">
  <div class="panel-head">
    <h2>Digital products <span class="count">{{ $products->total() }}</span></h2>
    <form class="table-search" method="get">
      <x-icon name="search" size="17"/><input name="search" value="{{ $search }}" placeholder="Product title or ID" aria-label="Search product catalog">
      <select name="status" aria-label="Filter status" class="table-select">
        @foreach(['all'=>'All statuses','draft'=>'Draft','pending'=>'Pending','published'=>'Published','rejected'=>'Rejected'] as $value=>$label)
          <option value="{{ $value }}" @selected($status===$value)>{{ $label }}</option>
        @endforeach
      </select><button class="btn subtle" type="submit">Search</button>
    </form>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Product ID</th><th>Title</th><th>Creator</th><th>License</th><th>Preview</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
        @forelse($products as $product)
          <tr>
            <td class="mono">{{ $product->public_id }}</td>
            <td><strong>{{ $product->title }}</strong></td><td>{{ $product->creator?->name }}</td>
            <td>{{ $product->licenseDefinition?->name ?? 'Missing' }}<div class="text-muted">{{ $product->licenseDefinition?->version }}</div></td>
            <td>@if($product->coverAsset)<img class="media-thumb" src="{{ $product->coverAsset->previewUrl() }}" width="52" height="52" alt="{{ $product->coverAsset->alt_text }}">@else<span class="text-muted">Missing</span>@endif</td>
            <td><span class="role-pill">{{ ucfirst($product->status) }}</span></td>
            <td>
              <x-row-action-menu :id="'admin-product-'.$product->id" :label="$product->title">
                <button type="button" data-modal-open="admin-product-{{ $product->id }}"><x-icon name="settings" size="16"/> Configure</button>
              </x-row-action-menu>
              <dialog class="config-dialog wide-dialog" id="admin-product-{{ $product->id }}">
                <div class="dialog-heading"><h2>{{ $product->title }}</h2><p>ID {{ $product->public_id }} · {{ $product->creator?->name }} · {{ ucfirst($product->status) }}</p></div>
                @if($product->coverAsset)<img class="media-dialog-preview" src="{{ $product->coverAsset->previewUrl() }}" alt="{{ $product->coverAsset->alt_text }}">@endif
                <strong>Product description</strong><p class="review-text">{{ $product->description }}</p>
                <p><strong>Listed price:</strong> {{ $product->price === null ? 'Not specified' : '$'.number_format((float)$product->price,2).' USD' }}</p>
                <p><strong>License:</strong> {{ $product->licenseDefinition?->name ?? 'Not selected' }} @if($product->licenseDefinition) ({{ $product->licenseDefinition->status }}) @endif</p>
                @if($product->licenseDefinition && $product->licenseDefinition->status === 'active')
                  <a href="{{ $product->licenseDefinition->publicUrl() }}" class="btn subtle compact" target="_blank" rel="noopener noreferrer">Read license definition</a>
                @endif
                <p><strong>Preview review:</strong> {{ $product->coverAsset?->review_status ?? 'Missing' }}</p>
                @if($product->license_terms)<strong>Additional terms</strong><p class="review-text">{{ $product->license_terms }}</p>@endif
                @if($product->status === 'pending')
                  <form method="post" action="{{ route('dashboard.products.decide',$product) }}">
                    @csrf @method('PATCH')
                    <p class="hint">Publishing is rejected unless an active license, approved owned preview and stored image all exist.</p>
                    <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn subtle" type="submit" name="decision" value="rejected">Reject</button><button class="btn primary" type="submit" name="decision" value="published">Publish product</button></div>
                  </form>
                @else
                  @if($product->status === 'published')<a class="btn subtle" href="{{ $product->publicUrl() }}" target="_blank" rel="noopener noreferrer">View published product</a>@endif
                  <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Close</button></div>
                @endif
              </dialog>
            </td>
          </tr>
        @empty<tr><td class="empty-cell" colspan="7">No digital products match these filters.</td></tr>@endforelse
      </tbody>
    </table>
  </div>
  <x-pagination :paginator="$products"/>
</section>
@endsection
