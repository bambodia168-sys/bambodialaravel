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
    <article class="panel policy-article"><div class="eyebrow">{{ ucfirst($policy->category) }} @if($policy->jurisdiction) · {{ $policy->jurisdiction }} @endif</div><h2>{{ $policy->title }}</h2><div class="policy-copy">{!! nl2br(e($policy->body)) !!}</div></article>
  </div>
</div>
@endsection
