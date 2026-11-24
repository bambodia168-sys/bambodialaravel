@php
  $isAdmin = in_array(auth()->user()->role, ['admin','super_admin'], true);
  $ownerRevision = ! $management && $ticket->ownerCanRevise();
  $canAdminEdit = $canManage && $isAdmin;
  $canEdit = $ownerRevision || $canAdminEdit;
  $editUrl = $canAdminEdit
    ? route('dashboard.support.edit', $ticket->public_id)
    : route(auth()->user()->supportTicketRoutePrefix().'.edit', $ticket->public_id);
  $deleteUrl = $canAdminEdit
    ? route('dashboard.support.destroy', $ticket->public_id)
    : route(auth()->user()->supportTicketRoutePrefix().'.destroy', $ticket->public_id);
  $menuId = ($management ? 'queue-' : 'own-').$ticket->id;
@endphp
<x-row-action-menu :id="$menuId" :label="$ticket->subject">
  <a href="{{ $detailUrl }}"><x-icon name="eye" size="16"/> View</a>
  <a href="{{ $detailUrl }}" target="_blank" rel="noopener noreferrer"><x-icon name="external-link" size="16"/> Visit Link</a>
  @if($canEdit)
    <a href="{{ $editUrl }}"><x-icon name="pencil" size="16"/> Edit</a>
  @endif
  @if($canManage)
    <button type="button" data-modal-open="support-assign-{{ $menuId }}"><x-icon name="user-round-check" size="16"/> Assign to</button>
    @foreach(\App\Models\SupportTicket::STATUSES as $value => $label)
      @continue($value === $ticket->status)
      @php
        $actionLabel = match($value) {
          'resolved' => 'Resolve',
          'closed' => 'Close',
          'open' => 'Reopen',
          default => $label,
        };
      @endphp
      <form method="post" action="{{ route('dashboard.support.status', $ticket->public_id) }}">
        @csrf @method('PATCH')
        <input type="hidden" name="status" value="{{ $value }}">
        <button type="submit"><x-icon name="check-circle" size="16"/> {{ $actionLabel }}</button>
      </form>
    @endforeach
  @endif
  @if($canEdit)
    <button type="button" data-modal-open="support-delete-{{ $menuId }}"><x-icon name="trash-2" size="16"/> Delete</button>
  @endif
</x-row-action-menu>

@if($canManage)
<dialog class="config-dialog support-action-dialog" id="support-assign-{{ $menuId }}">
  <form method="post" action="{{ route('dashboard.support.assign', $ticket->public_id) }}">
    @csrf @method('PATCH')
    <h2>Assign support ticket</h2>
    <p>Ticket #{{ $ticket->public_id }} · {{ $ticket->subject }}</p>
    <label for="support-assignee-{{ $menuId }}">Support agent</label>
    <select name="assigned_to" id="support-assignee-{{ $menuId }}">
      <option value="">Unassigned</option>
      @foreach($assignees as $agent)
        <option value="{{ $agent->id }}" @selected((int)$ticket->assigned_to === (int)$agent->id)>{{ $agent->name }} ({{ str_replace('_',' ',$agent->role) }})</option>
      @endforeach
    </select>
    <div class="dialog-actions">
      <button class="btn subtle" type="button" data-modal-close>Cancel</button>
      <button class="btn primary" type="submit">Save assignment</button>
    </div>
  </form>
</dialog>
@endif

@if($canEdit)
<dialog class="config-dialog support-action-dialog" id="support-delete-{{ $menuId }}">
  <form method="post" action="{{ $deleteUrl }}">
    @csrf @method('DELETE')
    <h2>Delete support ticket?</h2>
    <p>Ticket #{{ $ticket->public_id }} will be archived and removed from the active ticket list. Its conversation is retained for audit purposes.</p>
    <div class="dialog-actions">
      <button type="button" class="btn subtle" data-modal-close>Cancel</button>
      <button type="submit" class="btn primary">Confirm delete</button>
    </div>
  </form>
</dialog>
@endif
