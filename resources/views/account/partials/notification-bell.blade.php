<details class="workspace-notifications" data-workspace-notifications="{{ route('account.notifications.tray', absolute: false) }}">
  <summary class="icon-button" title="Notifications" aria-label="Notifications, {{ $unreadCount }} unread" aria-controls="workspace-notification-tray">
    <x-icon name="bell" size="19"/>
    <span class="workspace-notifications-count" data-notification-count aria-hidden="true" @if(!$unreadCount) hidden @endif>{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
  </summary>
  <section class="workspace-notification-tray" id="workspace-notification-tray" aria-labelledby="workspace-notification-title">
    <div class="workspace-notification-heading"><h2 id="workspace-notification-title">Notifications</h2></div>
    <p class="workspace-notification-empty" data-notification-placeholder aria-live="polite">Open your Notification Center to view alerts.</p>
    <a class="workspace-notification-center" href="{{ route('account.notifications') }}">All notifications <x-icon name="arrow-right" size="16"/></a>
  </section>
</details>
