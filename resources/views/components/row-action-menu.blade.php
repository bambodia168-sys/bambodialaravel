@props(['id', 'label' => 'this row'])
@php
    $triggerId = 'row-action-trigger-'.$id;
    $menuId = 'row-action-menu-'.$id;
@endphp
<div class="row-action-control">
  <button id="{{ $triggerId }}" type="button" class="row-action-trigger"
    popovertarget="{{ $menuId }}" aria-label="Actions for {{ $label }}"
    aria-haspopup="menu" aria-controls="{{ $menuId }}" aria-expanded="false"
    title="Actions">
    <x-icon name="ellipsis-vertical" size="19"/>
  </button>
  <div id="{{ $menuId }}" class="row-action-popover" popover="auto"
    data-row-action-menu data-row-action-anchor="{{ $triggerId }}"
    role="menu" aria-label="Actions for {{ $label }}">
    {{ $slot }}
  </div>
</div>
