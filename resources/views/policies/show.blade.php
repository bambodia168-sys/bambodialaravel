@extends('layouts.main')
@section('title',$policy->title)
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Policy Center</div><h1>{{ $policy->title }}</h1><p>Last administrator review: {{ $policy->reviewed_at?->format('d M Y') ?? 'Not recorded' }}</p></div>
  <div class="policy-layout">
    <aside class="panel policy-aside"><h2>Policy directory</h2>
      @foreach($otherPolicies as $other)
        <a class="{{ $other->id === $policy->id ? 'active' : '' }}" href="{{ $other->publicUrl() }}"><x-icon name="file" size="17"/>{{ $other->title }}</a>
      @endforeach
    </aside>
    <article class="panel policy-article"><div class="eyebrow">{{ ucfirst($policy->category) }} @if($policy->jurisdiction) · {{ $policy->jurisdiction }} @endif</div><h2>{{ $policy->title }}</h2><div class="policy-copy">{!! nl2br(e($policy->body)) !!}</div>
      @if($publication)
        <div class="onboarding-content">
          <p><strong>Published revision:</strong> {{ $publication->revision }}</p>
          <p><strong>Publication SHA-256:</strong> <span class="mono hash-line">{{ $publication->sha256 }}</span></p>
          @auth
            @if($acknowledged)
              <p class="notice success">You have already recorded a voluntary reading acknowledgement for this revision.</p>
            @else
              <form method="post" action="{{ route('policy.acknowledge',$publication) }}">
                @csrf
                <label class="check-label"><input type="checkbox" name="confirm_read" value="1" required>
                  I confirm I have read this published revision and want to record an optional acknowledgement.</label>
                <button class="btn primary" type="submit">Record reading acknowledgement</button>
              </form>
            @endif
            <p><a href="{{ route('account.policy-receipts') }}">View my policy receipts</a></p>
          @endauth
          <p class="hint">Reading acknowledgement is voluntary. It is not blanket consent to process personal data, not a verified legal e-signature, and does not replace a jurisdiction-specific privacy basis or legal review.</p>
        </div>
      @else
        <p class="hint">This policy was published before revision receipts were enabled. Administrators must republish after legal review before optional reading acknowledgements can be recorded.</p>
      @endif
    </article>
  </div>
</div>
@endsection
