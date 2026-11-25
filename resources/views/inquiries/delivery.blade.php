@php
  $deliveryStatus = $inquiry->deliveryStatus();
  $buyer = (int) auth()->id() === (int) $inquiry->client_id;
  $nextStep = match ($deliveryStatus) {
    'awaiting_requirements' => $buyer ? 'Send the creator the information needed to start.' : 'Waiting for the buyer to send requirements.',
    'in_progress' => $buyer ? 'The creator is preparing your delivery.' : 'Submit the work when it is ready for the buyer.',
    'delivered' => $buyer ? 'Review the latest delivery before accepting or requesting changes.' : 'Waiting for the buyer to review your delivery.',
    'revision_requested' => $buyer ? 'The creator can submit a revised delivery.' : 'Review the buyer’s feedback and submit the revised work.',
    'accepted' => 'The buyer accepted the delivery.',
  };
@endphp
<section class="panel" aria-labelledby="delivery-heading">
  <div class="panel-head">
    <div><h2 id="delivery-heading">Service delivery</h2><p>{{ $nextStep }}</p></div>
    <span class="role-pill">{{ \App\Models\ServiceInquiry::DELIVERY_STATUSES[$deliveryStatus] }}</span>
  </div>
  @if($buyer && $deliveryStatus === 'awaiting_requirements')
    <div class="onboarding-content">
      <form method="post" action="{{ route('inquiries.delivery.requirements', $inquiry) }}" class="creator-application-form">
        @csrf
        @if(!empty($trackingMode))<input type="hidden" name="tracking" value="1">@endif
        <label for="service-requirements">Order requirements</label>
        <textarea id="service-requirements" name="requirements" rows="5" minlength="10" maxlength="4900" required>{{ old('requirements') }}</textarea>
        @error('requirements')<p class="hint" role="alert">{{ $message }}</p>@enderror
        <button class="btn primary" type="submit">Send requirements</button>
      </form>
    </div>
  @elseif(!$buyer && in_array($deliveryStatus, ['in_progress', 'revision_requested'], true))
    <div class="onboarding-content">
      <form method="post" action="{{ route('inquiries.delivery.submit', $inquiry) }}" class="creator-application-form" enctype="multipart/form-data">
        @csrf
        @if(!empty($trackingMode))<input type="hidden" name="tracking" value="1">@endif
        <label for="delivery-notes">Delivery notes</label>
        <textarea id="delivery-notes" name="notes" rows="5" minlength="10" maxlength="5000" required>{{ old('notes') }}</textarea>
        @error('notes')<p class="hint" role="alert">{{ $message }}</p>@enderror
        <label for="delivery-artifact">Attachment (optional)</label>
        <input id="delivery-artifact" name="artifact" type="file" accept=".zip,.pdf,.pptx" aria-describedby="delivery-file-guide">
        <p class="hint" id="delivery-file-guide">ZIP, PDF, or PPTX up to 25 MB. Only the buyer and creator can download it.</p>
        @error('artifact')<p class="hint" role="alert">{{ $message }}</p>@enderror
        <button class="btn primary" type="submit">Submit delivery</button>
      </form>
    </div>
  @elseif($buyer && $deliveryStatus === 'delivered' && $currentDelivery)
    <div class="onboarding-content">
      <form method="post" action="{{ route('inquiries.delivery.decide', $inquiry) }}" class="creator-application-form">
        @csrf
        @if(!empty($trackingMode))<input type="hidden" name="tracking" value="1">@endif @method('PATCH')
        <input type="hidden" name="delivery" value="{{ $currentDelivery->id }}">
        <label for="delivery-feedback">Feedback (required for revisions)</label>
        <textarea id="delivery-feedback" name="feedback" rows="4" maxlength="5000">{{ old('feedback') }}</textarea>
        @error('feedback')<p class="hint" role="alert">{{ $message }}</p>@enderror
        <div class="panel-actions">
          <button class="btn primary" type="submit" name="decision" value="accept">Accept delivery</button>
          <button class="btn subtle" type="submit" name="decision" value="revise">Request revision</button>
        </div>
      </form>
    </div>
  @endif
  @if($deliveries->isNotEmpty())
    <div class="conversation-thread">
      @foreach($deliveries as $delivery)
        <article class="conversation-message">
          <div class="conversation-byline">
            <strong>{{ $delivery->is($currentDelivery) ? 'Latest delivery' : 'Previous delivery' }}</strong>
            <time datetime="{{ $delivery->created_at->toAtomString() }}">{{ $delivery->created_at->format('d M Y H:i') }}</time>
          </div>
          <p class="review-text">{{ $delivery->notes }}</p>
          @if($delivery->storage_path)
            <a class="btn subtle compact" href="{{ route('inquiries.delivery.download', [$inquiry, $delivery->id]) }}">Download {{ $delivery->original_filename }}</a>
          @endif
          @if($delivery->decision)
            <p><strong>{{ \App\Models\ServiceInquiry::DELIVERY_STATUSES[$delivery->decision] }}</strong></p>
          @endif
          @if($delivery->feedback)<p class="review-text">{{ $delivery->feedback }}</p>@endif
        </article>
      @endforeach
    </div>
  @endif
  <x-pagination :paginator="$deliveries"/>
</section>
