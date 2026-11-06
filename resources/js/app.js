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


/* Builder Studio: rich editing, secure featured-media selection and URL sharing. */
function initializeBuilderStudio() {
    const studio = document.querySelector('[data-builder-studio]');
    if (!studio) return;

    const copy = studio.querySelector('[data-builder-copy-url]');
    const feedback = studio.querySelector('[data-copy-feedback]');
    copy?.addEventListener('click', async () => {
        const fullUrl = copy.dataset.copyUrl;
        if (!fullUrl) return;
        let successful = false;
        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(fullUrl);
                successful = true;
            }
        } catch (_) {
            successful = false;
        }
        if (!successful) {
            const input = document.createElement('textarea');
            input.value = fullUrl;
            input.style.position = 'fixed';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.select();
            successful = document.execCommand('copy');
            input.remove();
        }
        if (feedback) feedback.textContent = successful ? 'Full URL copied to clipboard.' : fullUrl;
    });

    const editor = studio.querySelector('[data-studio-editor]');
    const form = studio.querySelector('[data-builder-form]');
    const area = editor?.querySelector('[data-editor-surface]');
    const fallback = editor?.querySelector('[data-editor-fallback]');
    const html = editor?.querySelector('[data-editor-html]');
    const toolbar = editor?.querySelector('[data-editor-toolbar]');
    const counter = editor?.querySelector('[data-editor-counter]');
    const source = editor?.querySelector('[data-editor-source]');
    const rich = typeof document.execCommand === 'function' && area && fallback && html && toolbar;

    function updateEditor() {
        if (!fallback || !html) return;
        const text = rich ? (area.innerText || '').trim() : fallback.value.trim();
        if (rich) {
            fallback.value = text;
            html.value = area.innerHTML;
        }
        if (counter) {
            counter.textContent = text.length.toLocaleString() + ' / 10,000';
            counter.classList.toggle('studio-editor-counter-error', text.length > 10000);
        }
    }

    if (rich) {
        area.hidden = false;
        area.contentEditable = form?.querySelector('fieldset[disabled]') ? 'false' : 'true';
        fallback.hidden = true;
        fallback.required = false;
        toolbar.hidden = false;
        const readonly = area.contentEditable === 'false';
        if (readonly) {
            toolbar.querySelectorAll('button,input,select').forEach(control => { control.disabled = true; });
        }

        area.addEventListener('paste', (event) => {
            event.preventDefault();
            const text = event.clipboardData?.getData('text/plain') || '';
            document.execCommand('insertText', false, text);
        });
        area.addEventListener('drop', (event) => event.preventDefault());
        area.addEventListener('input', updateEditor);
        toolbar.addEventListener('mousedown', (event) => {
            if (event.target.closest('button[data-editor-command],button[data-editor-action]')) {
                event.preventDefault(); // Keep text selection when clicking formatting buttons.
            }
        });
        const execute = (command, value = null) => {
            if (readonly || !source.hidden) return;
            area.focus();
            document.execCommand(command, false, value);
            updateEditor();
        };
        toolbar.querySelectorAll('[data-editor-command]').forEach((button) => {
            button.addEventListener('click', () => execute(button.dataset.editorCommand));
        });
        toolbar.querySelector('[data-editor-block]')?.addEventListener('change', (event) => {
            execute('formatBlock', event.target.value);
        });
        toolbar.querySelectorAll('[data-editor-color]').forEach((input) => {
            input.addEventListener('change', () => {
                if (readonly || !source.hidden) return;
                document.execCommand('styleWithCSS', false, true);
                execute(input.dataset.editorColor, input.value);
            });
        });
        toolbar.querySelectorAll('[data-editor-action]').forEach((button) => {
            button.addEventListener('click', () => {
                const action = button.dataset.editorAction;
                if (action === 'source') {
                    const show = source.hidden;
                    source.hidden = !show;
                    area.hidden = show;
                    if (show) source.value = area.innerHTML;
                    button.setAttribute('aria-pressed', String(show));
                    return;
                }
                if (readonly || !source.hidden) return;
                if (action === 'rule') {
                    execute('insertHorizontalRule');
                } else if (action === 'table') {
                    execute('insertHTML', '<table><tbody><tr><th>Heading 1</th><th>Heading 2</th></tr><tr><td>Cell 1</td><td>Cell 2</td></tr></tbody></table><p></p>');
                } else if (action === 'link') {
                    const value = window.prompt('Enter the full HTTPS link:');
                    if (!value) return;
                    try {
                        const url = new URL(value);
                        if (!['https:', 'http:'].includes(url.protocol)) throw new Error('Unsafe link');
                        execute('createLink', url.href);
                    } catch (_) {
                        window.alert('Enter a valid HTTPS or HTTP address.');
                    }
                }
            });
        });
        updateEditor();
    } else {
        fallback?.addEventListener('input', updateEditor);
        updateEditor();
    }

    form?.addEventListener('submit', (event) => {
        updateEditor();
        if (!rich) return;
        const plain = fallback.value.trim();
        const minimum = Number(fallback.getAttribute('minlength') || '40');
        if (plain.length < minimum || plain.length > 10000 || html.value.length > 60000) {
            event.preventDefault();
            source.hidden = true;
            area.hidden = false;
            area.focus();
            if (feedback) feedback.textContent = 'Check description length before submitting.';
        }
    });

    const preview = studio.querySelector('[data-studio-preview]');
    studio.querySelectorAll('[data-builder-preview-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!preview) return;
            preview.hidden = !preview.hidden;
            if (!preview.hidden) {
                const title = studio.querySelector('[name="title"]');
                const previewTitle = preview.querySelector('[data-preview-title]');
                const body = preview.querySelector('[data-preview-description]');
                if (previewTitle) previewTitle.textContent = title?.value || 'Untitled';
                if (body) {
                    if (rich) body.innerHTML = area.innerHTML;
                    else body.textContent = fallback?.value || '';
                }
                preview.scrollIntoView({behavior:'smooth', block:'start'});
            }
        });
    });

    const mediaType = studio.querySelectorAll('[name="featured_kind"]');
    const mediaSelect = studio.querySelector('[data-featured-media]');
    const chooser = studio.querySelector('[data-featured-media-select]');
    const colorWrap = studio.querySelector('[data-featured-color-wrap]');
    const color = studio.querySelector('[data-featured-color]');
    const colorLabel = studio.querySelector('[data-featured-color-label]');
    const mediaPreview = studio.querySelector('[data-featured-preview]');

    function currentKind() {
        return Array.from(mediaType).find(option => option.checked)?.value || 'none';
    }
    function showMediaPreview() {
        if (!mediaPreview) return;
        const kind = currentKind();
        mediaPreview.replaceChildren();
        if (kind === 'color' && color) {
            const swatch = document.createElement('div');
            swatch.className = 'studio-color-swatch';
            swatch.style.backgroundColor = color.value;
            swatch.style.color = '#fff';
            swatch.textContent = color.value.toUpperCase();
            mediaPreview.appendChild(swatch);
            if (colorLabel) colorLabel.textContent = color.value;
            return;
        }
        const option = mediaSelect?.selectedOptions?.[0];
        if (kind !== 'none' && option?.dataset.mediaUrl) {
            const media = document.createElement(kind === 'video' ? 'video' : 'img');
            media.src = option.dataset.mediaUrl;
            if (kind === 'video') {
                media.controls = true;
                media.playsInline = true;
                media.preload = 'metadata';
            } else {
                media.alt = option.dataset.mediaAlt || 'Featured media preview';
                media.loading = 'lazy';
            }
            mediaPreview.appendChild(media);
            return;
        }
        const empty = document.createElement('span');
        empty.textContent = kind === 'none' ? 'No featured media selected' : 'Select approved ' + kind + ' media above';
        mediaPreview.appendChild(empty);
    }

    function toggleMedia() {
        const kind = currentKind();
        if (chooser) chooser.hidden = !['image','animated','video'].includes(kind);
        if (colorWrap) colorWrap.hidden = kind !== 'color';
        if (mediaSelect) {
            let selectedValid = mediaSelect.value === '';
            Array.from(mediaSelect.options).forEach((option) => {
                if (!option.value) return;
                const mime = option.dataset.mediaMime || '';
                const matches = kind === 'image'
                    ? ['image/jpeg','image/png','image/webp'].includes(mime)
                    : kind === 'animated' ? mime === 'image/gif'
                    : kind === 'video' ? ['video/mp4','video/webm'].includes(mime) : false;
                option.hidden = !matches;
                option.disabled = !matches;
                if (option.selected && !matches) selectedValid = false;
            });
            if (!selectedValid) mediaSelect.value = '';
        }
        showMediaPreview();
    }
    mediaType.forEach(input => input.addEventListener('change', toggleMedia));
    mediaSelect?.addEventListener('change', showMediaPreview);
    color?.addEventListener('input', showMediaPreview);
    toggleMedia();
}
document.addEventListener('DOMContentLoaded', initializeBuilderStudio);
