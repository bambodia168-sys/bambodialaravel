<details class="workspace-notifications" data-workspace-notifications="{{ route('account.notifications.tray', absolute: false) }}">
  <summary class="icon-button" title="Notifications" aria-label="Notifications, {{ $unreadCount }} unread" aria-controls="workspace-notification-tray">
    <x-icon name="bell" size="19"/>
    <span class="workspace-notifications-count" data-notification-count aria-hidden="true" @if(!$unreadCount) hidden @endif>{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
  </summary>
  @include('account.partials.notification-tray')
</details>
