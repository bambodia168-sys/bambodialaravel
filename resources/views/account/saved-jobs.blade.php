@extends('layouts.workspace')
@section('workspace','account')
@section('title','Saved Jobs')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Account · Private opportunities</div><h1>Saved Jobs</h1>
    <p>Keep track of approved client opportunities and return when you are ready to submit a proposal.</p>
  </div>
  @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
  <section class="panel">
    <div class="panel-head"><h2>My saved opportunities <span class="count">{{ $saved->total() }}</span></h2>
      <a href="{{ route('jobs') }}" class="btn subtle"><x-icon name="briefcase" size="16"/> Explore jobs</a>
    </div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Opportunity</th><th>Category</th><th>Client</th><th>Budget</th><th>Saved on</th><th>Actions</th></tr></thead>
      <tbody>
        @forelse($saved as $entry)
        <tr>
          @if($entry->job)
            <td><strong>{{ $entry->job->title }}</strong><div class="text-muted">ID {{ $entry->job->public_id }}</div></td>
            <td>{{ ucfirst($entry->job->category) }}</td>
            <td>{{ $entry->job->client?->name }}</td>
            <td>{{ $entry->job->budget===null?'Not specified':'USD '.number_format((float)$entry->job->budget,2) }}</td>
          @else
            <td><strong>Opportunity no longer available</strong><div class="text-muted">Withdrawn or no longer accepting applications</div></td>
            <td>—</td><td>—</td><td>—</td>
          @endif
          <td>{{ $entry->created_at->format('d M Y') }}</td>
          <td><div class="toolbar-actions">
            @if($entry->job)<a href="{{ $entry->job->publicUrl() }}" class="btn subtle compact">View job</a>@endif
            <form method="post" action="{{ route('saved-jobs.destroy',$entry->marketplace_job_id) }}">
              @csrf @method('DELETE')<button class="btn subtle compact" type="submit">Remove</button>
            </form>
          </div></td>
        </tr>
        @empty
          <tr><td colspan="6" class="empty-cell">No saved jobs. Use the Save action on an approved job to keep it here.</td></tr>
        @endforelse
      </tbody>
    </table></div><x-pagination :paginator="$saved"/>
  </section>
  <p class="hint">A saved job is a private bookmark. It does not reserve a contract, create an application, or commit a budget or payment.</p>
</div>
@endsection
