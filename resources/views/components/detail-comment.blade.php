@props(['comment', 'params', 'reply' => false])
<article class="detail-comment {{ $reply ? 'detail-comment--reply' : '' }}">
  <div class="detail-comment-header">
    <div class="detail-comment-author">
      <span class="detail-comment-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($comment->user?->name ?? 'M',0,1)) }}</span>
      <div>
        <strong>{{ $comment->user?->name ?? 'Former member' }}</strong>
        <time datetime="{{ $comment->created_at->toIso8601String() }}">{{ $comment->created_at->diffForHumans() }}</time>
      </div>
    </div>
    @if($comment->updated_at->gt($comment->created_at))
      <span class="detail-comment-edited">Edited</span>
    @endif
  </div>
  <div class="detail-comment-body">{!! nl2br(e($comment->body)) !!}</div>
  @auth
    <div class="detail-comment-actions">
      @if((int)auth()->id()===(int)$comment->user_id)
        <details class="detail-comment-edit">
          <summary>Edit</summary>
          <form method="post" action="{{ route('detail.comments.update',[...$params,'comment'=>$comment->id]) }}">
            @csrf @method('PATCH')
            <label for="comment-edit-{{ $comment->id }}">Edit your comment</label>
            <textarea id="comment-edit-{{ $comment->id }}" name="body" rows="3" minlength="2" maxlength="2500" required>{{ $comment->body }}</textarea>
            <button type="submit" class="btn subtle compact">Save changes</button>
          </form>
        </details>
      @endif
      @if((int)auth()->id()===(int)$comment->user_id || in_array(auth()->user()->role,['admin','super_admin'],true))
        <form method="post" action="{{ route('detail.comments.destroy',[...$params,'comment'=>$comment->id]) }}">
          @csrf @method('DELETE')
          <button class="detail-comment-delete" type="submit" aria-label="Delete this comment">Delete</button>
        </form>
      @endif
    </div>
    @if(! $reply)
      <details class="detail-comment-reply">
        <summary><x-icon name="corner-down-right" size="15"/> Reply</summary>
        <form method="post" action="{{ route('detail.comments.store',$params) }}">
          @csrf
          <input type="hidden" name="parent_id" value="{{ $comment->id }}">
          <label for="comment-reply-{{ $comment->id }}">Your reply</label>
          <textarea id="comment-reply-{{ $comment->id }}" name="body" rows="3" minlength="2" maxlength="2500" required></textarea>
          <button type="submit" class="btn subtle compact">Post reply</button>
        </form>
      </details>
    @endif
  @endauth
  @if(! $reply && $comment->replies->isNotEmpty())
    <div class="detail-comment-replies">
      @foreach($comment->replies as $response)
        <x-detail-comment :comment="$response" :params="$params" :reply="true"/>
      @endforeach
    </div>
  @endif
</article>
