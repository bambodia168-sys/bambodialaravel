@extends('layouts.main')
@section('title',$job->title)
@section('content')
<div class="container inner-page listing-detail premium-detail premium-job-detail">
  <nav class="marketplace-breadcrumb" aria-label="Breadcrumb">
    <a href="{{ route('home') }}">Home</a><x-icon name="chevron-right" size="14"/>
    <a href="{{ route('jobs') }}">Jobs</a><x-icon name="chevron-right" size="14"/>
    <span aria-current="page">{{ $job->public_id }}</span>
  </nav>
  <header class="premium-detail-intro">
    <div class="premium-detail-kicker">
      <span class="premium-detail-type"><x-icon name="briefcase" size="16"/> Approved job opportunity</span>
      <span class="premium-detail-id">Job #{{ $job->public_id }}</span>
    </div>
    <h1>{{ $job->title }}</h1>
    <div class="premium-detail-byline">
      <span><x-icon name="user" size="16"/> {{ $job->client?->name ?? 'Bambodia client' }}</span>
      <span><x-icon name="layers" size="16"/> {{ ucfirst($job->category) }}</span>
      <span><x-icon name="check" size="16"/> Open for proposals</span>
    </div>
  </header>
  @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif

  <nav class="premium-detail-tabs" aria-label="Job opportunity sections">
    <a href="#overview">Overview</a>
    <a href="#requirements">How proposals work</a>
    @auth
      @if(auth()->user()->role==='freelancer')<a href="#apply">Apply</a>@endif
    @endauth
    <a href="#client">Client</a>
  </nav>
  <div class="premium-detail-layout">
    <main class="premium-detail-main">
      <section class="premium-showcase" aria-label="Job overview">
        @if($job->featuredAsset?->review_status === 'approved' && in_array($job->featured_kind, ['image','animated'],true))
          <img class="premium-showcase-cover" src="{{ $job->featuredAsset->previewUrl() }}" alt="{{ $job->featuredAsset->alt_text ?: $job->title }}">
        @elseif($job->featuredAsset?->review_status === 'approved' && $job->featured_kind === 'video')
          <video class="premium-showcase-cover studio-showcase-video" src="{{ $job->featuredAsset->previewUrl() }}" controls playsinline preload="metadata" aria-label="{{ $job->featuredAsset->alt_text }}"></video>
        @elseif($job->featured_kind === 'color' && preg_match('/^#[0-9a-fA-F]{6}$/', $job->featured_color ?? ''))
          <div class="premium-showcase-placeholder" style="background-color:{{ $job->featured_color }};color:#fff">
            <span class="premium-showcase-symbol"><x-icon name="briefcase" size="58"/></span>
            <h2>{{ $job->title }}</h2>
          </div>
        @else
        <div class="premium-showcase-placeholder premium-showcase-job">
          <span class="premium-showcase-symbol"><x-icon name="briefcase" size="58"/></span>
          <span class="eyebrow">Approved client opportunity</span>
          <h2>{{ ucfirst($job->category) }}</h2>
          <p>{{ $job->title }}</p>
        </div>
        @endif
      </section>
      <div class="premium-showcase-actions">
        <div class="marketplace-detail-actions">
          @auth
            @if($isSaved)
              <form method="post" action="{{ route('saved-jobs.destroy',$job) }}">@csrf @method('DELETE')
                <button type="submit" class="btn subtle"><x-icon name="bookmark" size="16"/> Saved — remove from list</button>
              </form>
            @else
              <form method="post" action="{{ route('saved-jobs.store',$job) }}">@csrf
                <button type="submit" class="btn subtle"><x-icon name="bookmark" size="16"/> Save job</button>
              </form>
            @endif
            <a class="btn subtle" href="{{ route('account.saved-jobs') }}">My saved jobs</a>
          @else
            <a class="btn subtle" href="{{ route('login') }}">Sign in to save this job</a>
          @endauth
        </div>
        <a class="premium-action-link" href="{{ route('jobs') }}">Browse more opportunities <x-icon name="chevron-right" size="15"/></a>
      </div>

      @if($screenshots->isNotEmpty())
        <section class="panel premium-content-section" id="screenshots">
          <div class="panel-head"><div><span class="eyebrow">Approved visual resources</span><h2>Screenshots &amp; gallery</h2></div></div>
          <div class="builder-public-gallery">
            @foreach($screenshots as $shot)
              <a href="{{ $shot->previewUrl() }}" target="_blank" rel="noopener noreferrer"
                 aria-label="View screenshot: {{ $shot->alt_text }}">
                <img src="{{ $shot->previewUrl() }}" alt="{{ $shot->alt_text }}" loading="lazy">
              </a>
            @endforeach
          </div>
        </section>
      @endif

      <section class="panel premium-content-section" id="overview">
        <div class="panel-head"><div><span class="eyebrow">Published client brief</span><h2>Job brief</h2></div></div>
        <div class="listing-body premium-description">{!! $job->description_html ? \App\Support\BuilderContent::sanitize($job->description_html) : nl2br(e($job->description)) !!}</div>
      </section>
      <section class="panel premium-content-section" id="requirements">
        <div class="panel-head"><div><span class="eyebrow">A controlled application process</span><h2>How proposals work</h2></div></div>
        <div class="premium-steps">
          <div><span class="premium-step-number">01</span><div><strong>Review the published brief</strong><p>Check the requirements and stated budget before applying.</p></div></div>
          <div><span class="premium-step-number">02</span><div><strong>Submit a professional proposal</strong><p>Freelancers with published resumes can describe their approach and optional proposed budget.</p></div></div>
          <div><span class="premium-step-number">03</span><div><strong>Await the client decision</strong><p>The client can shortlist or decline a proposal. Applying does not create a contract or payment.</p></div></div>
        </div>
      </section>
      @auth
        @if(auth()->user()->role==='freelancer')
          <section class="panel premium-content-section" id="apply">
            <div class="panel-head"><div><span class="eyebrow">Work with this client</span><h2>Submit a proposal</h2></div></div>
            <div class="onboarding-content">
              @if($applied)
                <p>You already submitted a proposal for this job. <a class="marketplace-creator-link" href="{{ route('jobs.applied') }}">View its current status</a>.</p>
              @else
                <form action="{{ route('jobs.apply',$job) }}" method="post" class="creator-application-form">@csrf
                  <label for="proposal-message">Describe your relevant skills, scope, and approach</label>
                  <textarea id="proposal-message" name="cover_message" minlength="50" maxlength="5000" rows="7" required>{{ old('cover_message') }}</textarea>
                  <label for="proposal-budget">Your proposed budget (USD, optional)</label>
                  <input type="number" id="proposal-budget" name="proposed_budget" min="0" step="0.01" value="{{ old('proposed_budget') }}">
                  <p class="hint">A published resume is required. Submitting is an application, not a contract or payment commitment.</p>
                  <a class="btn subtle" href="{{ route('resume.edit') }}">Resume Builder</a>
                  <button class="btn primary" type="submit">Submit proposal</button>
                </form>
              @endif
            </div>
          </section>
        @elseif((int)auth()->id()===(int)$job->client_id)
          <section class="panel premium-content-section" id="apply">
            <div class="panel-head"><h2>Manage applications</h2></div>
            <div class="listing-body"><a class="btn primary" href="{{ route('jobs.proposals',$job) }}">Review applications</a></div>
          </section>
        @endif
      @endauth
      <section class="panel premium-content-section" id="client">
        <div class="panel-head"><div><span class="eyebrow">Published by</span><h2>Client information</h2></div></div>
        <div class="premium-creator-body">
          <div class="premium-creator-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($job->client?->name ?? 'B',0,1)) }}</div>
          <div><strong>{{ $job->client?->name ?? 'Bambodia client' }}</strong>
            <p>Client who published this approved job brief.</p></div>
        </div>
      </section>
    </main>
    <aside class="premium-detail-sidebar" aria-label="Opportunity details and actions">
      <section class="premium-side-card premium-price-card">
        <div class="premium-side-card-body">
          <span class="eyebrow">Opportunity summary</span>
          <div class="premium-side-price"><span>Estimated budget</span>
            <strong>{{ $job->budget===null ? 'On request' : '$'.number_format((float)$job->budget,2) }}</strong></div>
          <p class="premium-price-note">USD · Client-provided estimate</p>
          <div class="premium-side-checks">
            <span><x-icon name="check" size="17"/> Administrator-approved job</span>
            <span><x-icon name="shield" size="17"/> Private, permission-scoped proposals</span>
          </div>
          @auth
            @if(auth()->user()->role==='freelancer')
              <a class="btn primary premium-full-action" href="{{ $applied ? route('jobs.applied') : '#apply' }}">{{ $applied?'View my proposal':'Apply to this job' }} <x-icon name="chevron-right" size="16"/></a>
            @elseif((int)auth()->id()===(int)$job->client_id)
              <a class="btn primary premium-full-action" href="{{ route('jobs.proposals',$job) }}">Review applications <x-icon name="chevron-right" size="16"/></a>
            @else
              <a class="btn subtle premium-full-action" href="{{ route('jobs') }}">Explore other jobs</a>
            @endif
          @else
            <a class="btn primary premium-full-action" href="{{ route('login') }}">Sign in to apply <x-icon name="chevron-right" size="16"/></a>
          @endauth
          <p class="premium-action-disclaimer">Budgets are informational. Bambodia does not currently process payments, hold escrow or create contracts automatically.</p>
        </div>
      </section>
      <section class="premium-side-card">
        <div class="premium-side-card-heading"><h2>Information</h2></div>
        <dl class="premium-info-list">
          <div><dt>Category</dt><dd>{{ ucfirst($job->category) }}</dd></div>
          <div><dt>Created</dt><dd>{{ $job->created_at->format('d M Y') }}</dd></div>
          <div><dt>Last updated</dt><dd>{{ $job->updated_at->format('d M Y') }}</dd></div>
          <div><dt>Currency</dt><dd>{{ $job->currency ?: 'USD' }}</dd></div>
          <div><dt>Reference</dt><dd>{{ $job->public_id }}</dd></div>
        </dl>
      </section>
    </aside>
  </div>
</div>
@endsection
