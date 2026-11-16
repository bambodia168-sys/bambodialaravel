@php
  use App\Support\MarketplaceManagement;

  $dialogId = 'manager-'.$type.'-'.$record->id;
  $editUrl = $canReview && MarketplaceManagement::mayEdit($record) ? MarketplaceManagement::builderUrl($record) : null;
  $transferUrl = $canTransfer && $member ? route('dashboard.marketplace.transfer', [
    'user' => $member, 'type' => $type, 'id' => $record->id,
  ]) : null;
  $actions = $canReview ? MarketplaceManagement::actions($record) : [];
  $icons = ['published' => 'check-circle', 'draft' => 'pencil', 'in_review' => 'clipboard-check',
    'pending' => 'clock', 'rejected' => 'x', 'archived' => 'archive', 'deleted' => 'trash-2'];
@endphp
<x-row-action-menu :id="$dialogId" :label="$record->title">
  <a href="{{ $previewUrl }}"><x-icon name="eye" size="16"/> View details</a>
  @if($record->status === 'published')<a href="{{ $record->publicUrl() }}"><x-icon name="external-link" size="16"/> View published page</a>@endif
  <button type="button" data-modal-open="{{ $dialogId }}"><x-icon name="settings" size="16"/> Configure</button>
  @if($editUrl)<a href="{{ $editUrl }}"><x-icon name="pencil" size="16"/> Edit</a>@endif
  @foreach($actions as $target => $label)
    <button type="button" data-modal-open="{{ $dialogId }}-{{ $target }}" @class(['is-danger' => $target === 'deleted'])>
      <x-icon :name="$icons[$target]" size="16"/> {{ $target === 'draft' ? ($record->status === 'archived' ? 'Restore to draft' : ($editUrl ? 'Move to draft' : 'Edit as draft')) : $label }}
    </button>
  @endforeach
  @if($transferUrl && $record->status !== 'deleted')<a href="{{ $transferUrl }}"><x-icon name="users" size="16"/> Change {{ $isJob ? 'client' : 'creator' }}</a>@endif
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
    @if($transferUrl && $record->status !== 'deleted')<a class="btn subtle" href="{{ $transferUrl }}">Change {{ $isJob ? 'client' : 'creator' }}</a>@endif
    @if($record->status === 'published')<a class="btn subtle" href="{{ $record->publicUrl() }}">View published listing</a>@endif
  </div>
</dialog>
@foreach($actions as $target => $label)
  <dialog class="config-dialog" id="{{ $dialogId }}-{{ $target }}" aria-labelledby="{{ $dialogId }}-{{ $target }}-title">
    <h2 id="{{ $dialogId }}-{{ $target }}-title">{{ match($target) { 'published' => 'Publish listing', 'archived' => 'Archive listing', 'deleted' => 'Delete listing', 'rejected' => 'Reject listing', default => 'Move to '.$label } }}</h2>
    <p>{{ $record->title }}</p>
    @if($target === 'deleted')<p>This removes the record from the marketplace and the owner’s active listings while keeping its history.</p>@endif
    @if($target === 'published' && $isProduct)<p>An active license and the creator’s stored cover are required.</p>@endif
    <form method="post" action="{{ route('dashboard.marketplace.status', ['type' => $type, 'publicId' => $record->public_id]) }}">
      @csrf @method('PATCH')
      <input type="hidden" name="expected_status" value="{{ $record->status }}">
      @if($target === 'draft' && !$editUrl)<input type="hidden" name="open_editor" value="1">@endif
      <div class="dialog-actions">
        <button type="button" class="btn subtle" data-modal-close>Cancel</button>
        <button class="btn {{ $target === 'deleted' ? 'danger' : 'primary' }}" type="submit" name="status" value="{{ $target }}">{{ $label }}</button>
      </div>
    </form>
  </dialog>
@endforeach
