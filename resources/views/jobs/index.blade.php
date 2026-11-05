@extends('layouts.main')
@section('title','Find Jobs')
@section('content')
<div class="container inner-page">
 <div class="page-intro"><div class="eyebrow">Bambodia · Freelance Jobs</div><h1>Find Jobs</h1><p>Browse approved client projects and submit proposals from your Freelancer account.</p></div>
 <section class="panel"><div class="panel-head"><h2>Open jobs <span class="count">{{ $jobs->total() }}</span></h2>
  <form method="get" class="table-search"><x-icon name="search" size="17"/><input name="q" value="{{ $search }}" placeholder="Search job titles and descriptions" aria-label="Search jobs"><button class="btn subtle" type="submit">Search</button></form>
 </div>
 <div class="table-scroll"><table class="data-table"><thead><tr><th>Job</th><th>Category</th><th>Client</th><th>Estimated Budget</th><th>Published</th><th>Action</th></tr></thead><tbody>
 @forelse($jobs as $job)<tr>
 <td><strong>{{ $job->title }}</strong><div class="text-muted">ID {{ $job->public_id }}</div></td>
 <td>{{ ucfirst($job->category) }}</td><td>{{ $job->client?->name }}</td>
 <td>{{ $job->budget===null ? 'Not specified' : '$'.number_format((float)$job->budget,2).' USD' }}</td>
 <td>{{ $job->updated_at->format('d M Y') }}</td>
 <td><a class="btn subtle compact" href="{{ $job->publicUrl() }}">View job</a></td>
 </tr>@empty<tr><td colspan="6" class="empty-cell">No approved open jobs match this search.</td></tr>@endforelse
 </tbody></table></div><x-pagination :paginator="$jobs"/></section>
 @auth
 @if(in_array(auth()->user()->role,['customer','subscriber','super_admin'],true))<p><a class="btn primary" href="{{ route('jobs.mine') }}">Manage my job posts</a></p>@endif
 @if(auth()->user()->role==='freelancer')<p><a class="btn subtle" href="{{ route('jobs.applied') }}">My proposals</a></p>@endif
 @endauth
</div>
@endsection
