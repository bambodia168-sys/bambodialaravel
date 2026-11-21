const notifications = document.querySelector('[data-workspace-notifications]');

if (notifications) {
    const trigger = notifications.querySelector('summary');
    const badge = notifications.querySelector('[data-notification-count]');
    let refreshInProgress = false;

    async function refreshNotifications() {
        if (document.hidden || refreshInProgress) return;
        refreshInProgress = true;
        const placeholder = notifications.querySelector('[data-notification-placeholder]');
        if (notifications.open && placeholder) placeholder.textContent = 'Loading unread notifications…';
        try {
            const response = await fetch(notifications.dataset.workspaceNotifications, {
                credentials: 'same-origin',
                cache: 'no-store',
            });
            if (!response.ok || response.redirected) return;

            const fragment = new DOMParser().parseFromString(await response.text(), 'text/html');
            const tray = fragment.querySelector('#workspace-notification-tray');
            if (!tray) return;

            const count = Number(tray.dataset.unreadCount);
            trigger.setAttribute('aria-label', `Notifications, ${count} unread`);
            badge.hidden = count === 0;
            badge.textContent = count > 99 ? '99+' : count;

            const current = notifications.querySelector('#workspace-notification-tray');
            // Preserve keyboard focus when an open tray is refreshed in the background.
            if (notifications.open && !current.contains(document.activeElement)) current.replaceWith(tray);
        } catch {
            // The account page can still render the last successful tray.
        } finally {
            refreshInProgress = false;
            if (placeholder?.isConnected) placeholder.textContent = 'Open your Notification Center to view alerts.';
        }
    }

    notifications.addEventListener('toggle', () => {
        if (notifications.open) refreshNotifications();
    });
    document.addEventListener('click', event => {
        if (!notifications.contains(event.target)) notifications.open = false;
    });
    notifications.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || !notifications.open) return;
        event.preventDefault();
        notifications.open = false;
        trigger.focus();
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) {
            notifications.open = false;
            refreshNotifications();
        }
    });
    document.addEventListener('visibilitychange', refreshNotifications);
    window.setInterval(refreshNotifications, 45_000);
}
