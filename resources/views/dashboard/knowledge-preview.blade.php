@extends('layouts.dashboard')
@section('title','Preview · '.$article->title)
@section('content')
<div class="page-intro"><div class="eyebrow">Knowledge Base · Private Preview</div>
  <h1>{{ $article->title }}</h1><p>Draft preview. Not available to public visitors until published.</p>
  <a class="btn subtle" href="{{ route('dashboard.knowledge.edit',$article) }}">Back to editor</a>
</div>
<div class="panel policy-formatted-content support-knowledge-preview">
  <div class="eyebrow">{{ \App\Support\SupportKnowledge::CATEGORIES[$article->category] ?? $article->category }}</div>
  <h2>{{ $article->title }}</h2>
  <p>{{ $article->excerpt }}</p>
  <div class="policy-copy">{!! $html !!}</div>
</div>
@endsection
