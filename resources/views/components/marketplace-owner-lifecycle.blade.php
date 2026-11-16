@props(['record', 'menuId', 'manager' => null, 'triggers' => false])
@php
  $job = $record instanceof \App\Models\MarketplaceJob;
  $kind = $job ? 'job' : 'listing';
  $actions = array_intersect_key([
      'draft' => [$record->status === 'archived' ? 'Restore to draft' : 'Move to draft', 'pencil',
          'Open the editor after moving this '.$kind.' to draft. Submit the changes for review when ready.'],
      'archived' => ['Archive', 'archive', 'Hide this '.$kind.' and keep it in Archived.'],
      'deleted' => ['Delete', 'trash-2', 'Remove this '.$kind.' from the marketplace and your manager. Its history is kept.'],
  ], array_flip(\App\Support\MarketplaceLifecycle::targets($record)));
@endphp
@foreach($actions as $target => [$label, $icon, $copy])
  @php($dialogId = $menuId.'-'.$target)
  @if($triggers)
    <button type="button" data-modal-open="{{ $dialogId }}" @class(['is-danger' => $target === 'deleted'])>
      <x-icon :name="$icon" size="16"/> {{ $label }}
    </button>
  @else
    <dialog class="config-dialog" id="{{ $dialogId }}" aria-labelledby="{{ $dialogId }}-title">
      <h2 id="{{ $dialogId }}-title">{{ $label }}</h2>
      <p><strong>{{ $record->title }}</strong></p>
      <p>{{ $copy }}</p>
      <form method="post" action="{{ route($job ? 'jobs.status' : ($target === 'deleted' ? 'studio.listings.destroy' : 'studio.listings.status'), $record) }}">
        @csrf
        @method(!$job && $target === 'deleted' ? 'DELETE' : 'PATCH')
        <input type="hidden" name="status" value="{{ $target }}">
        <input type="hidden" name="expected_status" value="{{ $record->status }}">
        @if($manager)<input type="hidden" name="manager" value="{{ $manager }}">@endif
        @if($record->status === 'archived')<input type="hidden" name="from" value="archived">@endif
        <div class="dialog-actions">
          <button type="button" class="btn subtle" data-modal-close>Cancel</button>
          <button class="btn {{ $target === 'deleted' ? 'danger' : 'primary' }}" type="submit">{{ $label }}</button>
        </div>
      </form>
    </dialog>
  @endif
@endforeach
