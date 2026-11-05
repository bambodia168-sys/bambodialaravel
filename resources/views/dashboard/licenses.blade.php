@extends('layouts.dashboard')
@section('title','License Manager')
@section('content')
<div class="page-intro"><div class="eyebrow">Marketplace Governance</div><h1>License Manager</h1><p>Create explicit license terms for digital products. Activated licenses used by products are immutable; issue a new version to change rights.</p></div>
<section class="panel">
  <div class="panel-head">
    <h2>License definitions <span class="count">{{ $licenses->total() }}</span></h2>
    <div class="toolbar-actions">
      <form method="get" class="table-search"><x-icon name="search" size="17"/><input name="search" value="{{ $search }}" aria-label="Search licenses" placeholder="Code or license name"><button class="btn subtle" type="submit">Search</button></form>
      <button class="btn primary" type="button" data-modal-open="license-create">New license</button>
    </div>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Public ID</th><th>License</th><th>Code</th><th>Version</th><th>Status</th><th>Products</th><th>Action</th></tr></thead>
    <tbody>
      @forelse($licenses as $license)
        <tr>
          <td class="mono">{{ $license->public_id }}</td><td><strong>{{ $license->name }}</strong></td>
          <td class="mono">{{ $license->code }}</td><td>{{ $license->version }}</td>
          <td><span class="role-pill">{{ ucfirst($license->status) }}</span></td><td>{{ $license->listings_count }}</td>
          <td>
            <button class="btn subtle compact" type="button" data-modal-open="license-{{ $license->id }}"><x-icon name="settings" size="16"/> Configure</button>
            <dialog class="config-dialog wide-dialog" id="license-{{ $license->id }}">
              <form method="post" action="{{ route('dashboard.licenses.update',$license) }}">
                @csrf @method('PATCH')
                <h2>{{ $license->name }}</h2>
                <p>Public license ID {{ $license->public_id }} · {{ $license->listings_count }} referenced products</p>
                @if($license->listings_count > 0)
                  <div class="notice">This license is referenced by products. Its terms are locked to prevent silent license changes. Create a new version for updated rights.</div>
                  @if($license->status === 'active')<a href="{{ $license->publicUrl() }}" class="btn subtle" target="_blank" rel="noopener noreferrer">Read published license</a>@endif
                  <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Close</button></div>
                @else
                  <label for="license-code-{{ $license->id }}">License code (A–Z, 0–9, underscore)</label><input id="license-code-{{ $license->id }}" name="code" value="{{ $license->code }}" required maxlength="32">
                  <label for="license-name-{{ $license->id }}">Display name</label><input id="license-name-{{ $license->id }}" name="name" value="{{ $license->name }}" required maxlength="120">
                  <label for="license-version-{{ $license->id }}">Version</label><input id="license-version-{{ $license->id }}" name="version" value="{{ $license->version }}" required placeholder="1.0.0">
                  <label for="license-permissions-{{ $license->id }}">Permissions granted</label><textarea id="license-permissions-{{ $license->id }}" name="permissions" minlength="30" rows="6" required>{{ $license->permissions }}</textarea>
                  <label for="license-restrictions-{{ $license->id }}">Restrictions and prohibited uses</label><textarea id="license-restrictions-{{ $license->id }}" name="restrictions" minlength="30" rows="6" required>{{ $license->restrictions }}</textarea>
                  <label for="license-status-{{ $license->id }}">Status</label><select id="license-status-{{ $license->id }}" name="status"><option value="draft" @selected($license->status==='draft')>Draft</option><option value="active" @selected($license->status==='active')>Active</option></select>
                  <label class="check-label"><input type="checkbox" name="confirm_review" value="1"> I have reviewed these license terms and authorize their publication</label>
                  <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save license</button></div>
                @endif
              </form>
            </dialog>
          </td>
        </tr>
      @empty<tr><td class="empty-cell" colspan="7">No license definitions have been created.</td></tr>@endforelse
    </tbody>
  </table></div>
  <x-pagination :paginator="$licenses"/>
</section>
<dialog class="config-dialog wide-dialog" id="license-create">
  <form method="post" action="{{ route('dashboard.licenses.store') }}">
    @csrf
    <h2>Create a license definition</h2>
    <p>Define the rights granted to buyers and the uses that are prohibited. Legal review is required before activation.</p>
    <label for="license-new-code">License code</label><input id="license-new-code" name="code" placeholder="REGULAR" maxlength="32" required>
    <label for="license-new-name">License name</label><input id="license-new-name" name="name" maxlength="120" required>
    <label for="license-new-version">Version</label><input id="license-new-version" name="version" value="1.0.0" required>
    <label for="license-new-permissions">Permissions granted</label><textarea id="license-new-permissions" name="permissions" minlength="30" rows="6" required></textarea>
    <label for="license-new-restrictions">Restrictions and prohibited uses</label><textarea id="license-new-restrictions" name="restrictions" minlength="30" rows="6" required></textarea>
    <label for="license-new-status">Publication status</label><select id="license-new-status" name="status"><option value="draft">Draft</option><option value="active">Active</option></select>
    <label class="check-label"><input type="checkbox" name="confirm_review" value="1"> I have reviewed these license terms and authorize their publication</label>
    <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Create license</button></div>
  </form>
</dialog>
@endsection
