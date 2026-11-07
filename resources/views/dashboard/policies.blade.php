@extends('layouts.dashboard')
@section('title','Policy Center')
@section('content')
<div class="page-intro"><div class="eyebrow">Content & Policies</div><h1>Policy Center</h1><p>Maintain policies, review legal content, and control publication.</p></div>
<p class="text-muted" style="margin:0 0 1rem">Create legal drafts prepares 13 editable policies, including Regular and Extended License terms. This action will not publish them or replace existing documents. Review with qualified counsel before publishing.</p>
<section class="panel">
  <div class="panel-head"><h2>Policies <span class="count">{{ $policies->total() }}</span></h2><div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center"><form action="{{ route('dashboard.policies.legal-drafts') }}" method="post" onsubmit="return confirm('Create missing draft legal policies for review? No existing policies will be changed or published.')">@csrf <button class="btn subtle" type="submit">Create legal drafts</button></form><button type="button" class="btn primary" data-modal-open="policy-new">New policy</button></div></div>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Policy ID</th><th>Title</th><th>Category</th><th>Status</th><th>Reviewed</th><th>Action</th></tr></thead><tbody>
  @forelse($policies as $policy)
    <tr><td class="mono">{{ $policy->public_id }}</td><td><strong>{{ $policy->title }}</strong></td><td>{{ ucfirst($policy->category) }}</td><td><span class="role-pill">{{ ucfirst($policy->status) }}</span></td><td>{{ $policy->reviewed_at?->format('d M Y') ?? 'Pending' }}</td><td><button class="btn subtle compact" type="button" data-modal-open="policy-{{ $policy->id }}"><x-icon name="settings" size="15"/> Configure</button>
      <dialog id="policy-{{ $policy->id }}" class="config-dialog wide-dialog"><form method="post" action="{{ route('dashboard.policies.update',$policy) }}">@csrf @method('PATCH')
        <h2>Configure policy</h2>
        <label for="policy-title-{{ $policy->id }}">Title</label><input id="policy-title-{{ $policy->id }}" name="title" value="{{ $policy->title }}" required>
        <label for="policy-category-{{ $policy->id }}">Category</label><select id="policy-category-{{ $policy->id }}" name="category">@foreach(['general','privacy','cookies','gdpr','terms','commerce'] as $category)<option value="{{ $category }}" @selected($category===$policy->category)>{{ ucfirst($category) }}</option>@endforeach</select>
        <label for="policy-juris-{{ $policy->id }}">Jurisdiction</label><input id="policy-juris-{{ $policy->id }}" name="jurisdiction" value="{{ $policy->jurisdiction }}">
        <label for="policy-body-{{ $policy->id }}">Policy content</label><textarea id="policy-body-{{ $policy->id }}" name="body" rows="10" required>{{ $policy->body }}</textarea>
        <label for="policy-status-{{ $policy->id }}">Publication</label><select id="policy-status-{{ $policy->id }}" name="status"><option value="draft">Draft</option><option value="published" @selected($policy->status==='published')>Published</option></select>
        <label class="check-label"><input type="checkbox" name="confirm_legal_review" value="1"> Administrator legal review completed</label>
        <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save policy</button></div>
      </form></dialog></td></tr>
  @empty<tr><td colspan="6" class="empty-cell">No policies have been created.</td></tr>@endforelse
  </tbody></table></div><x-pagination :paginator="$policies"/>
</section>
<dialog id="policy-new" class="config-dialog wide-dialog"><form method="post" action="{{ route('dashboard.policies.store') }}">@csrf
  <h2>New policy</h2>
  <label for="policy-title-new">Title</label><input id="policy-title-new" name="title" required maxlength="180">
  <label for="policy-category-new">Category</label><select id="policy-category-new" name="category">@foreach(['general','privacy','cookies','gdpr','terms','commerce'] as $category)<option value="{{ $category }}">{{ ucfirst($category) }}</option>@endforeach</select>
  <label for="policy-juris-new">Jurisdiction</label><input id="policy-juris-new" name="jurisdiction" maxlength="120">
  <label for="policy-body-new">Policy content</label><textarea id="policy-body-new" name="body" rows="10" minlength="50" required></textarea>
  <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save draft</button></div>
</form></dialog>
@endsection
