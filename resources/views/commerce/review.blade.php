<section class="panel" aria-labelledby="order-review-heading">
  <div class="panel-head"><h2 id="order-review-heading">{{ $order->review ? 'Purchase review' : 'Review your purchase' }}</h2></div>
  <div class="onboarding-content">
    @if($order->review)
      <p><strong>{{ $order->review->rating }} out of 5</strong> · {{ $order->review->hidden_at ? 'Hidden' : 'Submitted' }}</p>
      @if($order->review->body)<p class="review-text">{{ $order->review->body }}</p>@endif
      @if($section === 'dashboard.orders' && \App\Support\UserManagementAccess::allows(auth()->user(), 'marketplace_review'))
        <form method="post" action="{{ route('dashboard.orders.review.visibility', $order->number) }}">
          @csrf @method('PATCH')
          <button class="btn subtle" type="submit" name="visible" value="{{ $order->review->hidden_at ? 1 : 0 }}">
            {{ $order->review->hidden_at ? 'Restore review' : 'Hide review' }}
          </button>
        </form>
      @endif
    @else
      <form method="post" action="{{ route('account.orders.review.store', $order->number) }}" class="creator-application-form">
        @csrf
        <label for="purchase-rating">Rating</label>
        <select id="purchase-rating" name="rating" required>
          <option value="">Choose a rating</option>
          @for($rating = 5; $rating >= 1; $rating--)
            <option value="{{ $rating }}" @selected((int) old('rating') === $rating)>{{ $rating }} out of 5</option>
          @endfor
        </select>
        @error('rating')<p class="hint" role="alert">{{ $message }}</p>@enderror
        <label for="purchase-review-body">Public review (optional)</label>
        <textarea id="purchase-review-body" name="body" maxlength="1500" rows="5" aria-describedby="purchase-review-guide">{{ old('body') }}</textarea>
        <p class="hint" id="purchase-review-guide">The listing displays your review as “Verified buyer.”</p>
        @error('body')<p class="hint" role="alert">{{ $message }}</p>@enderror
        <button class="btn primary" type="submit">Post review</button>
      </form>
    @endif
  </div>
</section>
