@extends('layouts.main')
@section('title','My Policy Receipts')
@section('content')
<div class="container inner-page">
<div class="page-intro"><div class="eyebrow">Account · Privacy and compliance records</div>
  <h1>My Policy Receipts</h1>
  <p>Review your voluntary acknowledgement history for specific published policy revisions. Acknowledging a revision is not blanket consent to personal-data processing.</p>
</div>
@if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
<section class="panel">
  <div class="panel-head"><h2>Reading acknowledgements <span class="count">{{ $receipts->total() }}</span></h2>
    <a href="{{ route('policy-center') }}" class="btn subtle">Read current policies</a>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Policy</th><th>Revision</th><th>Publication digest</th><th>Recorded action</th><th>Acknowledged at</th></tr></thead>
    <tbody>
    @forelse($receipts as $receipt)
    <tr>
      <td><strong>{{ $receipt->publication?->title }}</strong></td>
      <td>{{ $receipt->publication?->revision }}</td>
      <td><span class="mono">{{ substr($receipt->sha256,0,20) }}…</span></td>
      <td>{{ str_replace('_',' ',$receipt->record_type) }}</td>
      <td>{{ $receipt->created_at->format('d M Y H:i') }}</td>
    </tr>
    @empty<tr><td colspan="5" class="empty-cell">You have not voluntarily acknowledged a published policy revision.</td></tr>@endforelse
    </tbody>
  </table></div>
  <x-pagination :paginator="$receipts"/>
</section>
</div>
@endsection
