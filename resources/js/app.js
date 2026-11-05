const root = document.documentElement;

function syncTheme() {
    root.dataset.theme = localStorage.getItem('bambodia-theme') ||
        root.dataset.theme ||
        (document.body?.classList.contains('dashboard-body') || document.body?.classList.contains('auth-body') ? 'dark' : 'light');
}

syncTheme();

function syncThemeButtons() {
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        const dark = root.dataset.theme === 'dark';
        button.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
        button.setAttribute('aria-pressed', dark ? 'true' : 'false');
        button.title = dark ? 'Light mode' : 'Dark mode';
    });
}
document.addEventListener('DOMContentLoaded', syncThemeButtons);

document.addEventListener('click', (event) => {
    const themeButton = event.target.closest('[data-theme-toggle]');
    if (themeButton) {
        const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
        localStorage.setItem('bambodia-theme', next);
        root.dataset.theme = next;
        syncThemeButtons();
    }

    const copyButton = event.target.closest('[data-copy-target]');
    if (copyButton) {
        const source = document.getElementById(copyButton.dataset.copyTarget);
        const secret = source?.textContent?.trim();
        if (secret && navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(secret)
                .then(() => { copyButton.textContent = 'Copied'; })
                .catch(() => { copyButton.textContent = 'Select and copy manually'; });
        } else {
            copyButton.textContent = 'Select and copy manually';
        }
    }

    const modalOpen = event.target.closest('[data-modal-open]');
    if (modalOpen) {
        const dialog = document.getElementById(modalOpen.dataset.modalOpen);
        if (dialog instanceof HTMLDialogElement) dialog.showModal();
    }

    const modalClose = event.target.closest('[data-modal-close]');
    if (modalClose) modalClose.closest('dialog')?.close();

    if (event.target.closest('[data-menu-toggle]')) {
        document.body.classList.toggle('menu-open');
    }
});

document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
});
