const root = document.documentElement;

function syncTheme() {
    root.dataset.theme = localStorage.getItem('bambodia-theme') ||
        (document.body?.classList.contains('dashboard-body') || document.body?.classList.contains('auth-body') ? 'dark' : 'light');
}

syncTheme();

document.addEventListener('click', (event) => {
    const themeButton = event.target.closest('[data-theme-toggle]');
    if (themeButton) {
        const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
        localStorage.setItem('bambodia-theme', next);
        root.dataset.theme = next;
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
