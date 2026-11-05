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
        const label = button.querySelector('[data-theme-label]');
        if (label) label.textContent = dark ? 'Switch to light mode' : 'Switch to dark mode';
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


/* Four role-aware workspaces: persistent icon-only collapse, independently
   scrollable navigation, and a working menu search. No private data stored. */
function initializeWorkspaces() {
    const shell = document.getElementById('workspace-shell');
    const sidebar = document.getElementById('workspace-sidebar');
    const body = document.body;
    if (!shell || !sidebar || !body?.dataset.workspace) return;

    const workspace = body.dataset.workspace;
    const navigation = document.getElementById('workspace-navigation');
    const toggle = document.querySelector('[data-workspace-toggle]');
    const backdrop = document.querySelector('[data-workspace-backdrop]');
    const search = document.querySelector('[data-workspace-search]');
    const mobile = window.matchMedia('(max-width:850px)');
    const collapseKey = 'bambodia-workspace:' + workspace + ':collapsed';
    const scrollKey = 'bambodia-workspace:' + workspace + ':sidebar-scroll';

    const collapse = (value) => {
        body.classList.toggle('workspace-collapsed', value);
        if (!mobile.matches) localStorage.setItem(collapseKey, value ? '1' : '0');
        toggle?.setAttribute('aria-expanded', String(mobile.matches
            ? body.classList.contains('workspace-mobile-open')
            : !value));
        toggle?.setAttribute('aria-label', value ? 'Expand navigation' : 'Collapse navigation');
        toggle?.setAttribute('title', value ? 'Show full menu' : 'Show icons only');
    };

    const closeMobile = () => {
        body.classList.remove('workspace-mobile-open');
        if (mobile.matches) toggle?.setAttribute('aria-expanded', 'false');
    };

    const synchronize = () => {
        if (mobile.matches) {
            body.classList.remove('workspace-collapsed');
            closeMobile();
            toggle?.setAttribute('aria-label', 'Open navigation');
        } else {
            body.classList.remove('workspace-mobile-open');
            collapse(localStorage.getItem(collapseKey) === '1');
        }
    };

    toggle?.addEventListener('click', () => {
        if (mobile.matches) {
            const opening = !body.classList.contains('workspace-mobile-open');
            body.classList.toggle('workspace-mobile-open', opening);
            toggle.setAttribute('aria-expanded', String(opening));
            toggle.setAttribute('aria-label', opening ? 'Close navigation' : 'Open navigation');
            if (opening) sidebar.querySelector('a.active')?.focus({preventScroll:true});
        } else {
            collapse(!body.classList.contains('workspace-collapsed'));
        }
    });

    backdrop?.addEventListener('click', closeMobile);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && body.classList.contains('workspace-mobile-open')) {
            closeMobile();
            toggle?.focus();
        }
    });

    const allGroups = [...(navigation?.querySelectorAll('.workspace-nav-group') || [])];
    const links = [...(navigation?.querySelectorAll('.workspace-nav-link') || [])];
    const noResults = navigation?.querySelector('.workspace-empty-search');

    const filterMenu = (text) => {
        const query = text.toLocaleLowerCase().trim();
        let shown = 0;
        allGroups.forEach((group) => {
            const entries = [...group.querySelectorAll('.workspace-nav-link')];
            let groupMatches = 0;
            entries.forEach((link) => {
                const match = !query || link.textContent.toLocaleLowerCase().includes(query);
                link.hidden = !match;
                if (match) groupMatches++;
            });
            group.hidden = groupMatches === 0;
            shown += groupMatches;
        });
        if (noResults) noResults.hidden = shown !== 0;
        if (query && shown !== 0) sidebar.scrollTo({top:0,behavior:'instant'});
    };

    search?.addEventListener('input', () => filterMenu(search.value));
    search?.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter') return;
        const visible = links.find((link) => !link.hidden && !link.closest('.workspace-nav-group')?.hidden);
        if (visible) {
            event.preventDefault();
            window.location.assign(visible.href);
        }
    });

    sidebar.addEventListener('scroll', () => {
        sessionStorage.setItem(scrollKey, String(Math.round(sidebar.scrollTop)));
    }, {passive:true});

    navigation?.addEventListener('click', (event) => {
        const link = event.target.closest('a.workspace-nav-link');
        if (link) sessionStorage.setItem(scrollKey, String(Math.round(sidebar.scrollTop)));
    });

    const saved = Number(sessionStorage.getItem(scrollKey) || '0');
    if (Number.isFinite(saved) && saved > 0) {
        sidebar.scrollTo({top:saved,behavior:'instant'});
    } else {
        // Scroll the sidebar alone, never the page/main workspace panel.
        const active = sidebar.querySelector('.workspace-nav-link.active');
        if (active) {
            const top = active.getBoundingClientRect().top;
            const bottom = active.getBoundingClientRect().bottom;
            const frame = sidebar.getBoundingClientRect();
            if (top < frame.top + 20 || bottom > frame.bottom - 20)
                sidebar.scrollTop += top - frame.top - 110;
        }
    }

    synchronize();
    mobile.addEventListener('change', synchronize);
}

document.addEventListener('DOMContentLoaded', initializeWorkspaces);


/* The real category grid uses native <details>, with convenient dismissal.
   Keep focus and account/language dropdown behaviors independent. */
document.addEventListener('click', (event) => {
    const categoryMenu = document.querySelector('[data-public-category-menu]');
    if (categoryMenu?.open && !categoryMenu.contains(event.target)) {
        categoryMenu.open = false;
    }
});
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    const categoryMenu = document.querySelector('[data-public-category-menu]');
    if (categoryMenu?.open) {
        categoryMenu.open = false;
        categoryMenu.querySelector('summary')?.focus();
    }
});
