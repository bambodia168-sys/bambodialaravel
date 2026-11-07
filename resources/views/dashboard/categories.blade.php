@extends('layouts.dashboard')
@section('title','Category Manager')
@section('content')
<div class="page-intro"><div class="eyebrow">Marketplace · Structure</div><h1>Category Manager</h1><p>Manage the real categories used to organize published marketplace listings and power public search filters.</p></div>
<section class="panel">
<div class="panel-head"><h2>Marketplace categories <span class="count">{{ $categories->total() }}</span></h2>
<div class="toolbar-actions">
<form method="get" class="table-search"><select class="table-select" name="type" aria-label="Filter marketplace type">
@foreach(['all'=>'All types','product'=>'Products','project'=>'Projects','freelance'=>'Freelancers','service'=>'Services'] as $key=>$label)<option value="{{ $key }}" @selected($type===$key)>{{ $label }}</option>@endforeach
</select><button type="submit" class="btn subtle">Filter</button></form>
<button class="btn primary" type="button" data-modal-open="category-new">New category</button>
</div></div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Public ID</th><th>Name</th><th>Type</th><th>Status</th><th>Order</th><th>Listings</th><th>Action</th></tr></thead><tbody>
@forelse($categories as $category)<tr>
<td class="mono">{{ $category->public_id }}</td><td><strong>{{ $category->name }}</strong><div class="text-muted">{{ $category->description }}</div></td>
<td>{{ ucfirst($category->type) }}</td><td><span class="role-pill">{{ $category->is_active?'On':'Off' }}</span></td>
<td>{{ $category->sort_order }}</td><td>{{ $category->listings_count }}</td>
<td><x-row-action-menu :id="'category-'.$category->id" :label="$category->name">
    <button type="button" data-modal-open="category-{{ $category->id }}"><x-icon name="settings" size="16"/> Configure</button>
  </x-row-action-menu>
<dialog class="config-dialog" id="category-{{ $category->id }}">
<form method="post" action="{{ route('dashboard.categories.update',$category) }}">@csrf @method('PATCH')
<h2>Configure category</h2><p>{{ $category->name }} · {{ $category->public_id }}</p>
<label for="cat-name-{{ $category->id }}">Category name</label><input id="cat-name-{{ $category->id }}" name="name" maxlength="120" minlength="3" value="{{ $category->name }}" required>
<label for="cat-desc-{{ $category->id }}">Description</label><textarea id="cat-desc-{{ $category->id }}" name="description" rows="3" maxlength="2000">{{ $category->description }}</textarea>
<label for="cat-type-{{ $category->id }}">Marketplace type</label><select id="cat-type-{{ $category->id }}" name="type" @disabled($category->listings_count>0)>
@foreach(['product','project','freelance','service'] as $option)<option value="{{ $option }}" @selected($category->type===$option)>{{ ucfirst($option) }}</option>@endforeach
</select>@if($category->listings_count>0)<input type="hidden" name="type" value="{{ $category->type }}">@endif
<label for="cat-order-{{ $category->id }}">Display order</label><input id="cat-order-{{ $category->id }}" type="number" min="0" max="100000" name="sort_order" value="{{ $category->sort_order }}" required>
<label for="cat-status-{{ $category->id }}">Enabled for new assignments</label><select id="cat-status-{{ $category->id }}" name="is_active"><option value="1" @selected($category->is_active)>On</option><option value="0" @selected(!$category->is_active)>Off</option></select>
<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save category</button></div>
</form></dialog></td></tr>
@empty<tr><td colspan="7" class="empty-cell">No categories have been created. Create a category to organize new listings.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$categories"/></section>
<dialog class="config-dialog" id="category-new"><form method="post" action="{{ route('dashboard.categories.store') }}">@csrf
<h2>Create marketplace category</h2><label for="category-name">Name</label><input id="category-name" name="name" minlength="3" maxlength="120" required>
<label for="category-type">Marketplace type</label><select id="category-type" name="type">@foreach(['product','project','freelance','service'] as $option)<option value="{{ $option }}">{{ ucfirst($option) }}</option>@endforeach</select>
<label for="category-desc">Description</label><textarea id="category-desc" name="description" rows="3" maxlength="2000"></textarea>
<label for="category-order">Display order</label><input id="category-order" type="number" min="0" max="100000" name="sort_order" value="0" required>
<label for="category-status">Status</label><select id="category-status" name="is_active"><option value="1">On</option><option value="0">Off</option></select>
<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn primary" type="submit">Create category</button></div></form></dialog>
@endsection
