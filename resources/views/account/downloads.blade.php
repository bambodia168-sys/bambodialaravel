@extends('layouts.main')
@section('title','My Downloads')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Account · Secure Fulfillment</div><h1>My Downloads</h1>
    <p>Only approved versions explicitly licensed to your account appear here. Download access is checked again for every request.</p></div>
  <section class="panel">
    <div class="panel-head"><h2>Licensed file access <span class="count">{{ $entitlements->total() }}</span></h2></div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Product</th><th>Version</th><th>File type</th><th>Access</th><th>Expires</th><th>Action</th></tr></thead>
      <tbody>
      @forelse($entitlements as $entitlement)
        @php
          $version = $entitlement->version;
          $product = $version?->product;
          $available = $entitlement->isActive() && $version?->isApproved()
            && $product?->status === 'published'
            && in_array(auth()->user()->role, ['customer','subscriber','freelancer'],true);
        @endphp
        <tr>
          <td><strong>{{ $product?->title ?? 'Unavailable product' }}</strong><div class="text-muted">ID {{ $product?->public_id }}</div></td>
          <td class="mono">{{ $version?->version }}</td><td>{{ strtoupper($version?->file_type ?? '—') }}</td>
          <td><span class="role-pill">{{ $available ? 'Available' : 'Unavailable' }}</span></td>
          <td>{{ $entitlement->expires_at?->format('d M Y') ?? '—' }}</td>
          <td>
          @if($available)
            <a class="btn primary compact" href="{{ route('account.downloads.file',$entitlement) }}"><x-icon name="download" size="15"/> Download</a>
          @else<span class="text-muted">Download unavailable</span>@endif
          </td>
        </tr>
      @empty<tr><td class="empty-cell" colspan="6">No verified product versions have been granted to this account.</td></tr>@endforelse
      </tbody></table></div><x-pagination :paginator="$entitlements"/>
  </section>
  <p class="hint">Products do not become downloadable by entering a URL or by selecting another user's public ID. No checkout or payment acceptance is active.</p>
</div>
@endsection
