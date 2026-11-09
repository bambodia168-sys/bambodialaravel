@props(['job', 'isSaved' => false])
@php
  $previewOnly = str_starts_with($job->title,'[LAYOUT TEST] ');
  $clientName = $job->client?->name ?: 'Bambodia client';
  $nameParts = preg_split('/\s+/u',trim($clientName),-1,PREG_SPLIT_NO_EMPTY) ?: [];
  $clientInitials = collect($nameParts)->take(2)
    ->map(fn($word) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($word,0,1)))
    ->implode('');
  $budget = $job->budget===null ? 'On request'
    : \App\Support\MarketplaceCardPresentation::price($job->budget,$job->currency);
@endphp
<article class="bambodia-job-row" aria-labelledby="bambodia-job-title-{{ $job->public_id }}">
  <div class="bambodia-job-avatar bambodia-job-avatar--{{ $job->category }}" aria-hidden="true">{{ $clientInitials }}</div>
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
      <span><x-icon name="briefcase" size="15"/> {{ ucfirst($job->category) }}</span>
      <span><x-icon name="clock" size="15"/> Created {{ $job->created_at->diffForHumans() }}</span>
    </div>
  </div>
  <div class="bambodia-job-budget">
    <strong>{{ $budget }}</strong>
    <span>{{ $job->budget===null ? 'Budget not specified' : 'Project budget' }}</span>
  </div>
  <div class="bambodia-job-actions">
    <a class="bambodia-job-button" href="{{ $job->publicUrl() }}" aria-label="{{ $previewOnly ? 'View preview of' : 'View job details for' }} {{ $job->title }}">
      {{ $previewOnly ? 'View preview' : 'View job' }} <x-icon name="arrow-up-right" size="16"/>
    </a>
    @auth
      @if($isSaved)
        <form method="post" action="{{ route('saved-jobs.destroy',$job) }}">
          @csrf @method('DELETE')
          <button type="submit" class="bambodia-job-save" aria-label="Remove {{ $job->title }} from saved jobs"><x-icon name="bookmark" size="15"/> Saved</button>
        </form>
      @else
        <form method="post" action="{{ route('saved-jobs.store',$job) }}">
          @csrf
          <button type="submit" class="bambodia-job-save" aria-label="Save {{ $job->title }}"><x-icon name="bookmark" size="15"/> Save</button>
        </form>
      @endif
    @endauth
  </div>
</article>
