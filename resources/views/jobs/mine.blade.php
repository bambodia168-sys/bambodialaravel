@extends('layouts.main')
@section('title','My Job Posts')
@section('content')
<div class="container inner-page">
  <div class="page-intro">
    <div class="eyebrow">Client Account</div><h1>Job Manager</h1>
    <p>Post genuine job briefs, manage existing posts and review freelancer proposals. All new jobs require administrator approval.</p>
  </div>
  <section class="panel">
    <div class="panel-head">
      <h2>{{ $archived ? 'Archived jobs' : 'My posted jobs' }} <span class="count">{{ $jobs->total() }}</span></h2>
      <a class="btn primary" href="{{ route('builder', ['type'=>'job']) }}"><x-icon name="plus" size="16"/> Post a job</a>
    </div>
    <nav class="workspace-queue-tabs" aria-label="Jobs by status">
      <a href="{{ route('jobs.mine') }}" @if(!$archived) class="is-active" aria-current="page" @endif>My jobs</a>
      <a href="{{ route('jobs.mine', ['status' => 'archived']) }}" @if($archived) class="is-active" aria-current="page" @endif>Archived</a>
    </nav>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Budget</th><th>Status</th><th>Proposals</th><th>Action</th></tr></thead>
      <tbody>
      @forelse($jobs as $job)
        <tr>
          <td class="mono">{{ $job->public_id }}</td>
          <td><strong>{{ $job->title }}</strong></td>
          <td>{{ $job->category_label }}</td>
          <td>{{ $job->budget===null?'—':'$'.number_format((float)$job->budget,2) }}</td>
          <td><span class="role-pill">{{ ucfirst($job->status) }}</span></td>
          <td>{{ $job->proposals_count }}</td>
          <td><x-row-action-menu :id="'my-job-'.$job->id" :label="$job->title">
            <a href="{{ url('/builder?='.$job->public_id) }}"><x-icon name="edit" size="16"/> {{ in_array($job->status,['draft','pending','rejected'],true) ? 'Edit in Builder Studio' : 'View details' }}</a>
            @if($job->status === 'published')<a href="{{ $job->publicUrl() }}"><x-icon name="external-link" size="16"/> View published job</a>@endif
            <a href="{{ route('jobs.proposals',$job) }}"><x-icon name="eye" size="16"/> Proposals</a>
            <x-marketplace-owner-lifecycle :record="$job" :menu-id="'my-job-'.$job->id" :triggers="true"/>
          </x-row-action-menu>
          <x-marketplace-owner-lifecycle :record="$job" :menu-id="'my-job-'.$job->id"/>
          </td>
        </tr>
      @empty
        <tr><td colspan="7" class="empty-cell">You have not posted a job yet.</td></tr>
      @endforelse
      </tbody>
    </table></div>
    <x-pagination :paginator="$jobs"/>
  </section>
</div>
@endsection
