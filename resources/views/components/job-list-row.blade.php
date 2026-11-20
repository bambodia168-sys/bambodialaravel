@props(['job', 'isSaved' => false])
@php
  $previewOnly = str_starts_with($job->title,'[LAYOUT TEST] ');
  $clientName = $job->client?->name ?: 'Bambodia client';
  $budget = $job->budget===null ? 'On request'
    : \App\Support\MarketplaceCardPresentation::price($job->budget,$job->currency);
@endphp
<article class="bambodia-job-row" aria-labelledby="bambodia-job-title-{{ $job->public_id }}">
  <div class="bambodia-job-avatar bambodia-job-avatar--{{ $job->category }}" aria-hidden="true"><x-profile-avatar :user="$job->client" public lazy size="23"/></div>
  <div class="bambodia-job-main">
    <div class="bambodia-job-title-line">
      <h3 id="bambodia-job-title-{{ $job->public_id }}"><a href="{{ $job->publicUrl() }}">{{ $job->title }}</a></h3>
      @if($job->status==='published' && $job->is_featured)
        <span class="bambodia-job-badge bambodia-job-badge--featured"><x-icon name="tag" size="13"/> Featured</span>
      @endif
      @if($previewOnly)
        <span class="bambodia-job-badge bambodia-job-badge--preview">Preview only</span>
      @else
        <span class="bambodia-job-badge">Approved</span>
      @endif
    </div>
    <div class="bambodia-job-meta">
      <span><x-icon name="user" size="15"/> {{ $clientName }}</span>
      <span><x-icon name="briefcase" size="15"/> {{ $job->category_label }}</span>
      <span><x-icon name="clock" size="15"/> Created {{ $job->created_at->diffForHumans() }}</span>
    </div>
  </div>
  <div class="bambodia-job-budget">
    <strong>{{ $budget }}</strong>
    <span>{{ $job->budget===null ? 'Budget not specified' : 'Project budget' }}</span>
  </div>
  <div class="bambodia-job-actions">
    <a class="bambodia-job-button" href="{{ $job->publicUrl() }}" aria-label="{{ $previewOnly ? 'View preview of' : 'View job details for' }} {{ $job->title }}">
      {{ $previewOnly ? 'View preview' : 'View job' }}
    </a>
    <div class="card-save-action"><x-save-star :item="$job" :is-saved="$isSaved"/></div>
  </div>
</article>
