<section class="workspace-notification-tray" id="workspace-notification-tray" aria-labelledby="workspace-notification-title" data-unread-count="{{ $unreadCount }}">
  <div class="workspace-notification-heading">
    <h2 id="workspace-notification-title">Notifications</h2>
    <span>{{ number_format($unreadCount) }} unread</span>
  </div>
  @if($unreadNotifications->isNotEmpty())
    <ul class="workspace-notification-list">
      @foreach($unreadNotifications as $notification)
        <li>
          <form action="{{ route('account.notifications.read', $notification, false) }}" method="post">
            @csrf
            @method('PATCH')
            <button type="submit">
              <span class="workspace-notification-dot" aria-hidden="true"></span>
              <span class="workspace-notification-copy">
                <strong>{{ $notification->title }}</strong>
                <span>{{ $notification->message }}</span>
                <time datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
              </span>
            </button>
          </form>
        </li>
      @endforeach
    </ul>
  @else
    <p class="workspace-notification-empty">You’re all caught up.</p>
  @endif
  <a class="workspace-notification-center" href="{{ route('account.notifications') }}">All notifications <x-icon name="arrow-right" size="16"/></a>
</section>
