@extends('layouts.workspace')
@section('workspace','creator')
@section('title','My Products — Licenses')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Creator Studio · Product Licensing</div><h1>Product Licenses</h1>
    <p>View licenses issued for your published downloadable products, their activation status and release history. Customer keys remain private.</p></div>
  <div class="notice">Creators cannot mint, revoke, rotate or upgrade customer keys. Bambodia verifies the product, its file entitlement and reviewed licensing terms before issuance. Automatic purchase issuance is not enabled yet.</div>
  <section class="panel">
    <div class="panel-head"><h2>Licenses for your products <span class="count">{{ $licenses->total() }}</span></h2></div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Product</th><th>Release</th><th>Customer ID</th><th>License</th><th>Status</th><th>Domains & activity</th></tr></thead>
      <tbody>
      @forelse($licenses as $license)
        <tr>
          <td><strong>{{ $license->product?->title }}</strong><div class="text-muted">Product {{ $license->product?->public_id }}</div></td>
          <td>v{{ $license->version?->version }}</td>
          <td class="mono">{{ $license->customer?->public_id }}</td>
          <td>{{ ucfirst($license->tier) }} · {{ $license->definition?->name }}</td>
          <td><span class="role-pill">{{ ucfirst($license->status) }}</span></td>
          <td><details><summary>Review history</summary>
            @forelse($license->activations as $domain)
              <p class="hint">{{ $domain->domain }} · {{ ucfirst($domain->status) }} · {{ $domain->activated_at?->format('d M Y') }}</p>
            @empty<p class="hint">No activated domains.</p>@endforelse
            @foreach($license->events->take(10) as $event)
              <p class="hint">{{ ucfirst(str_replace('.',' ',$event->action)) }} · {{ $event->created_at->format('d M Y H:i') }}</p>
            @endforeach
          </details></td>
        </tr>
      @empty<tr><td class="empty-cell" colspan="6">No product licenses have been issued for your downloadable products yet.</td></tr>@endforelse
      </tbody>
    </table></div>
    <x-pagination :paginator="$licenses"/>
  </section>
</div>
@endsection
