@extends('layouts.main')
@section('title',$creator?'Creator Inquiries':'My Service Requests')
@section('content')
<div class="container inner-page">
<div class="page-intro"><div class="eyebrow">{{ $creator?'Creator Studio':'Customer Account' }} · Private Messages</div>
<h1>{{ $creator?'Client Inquiries':'My Service Requests' }}</h1>
<p>Track real project discussions. Only the two participants can read messages or close the inquiry.</p></div>
<section class="panel"><div class="panel-head"><h2>Conversations <span class="count">{{ $inquiries->total() }}</span></h2><a class="btn subtle" href="{{ route('marketplace.search') }}">Explore listings</a></div>
<div class="workspace-queue-toolbar">
  <nav class="workspace-queue-tabs" aria-label="Private conversation status">
    @foreach(['all'=>'All','open'=>'Open','closed'=>'Closed'] as $value=>$label)
      <a href="{{ route($creator?'studio.inquiries':'account.inquiries',array_merge(request()->except('status','page'),$value==='all'?[]:['status'=>$value])) }}"
        class="{{ $status===$value?'is-active':'' }}" @if($status===$value)aria-current="page"@endif>
        {{ $label }} <span>{{ $value==='all'?$counts->sum():(int)$counts->get($value,0) }}</span>
      </a>
    @endforeach
  </nav>
  <form method="get" class="table-search" role="search" aria-label="Search your private conversations">
    @if($status!=='all')<input type="hidden" name="status" value="{{ $status }}">@endif
    <input name="q" value="{{ $search }}" maxlength="120" aria-label="Find conversation subject or ID" placeholder="Subject or reference">
    <button type="submit" class="btn subtle compact">Search</button>
    @if($search!=='')<a class="btn subtle compact" href="{{ route($creator?'studio.inquiries':'account.inquiries',$status==='all'?[]:['status'=>$status]) }}">Clear</a>@endif
  </form>
</div>
<div class="table-scroll"><table class="data-table">
<thead><tr><th>Reference</th><th>Listing</th><th>{{ $creator?'Client':'Creator' }}</th><th>Subject</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead><tbody>
@forelse($inquiries as $inquiry)<tr>
<td class="mono">{{ $inquiry->public_id }}</td>
<td><strong>{{ $inquiry->listing?->title }}</strong><div class="text-muted">{{ ucfirst($inquiry->listing?->type ?? '') }}</div></td>
<td>{{ $creator?$inquiry->client?->name:$inquiry->creator?->name }}</td>
<td>{{ $inquiry->subject }}</td><td><span class="role-pill">{{ ucfirst($inquiry->status) }}</span></td>
<td>{{ $inquiry->updated_at->format('d M Y') }}</td>
<td><a class="btn subtle compact" href="{{ route('inquiries.show',$inquiry) }}">Open conversation</a></td></tr>
@empty<tr><td colspan="7" class="empty-cell">No inquiries are available.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$inquiries"/>
</section></div>
@endsection
