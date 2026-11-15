@php
  use App\Support\MarketplaceManagement;

  $dialogId = 'manager-'.$type.'-'.$record->id;
  $editUrl = $canReview && MarketplaceManagement::mayEdit($record) ? MarketplaceManagement::builderUrl($record) : null;
  $transferUrl = $canTransfer && $member ? route('dashboard.marketplace.transfer', [
    'user' => $member, 'type' => $type, 'id' => $record->id,
  ]) : null;
  $actions = $canReview ? MarketplaceManagement::actions($record) : [];
@endphp
<x-row-action-menu :id="$dialogId" :label="$record->title">
  <button type="button" data-modal-open="{{ $dialogId }}"><x-icon name="settings" size="16"/> Configure</button>
  @if($editUrl)<a href="{{ $editUrl }}"><x-icon name="pencil" size="16"/> Edit</a>@endif
  @foreach($actions as $target => $label)
    <button type="button" data-modal-open="{{ $dialogId }}-{{ $target }}">{{ $label }}</button>
  @endforeach
  @if($transferUrl)<a href="{{ $transferUrl }}">Change {{ $isJob ? 'client' : 'creator' }}</a>@endif
</x-row-action-menu>
<dialog class="config-dialog wide-dialog" id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-heading">
  <div class="dialog-heading">
    <h2 id="{{ $dialogId }}-heading">{{ $record->title }}</h2>
    <p>{{ $record->public_id }} · {{ $member?->name ?? 'Unavailable' }} · {{ $statusLabel }}</p>
  </div>
  @if($thumbUrl)<img class="media-dialog-preview" src="{{ $thumbUrl }}" alt="{{ $thumbnail->alt_text }}" loading="lazy">@endif
  <p class="review-text">{{ $record->description }}</p>
  @if($isProduct && $record->licenseDefinition?->status === 'active')
    <p><a href="{{ $record->licenseDefinition->publicUrl() }}">Read license definition</a></p>
  @endif
  @if($canReview && $record->status === 'published')
    <form method="post" action="{{ route($isJob ? 'dashboard.card-features.job' : 'dashboard.card-features.listing', $record) }}" class="marketplace-card-feature-form">
      @csrf @method('PATCH')
      <input type="hidden" name="is_featured" value="{{ $record->is_featured ? '1' : '0' }}">
      <label><input type="checkbox" @checked($record->is_featured)> Featured on public cards</label>
      <button class="btn subtle compact" type="submit">Save Featured status</button>
    </form>
  @endif
  <div class="dialog-actions">
    <button type="button" class="btn subtle" data-modal-close>Close</button>
    <a class="btn subtle" href="{{ $previewUrl }}">Open Builder preview</a>
    @if($editUrl)<a class="btn primary" href="{{ $editUrl }}">Edit in Builder Studio</a>@endif
    @if($transferUrl)<a class="btn subtle" href="{{ $transferUrl }}">Change {{ $isJob ? 'client' : 'creator' }}</a>@endif
    @if($record->status === 'published')<a class="btn subtle" href="{{ $record->publicUrl() }}">View published listing</a>@endif
  </div>
</dialog>
@foreach($actions as $target => $label)
  <dialog class="config-dialog" id="{{ $dialogId }}-{{ $target }}" aria-labelledby="{{ $dialogId }}-{{ $target }}-title">
    <h2 id="{{ $dialogId }}-{{ $target }}-title">{{ $target === 'published' ? 'Publish listing' : 'Move to '.$label }}</h2>
    <p>{{ $record->title }}</p>
    @if($target === 'published' && $isProduct)<p>An active license and the creator’s stored cover are required.</p>@endif
    <form method="post" action="{{ route('dashboard.marketplace.status', ['type' => $type, 'publicId' => $record->public_id]) }}">
      @csrf @method('PATCH')
      <input type="hidden" name="expected_status" value="{{ $record->status }}">
      <div class="dialog-actions">
        <button type="button" class="btn subtle" data-modal-close>Cancel</button>
        <button class="btn primary" type="submit" name="status" value="{{ $target }}">{{ $target === 'published' ? 'Publish' : 'Set '.$label }}</button>
      </div>
    </form>
  </dialog>
@endforeach
