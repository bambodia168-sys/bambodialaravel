@extends('layouts.main')
@section('title','Comments · '.$title)
@section('content')
<div class="container inner-page detail-workspace">
  <div class="detail-workspace-head">
    <div>
      <span class="eyebrow">Bambodia Community</span>
      <h1>Comments</h1>
      <p>Discuss <strong>{{ $title }}</strong> with other signed-in members.</p>
    </div>
    <div class="detail-workspace-navigation">
      <a class="btn subtle" href="{{ $publicUrl }}"><x-icon name="arrow-left" size="16"/> Back to {{ ucfirst($kind) }}</a>
      <a class="btn subtle" href="{{ route('detail.support',$params) }}"><x-icon name="life-buoy" size="16"/> Private Support</a>
    </div>
  </div>

  <div class="detail-workspace-grid">
    <section class="panel detail-workspace-panel" aria-labelledby="conversation-title">
      <div class="panel-head">
        <h2 id="conversation-title">Community conversation</h2>
        <span class="count">{{ $comments->total() }}</span>
      </div>
      @forelse($comments as $comment)
        <x-detail-comment :comment="$comment" :params="$params"/>
      @empty
        <div class="detail-workspace-empty">No comments yet. Start a helpful discussion about this {{ $kind }}.</div>
      @endforelse
      <x-pagination :paginator="$comments"/>
    </section>

    <aside class="panel detail-compose" aria-labelledby="new-comment-title">
      <h2 id="new-comment-title">Write a comment</h2>
      <p>Share a question, feedback or useful information. Comments can be read by signed-in members.</p>
      <form method="post" action="{{ route('detail.comments.store',$params) }}">
        @csrf
        <label for="detail-comment-text">Your comment</label>
        <textarea id="detail-comment-text" name="body" minlength="2" maxlength="2500" rows="7" required>{{ old('body') }}</textarea>
        @error('body')<p class="field-error">{{ $message }}</p>@enderror
        <p class="hint">Be respectful. You can edit or delete your own comments.</p>
        <button type="submit" class="btn primary"><x-icon name="message-square" size="16"/> Publish comment</button>
      </form>
    </aside>
  </div>
</div>
@endsection
