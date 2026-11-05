@extends('layouts.dashboard')
@section('title','Compliance Register')
@section('content')
<div class="page-intro"><div class="eyebrow">Super Admin · Policy provenance</div><h1>Compliance Register</h1>
  <p>Versioned policy publications and optional user reading acknowledgements. This register is operational evidence, not a legal compliance certificate or verified personal identity system.</p>
</div>
<div class="metrics">
  <div class="metric"><span><x-icon name="book"/> Published revisions</span><strong>{{ number_format($totalPublications) }}</strong></div>
  <div class="metric"><span><x-icon name="check"/> Reading acknowledgements</span><strong>{{ number_format($totalReceipts) }}</strong></div>
  <div class="metric"><span><x-icon name="file"/> Account contract decisions</span><strong>{{ number_format($contractAcknowledgements) }}</strong></div>
  <div class="metric"><span><x-icon name="alert"/> Unresolved disputes</span><strong>{{ number_format($openDisputes) }}</strong></div>
</div>
<section class="panel">
  <div class="panel-head"><h2>Most recent published policy revisions</h2>
    <a class="btn subtle" href="{{ route('dashboard.policies') }}">Policy Center</a>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Policy</th><th>Revision</th><th>Published by</th><th>SHA-256</th><th>Date</th></tr></thead>
    <tbody>
    @forelse($publications as $publication)
      <tr><td><strong>{{ $publication->title }}</strong></td>
        <td>{{ $publication->revision }}</td><td>{{ $publication->publishedBy?->name }}</td>
        <td><span class="mono">{{ substr($publication->sha256,0,20) }}…</span></td>
        <td>{{ $publication->created_at->format('d M Y') }}</td></tr>
    @empty<tr><td colspan="5" class="empty-cell">No policy revisions have been published with the new snapshot register.</td></tr>@endforelse
    </tbody>
  </table></div>
</section>
<section class="panel">
  <div class="panel-head"><h2>Recorded reading acknowledgements <span class="count">{{ $receipts->total() }}</span></h2></div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Account</th><th>Public ID</th><th>Policy</th><th>Revision</th><th>Exact digest</th><th>Recorded</th></tr></thead>
    <tbody>
    @forelse($receipts as $receipt)
      <tr><td><strong>{{ $receipt->user?->name }}</strong></td><td class="mono">{{ $receipt->user?->public_id }}</td>
        <td>{{ $receipt->publication?->title }}</td><td>{{ $receipt->publication?->revision }}</td>
        <td class="mono">{{ substr($receipt->sha256,0,12) }}…</td>
        <td>{{ $receipt->created_at->format('d M Y H:i') }}</td></tr>
    @empty<tr><td colspan="6" class="empty-cell">No voluntary policy reading acknowledgements recorded yet.</td></tr>@endforelse
    </tbody>
  </table></div>
  <x-pagination :paginator="$receipts"/>
</section>
<p class="hint">Historical policy versions must remain immutable. Revisions published before this feature have no registered digest until manually republished after administrator review. Privacy rights, merchant eligibility and jurisdiction-specific legal requirements still require independent assessment.</p>
@endsection
