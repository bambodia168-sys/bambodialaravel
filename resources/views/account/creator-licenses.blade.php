@extends('layouts.workspace')
@section('workspace','creator')
@section('title','My Products — Licenses')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Creator Studio · Product Licensing</div><h1>Product Licenses</h1>
    <p>View licenses issued for your published downloadable products, their activation status and release history. Customer keys remain private.</p></div>
  <section class="panel">
    <div class="panel-head"><h2>Licenses for your products <span class="count">{{ $licenses->total() }}</span></h2></div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Product</th><th>Release</th><th>Customer ID</th><th>License</th><th>License period</th><th>Support</th><th>Status</th><th>Domains & activity</th></tr></thead>
      <tbody>
      @forelse($licenses as $license)
        <tr>
          <td><strong>{{ $license->product?->title }}</strong><div class="text-muted">Product {{ $license->product?->public_id }}</div></td>
          <td>v{{ $license->version?->version }}</td>
          <td class="mono">{{ $license->customer?->public_id }}</td>
          <td><strong>{{ ucfirst($license->tier) }} License</strong><div class="text-muted mono">License {{ $license->public_id }}</div>
            <x-license-definition-readonly :definition="$license->definition" :label="ucfirst($license->tier).' License'"/>
          </td>
          <td><strong>Lifetime</strong></td>
          <td>
            <span class="role-pill {{ $license->supportStatus() === 'Expired' ? 'license-support-expired' : '' }}">{{ $license->supportStatus() }}</span>
            <div class="license-table-secondary">{{ $license->supportName() }}</div>
            @if($license->support_expires_at)<div class="license-table-secondary">Until {{ $license->support_expires_at->format('d M Y') }}</div>@endif
          </td>
          <td><span class="role-pill">{{ ucfirst($license->status) }}</span></td>
          <td><details><summary>Review history</summary>
            @forelse($license->activations as $domain)
              <p class="hint">{{ $domain->domain }} · {{ ucfirst($domain->status) }} · {{ $domain->activated_at?->format('d M Y') }}</p>
            @empty<p class="hint">No activated domains.</p>@endforelse
            @foreach($license->events->take(10) as $event)
              <p class="hint">{{ ucfirst(str_replace('.',' ',$event->action)) }} · {{ $event->created_at->format('d M Y H:i') }}</p>
            @endforeach
          </details>
          <details class="license-workflow-panel">
            <summary>Request administrative review</summary>
            <form method="post" action="{{ route('studio.licenses.request-review',$license) }}" class="license-workflow-form">
              @csrf
              <label for="license-request-action-{{ $license->id }}">Requested action</label>
              <select id="license-request-action-{{ $license->id }}" name="action" required>
                <option value="domain_review">Domain transfer assistance</option>
                <option value="upgrade_review">Review upgrade to Extended</option>
                <option value="suspension_review">Investigate license suspension</option>
                <option value="revocation_review">Investigate potential license abuse</option>
              </select>
              <label for="license-request-reason-{{ $license->id }}">Explain the issue</label>
              <textarea id="license-request-reason-{{ $license->id }}" name="reason" rows="3" minlength="20" maxlength="500" required></textarea>
              <button type="submit" class="btn subtle compact">Request review</button>
              <p class="hint">This sends an administrator-review request only. It does not change the customer's license, domain or key.</p>
            </form>
          </details></td>
        </tr>
      @empty<tr><td class="empty-cell" colspan="8">No product licenses have been issued for your downloadable products yet.</td></tr>@endforelse
      </tbody>
    </table></div>
    <x-pagination :paginator="$licenses"/>
  </section>
</div>
@endsection
