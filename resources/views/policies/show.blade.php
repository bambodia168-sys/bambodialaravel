@extends('layouts.main')
@section('title',$policy->title)
@section('content')
<div class="container inner-page policy-single-premium">
  <div class="policy-layout">
    <aside class="panel policy-aside" aria-label="Policy navigation">
      <a href="{{ route('policy-center') }}" class="policy-back policy-sidebar-back">
        <x-icon name="chevron-left" size="16"/> All policies
      </a>
      <h2>Policy directory</h2>
      @foreach($otherPolicies as $other)
        <a class="{{ $other->id === $policy->id ? 'active' : '' }}" href="{{ $other->publicUrl() }}">
          <x-icon name="file-text" size="16"/>{{ $other->title }}
        </a>
      @endforeach
    </aside>

    <article class="panel policy-article policy-formatted-content">
      <header class="policy-document-header" aria-label="Policy publication details">
        <div class="policy-single-meta">
          <span><x-icon name="shield" size="16"/> Policy ID <strong class="mono">{{ $policy->public_code }}</strong></span>
          <span><x-icon name="book-open" size="16"/> {{ ucfirst($policy->category) }}</span>
          <span><x-icon name="file-check" size="16"/> v{{ $publication?->version ?? $policy->version }}</span>
          <span><x-icon name="clock" size="16"/> Published {{ $policy->published_at?->format('d M Y') ?? $publication?->created_at?->format('d M Y') ?? 'Date unavailable' }}</span>
        </div>
      </header>

      <div class="policy-single-detail-grid">
        <div><span>Jurisdiction</span><strong>{{ $policy->jurisdiction ?: 'As specified in the policy' }}</strong></div>
        <div><span>Last updated</span><strong>{{ $policy->updated_at?->format('d M Y') ?? 'Not recorded' }}</strong></div>
        <div><span>Person in charge</span><strong><a href="mailto:{{ $policy->pic_email }}">{{ $policy->pic_email }}</a></strong></div>
      </div>

      <div class="policy-copy">{!! $policyHtml !!}</div>

      @if($publication)
        <section class="policy-reading-ack">
          <p class="text-muted"><strong>Published revision:</strong> {{ $publication->revision }} &nbsp; <strong>Version:</strong> {{ $publication->version ?? $policy->version }}</p>
          @auth
            @if($acknowledged)
              <p class="notice success">You have recorded a voluntary acknowledgement for this revision.</p>
            @else
              <form method="post" action="{{ route('policy.acknowledge',$publication) }}">
                @csrf
                <label class="check-label"><input name="confirm_read" type="checkbox" value="1" required> I have read this revision and voluntarily wish to record an acknowledgement.</label>
                <button class="btn subtle" type="submit">Record acknowledgement</button>
              </form>
            @endif
            <p><a href="{{ route('account.policy-receipts') }}">View policy receipts</a></p>
          @endauth
          <p class="text-muted">Acknowledgements are voluntary and do not replace legally required consent or contract acceptance.</p>
        </section>
      @endif

      <footer class="policy-document-footer" aria-label="Policy page actions">
        <div class="policy-single-actions">
          <button type="button" class="btn subtle" data-policy-copy-url="{{ $policy->publicUrl() }}"
                  aria-label="Copy full policy URL" title="Copy full policy URL">
            <x-icon name="copy" size="16"/> Full URL
          </button>
          <button type="button" class="btn subtle" data-policy-print>
            <x-icon name="file-text" size="16"/> Print
          </button>
          <button type="button" class="btn subtle" data-policy-share="{{ $policy->publicUrl() }}">
            <x-icon name="external-link" size="16"/> Share
          </button>
          <span class="policy-copy-feedback" aria-live="polite"></span>
        </div>
      </footer>
    </article>
  </div>
</div>
@endsection
