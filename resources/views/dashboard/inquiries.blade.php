@extends('layouts.dashboard')
@section('title','Service Inquiries')
@section('content')
<div class="page-intro"><div class="eyebrow">Marketplace Operations</div><h1>Service Inquiries</h1>
<p>Review operational metadata for marketplace service, project and freelance inquiries. Message content remains private to the customer and creator.</p></div>
<section class="panel"><div class="panel-head"><h2>Inquiries <span class="count">{{ $inquiries->total() }}</span></h2>
<form method="get" class="table-search"><select name="status" class="table-select" aria-label="Filter inquiries">
@foreach(['all'=>'All','open'=>'Open','closed'=>'Closed'] as $value=>$label)<option value="{{ $value }}" @selected($status===$value)>{{ $label }}</option>@endforeach
</select><button class="btn subtle" type="submit">Filter</button></form></div>
<div class="table-scroll"><table class="data-table"><thead>
<tr><th>Reference</th><th>Listing</th><th>Client</th><th>Creator</th><th>Budget</th><th>Status</th><th>Last activity</th></tr>
</thead><tbody>
@forelse($inquiries as $inquiry)<tr>
<td class="mono">{{ $inquiry->public_id }}</td><td>{{ $inquiry->listing?->title }}</td>
<td>{{ $inquiry->client?->name }}</td><td>{{ $inquiry->creator?->name }}</td>
<td>{{ $inquiry->proposed_budget===null?'—':'$'.number_format((float)$inquiry->proposed_budget,2) }}</td>
<td><span class="role-pill">{{ ucfirst($inquiry->status) }}</span></td>
<td>{{ $inquiry->updated_at->format('d M Y') }}</td></tr>
@empty<tr><td class="empty-cell" colspan="7">No recorded service inquiries match this filter.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$inquiries"/></section>
@endsection
