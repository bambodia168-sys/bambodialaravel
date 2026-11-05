@extends('layouts.main')
@section('title',$entry->title)
@section('content')
<div class="container inner-page">
  <article class="panel editorial-page">
    <div class="eyebrow">{{ ucfirst($entry->type) }} · {{ $entry->public_id }}</div>
    <h1>{{ $entry->title }}</h1>
    <p class="text-muted">Published {{ $entry->published_at?->format('d M Y') }} @if($entry->author) · {{ $entry->author->name }} @endif</p>
    <div class="editorial-copy">{!! nl2br(e($entry->body)) !!}</div>
  </article>
</div>
@endsection
