@extends('layouts.dashboard')
@section('title','Policy Preview · '.$policy->title)
@section('content')
<div class="page-intro">
  <div class="eyebrow">Private administrator preview</div>
  <h1>{{ $policy->title }}</h1>
  <p>Preview of the current saved editor content. This page is not public and does not publish a revision.</p>
  <a class="btn subtle" href="{{ route('dashboard.policies.edit',$policy) }}"><x-icon name="edit" size="16"/> Back to Policy Builder</a>
</div>
<article class="panel policy-preview-article">
  <div class="policy-preview-meta">Policy ID {{ $policy->public_code }} · v{{ $policy->version }} · {{ ucfirst($policy->category) }} · {{ ucfirst($policy->status) }}</div>
  <div class="policy-copy policy-formatted-content">{!! \App\Support\PolicyContent::display($policy->body, $policy->body_format) !!}</div>
</article>
@endsection
