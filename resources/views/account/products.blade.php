@extends('layouts.workspace')
@section('workspace','creator')
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
        @if(\App\Models\FeatureFlag::isEnabled('creator_submissions'))<a class="btn primary" href="{{ route('builder', ['type' => 'product']) }}">Create product</a>@endif
      </div>
    </div>
    <div class="workspace-queue-toolbar">
      <nav class="workspace-queue-tabs" aria-label="Your products by status">
        @foreach(['all'=>'All','draft'=>'Draft','pending'=>'Pending','published'=>'Published','rejected'=>'Rejected'] as $value=>$label)
          <a href="{{ route('studio.products.index',array_merge(request()->except('status','page'),$value==='all'?[]:['status'=>$value])) }}"
             class="{{ $status===$value?'is-active':'' }}" @if($status===$value)aria-current="page"@endif>
            {{ $label }} <span>{{ $value==='all'?$counts->sum():(int)$counts->get($value,0) }}</span>
          </a>
        @endforeach
      </nav>
      <form method="get" role="search" class="table-search" aria-label="Search your product catalog">
        @if($status!=='all')<input type="hidden" name="status" value="{{ $status }}">@endif
        <input name="q" value="{{ $search }}" maxlength="120" aria-label="Find your product" placeholder="Product title or ID">
        <button type="submit" class="btn subtle compact">Search</button>
        @if($search!=='')<a class="btn subtle compact" href="{{ route('studio.products.index',$status==='all'?[]:['status'=>$status]) }}">Clear</a>@endif
      </form>
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
              <div class="table-actions">
                <a class="btn subtle compact" href="{{ url('/builder?='.$product->public_id) }}"><x-icon name="edit" size="15"/> {{ in_array($product->status,['draft','rejected'],true) ? 'Edit in builder' : 'Details' }}</a>
                @if($product->status === 'published')
                  <a class="btn subtle compact" href="{{ $product->publicUrl() }}">View</a>
                  <a class="btn primary compact" href="{{ route('studio.vault.index', $product) }}">Releases</a>
                @endif
              </div>
            </td>
          </tr>
        @empty<tr><td class="empty-cell" colspan="7">No digital products have been created.</td></tr>@endforelse
      </tbody>
    </table></div>
    <x-pagination :paginator="$products"/>
  </section>
</div>
@endsection
