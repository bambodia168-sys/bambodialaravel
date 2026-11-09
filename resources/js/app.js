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



/* Unified native top-layer row actions. Position beside the actual trigger to
   avoid clipped menus in horizontally scrollable tables; preserve existing routes
   and dialogs. No mutation is performed by opening an action menu. */
function initializeRowActionMenus() {
    const menus = [...document.querySelectorAll('[data-row-action-menu]')];
    if (!menus.length) return;
    const menuItems = menu => [...menu.querySelectorAll('a[href],button:not([disabled])')]
        .filter(item => !item.closest('[hidden]') && getComputedStyle(item).display !== 'none');
    const place = menu => {
        const trigger = document.getElementById(menu.dataset.rowActionAnchor || '');
        if (!trigger) return;
        const box = trigger.getBoundingClientRect();
        const width = menu.offsetWidth || 210;
        const height = menu.offsetHeight || 100;
        const left = Math.max(10, Math.min(window.innerWidth - width - 10, box.right - width));
        const below = box.bottom + 6;
        const top = (below + height > window.innerHeight - 10 && box.top - height - 6 > 10)
            ? box.top - height - 6
            : Math.max(10, Math.min(below, window.innerHeight - height - 10));
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
    };
    menus.forEach(menu => {
        const trigger = document.getElementById(menu.dataset.rowActionAnchor || '');
        if (!trigger) return;
        menuItems(menu).forEach(item => item.setAttribute('role', 'menuitem'));
        // beforetoggle fires while the trigger is still active. Pre-position the
        // popover before it enters the top layer so it never flashes at (0,0).
        menu.addEventListener('beforetoggle', event => {
            if (event.newState === 'open') {
                trigger.setAttribute('aria-expanded', 'true');
                place(menu);
            }
        });
        menu.addEventListener('toggle', event => {
            const isOpen = event.newState === 'open';
            trigger.setAttribute('aria-expanded', String(isOpen));
            if (isOpen) {
                place(menu);
                requestAnimationFrame(() => {
                    if (menu.matches(':popover-open')) menuItems(menu)[0]?.focus({ preventScroll:true });
                });
            }
        });
        menu.addEventListener('click', event => {
            if (event.target.closest('a[href],button:not([disabled])')) menu.hidePopover?.();
        });
        menu.addEventListener('keydown', event => {
            if (!['ArrowDown','ArrowUp','Home','End'].includes(event.key)) return;
            const items = menuItems(menu);
            if (!items.length) return;
            event.preventDefault();
            let idx = items.indexOf(document.activeElement);
            if (event.key === 'Home') idx = 0;
            else if (event.key === 'End') idx = items.length - 1;
            else if (event.key === 'ArrowDown') idx = (idx + 1) % items.length;
            else idx = (idx - 1 + items.length) % items.length;
            items[idx]?.focus({ preventScroll:true });
        });
    });
    window.addEventListener('resize', () => menus.forEach(menu => {
        if (menu.matches(':popover-open')) place(menu);
    }));
    document.addEventListener('scroll', event => {
        // Keep the native popover anchored when mobile/table scrolling moves its row.
        // Do not dismiss a menu because the browser scrolls it into view.
        if (event.target.closest?.('[data-row-action-menu]')) return;
        menus.forEach(menu => {
            if (menu.matches(':popover-open')) place(menu);
        });
    }, true);
}
document.addEventListener('DOMContentLoaded', initializeRowActionMenus);

/* License-specific dialog focus and status presets remain unchanged. */
function initializeLicenseActionMenus() {
    document.addEventListener('click', event => {
        const action = event.target.closest('[data-license-action][data-modal-open]');
        if (!action) return;
        const targetId = action.dataset.licenseFocus;
        const selected = action.dataset.licenseSelect;
        requestAnimationFrame(() => {
            const dialog = document.getElementById(action.dataset.modalOpen);
            const field = document.getElementById(targetId);
            if (!dialog?.open || !field) return;
            if (selected) {
                const select = field.tagName === 'SELECT' ? field : field.querySelector('select[name="action"],select[name="status"]');
                if (select && [...select.options].some(o => o.value === selected)) select.value = selected;
            }
            field.scrollIntoView({block:'nearest'});
            const focusable = field.matches('input,select,textarea,button') ? field
                : field.querySelector('select,input,textarea,button,[tabindex]') || field;
            if (!focusable.hasAttribute('tabindex') && focusable === field && !field.matches('input,select,textarea,button')) {
                field.setAttribute('tabindex','-1');
            }
            focusable.focus({preventScroll:true});
        });
    });
}
document.addEventListener('DOMContentLoaded', initializeLicenseActionMenus);

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
    const sourceStatus = editor?.querySelector('[data-editor-source-status]');
    const applySourceButton = editor?.querySelector('[data-editor-action="apply-source"]');
    const resizeHandle = editor?.querySelector('[data-editor-resize-handle]');
    const readonly = studio.dataset.builderReadonly === '1';
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

    const youtubeSource = (raw) => {
        let urlText = String(raw || '').trim();
        if (urlText.startsWith('<iframe')) {
            const doc = new DOMParser().parseFromString(urlText, 'text/html');
            urlText = doc.querySelector('iframe')?.getAttribute('src') || '';
        }
        try {
            const parsed = new URL(urlText);
            if (parsed.protocol !== 'https:' || parsed.port || parsed.username || parsed.password) return null;
            const host = parsed.hostname.toLowerCase();
            let id = '';
            if (['youtu.be', 'www.youtu.be'].includes(host)) {
                id = parsed.pathname.slice(1);
            } else if (['youtube.com','www.youtube.com','m.youtube.com','youtube-nocookie.com','www.youtube-nocookie.com'].includes(host)) {
                if (parsed.pathname === '/watch') id = parsed.searchParams.get('v') || '';
                else {
                    const match = parsed.pathname.match(/^\/(embed|shorts|live)\/([A-Za-z0-9_-]{11})$/);
                    if (match) id = match[2];
                }
            }
            if (!/^[A-Za-z0-9_-]{11}$/.test(id)) return null;
            const time = parsed.searchParams.get('start') || parsed.searchParams.get('t') || '';
            const seconds = /^\d{1,5}$/.test(time) ? Number(time) : null;
            return 'https://www.youtube-nocookie.com/embed/' + id
                + (seconds && seconds <= 99999 ? '?start=' + seconds : '');
        } catch (_) {
            return null;
        }
    };
    const videoHtml = (src) =>
        '<div><iframe src="' + src + '" title="YouTube video preview" loading="lazy" '
        + 'referrerpolicy="strict-origin-when-cross-origin" '
        + 'sandbox="allow-scripts allow-same-origin allow-presentation" '
        + 'allow="encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe></div><p></p>';
    const showSourceStatus = (message, error = false) => {
        if (!sourceStatus) return;
        sourceStatus.hidden = !message;
        sourceStatus.textContent = message;
        sourceStatus.classList.toggle('studio-editor-counter-error', error);
    };
    async function applySourceHtml() {
        if (readonly || !source || !area || !html) return false;
        if (!studio.dataset.editorPreviewUrl || !form) return false;
        applySourceButton.disabled = true;
        showSourceStatus('Checking and sanitizing HTML preview…');
        try {
            const response = await fetch(studio.dataset.editorPreviewUrl, {
                method:'POST',
                headers:{
                    'Accept':'application/json',
                    'Content-Type':'application/json',
                    'X-CSRF-TOKEN': form.querySelector('[name="_token"]')?.value || '',
                },
                credentials:'same-origin',
                body:JSON.stringify({html:source.value}),
            });
            const payload = await response.json();
            if (!response.ok || typeof payload.html !== 'string') {
                throw new Error(payload.message || 'HTML could not be validated.');
            }
            // Never mount untrusted HTML directly: only render the server's
            // stripped and canonicalized allowlist output.
            area.innerHTML = payload.html;
            source.value = payload.html;
            html.value = payload.html;
            if (fallback) fallback.value = payload.text || '';
            area.hidden = false;
            source.hidden = true;
            if (applySourceButton) applySourceButton.hidden = true;
            toolbar.querySelector('[data-editor-action="source"]')?.setAttribute('aria-pressed','false');
            showSourceStatus('HTML sanitized successfully. Unsupported scripts and frames were removed.');
            updateEditor();
            return true;
        } catch (error) {
            showSourceStatus(error.message || 'Could not preview HTML. Please try again.', true);
            return false;
        } finally {
            if (applySourceButton) applySourceButton.disabled = false;
        }
    }

    if (rich) {
        area.hidden = false;
        area.contentEditable = studio.dataset.builderReadonly === '1' ? 'false' : 'true';
        fallback.hidden = true;
        fallback.required = false;
        toolbar.hidden = false;
        if (readonly) {
            toolbar.querySelectorAll('button,input,select').forEach(control => { control.disabled = true; });
        }

        area.addEventListener('paste', (event) => {
            event.preventDefault();
            const text = event.clipboardData?.getData('text/plain') || '';
            if (readonly) return;
            const video = youtubeSource(text);
            document.execCommand(video ? 'insertHTML' : 'insertText', false,
                video ? videoHtml(video) : text);
            updateEditor();
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
            button.addEventListener('click', async () => {
                const action = button.dataset.editorAction;
                if (action === 'source') {
                    if (source.hidden) {
                        updateEditor();
                        source.value = area.innerHTML;
                        source.hidden = false;
                        area.hidden = true;
                        button.setAttribute('aria-pressed', 'true');
                        if (applySourceButton) applySourceButton.hidden = readonly;
                        showSourceStatus(readonly ? 'HTML preview (read-only)' : 'Edit source and select Apply HTML to preview safely.');
                    } else if (readonly) {
                        source.hidden = true;
                        area.hidden = false;
                        button.setAttribute('aria-pressed', 'false');
                        showSourceStatus('');
                    } else {
                        await applySourceHtml();
                    }
                    return;
                }
                if (action === 'apply-source') {
                    await applySourceHtml();
                    return;
                }
                if (readonly || !source.hidden) return;
                if (action === 'rule') {
                    execute('insertHorizontalRule');
                } else if (action === 'table') {
                    execute('insertHTML', '<table><tbody><tr><th>Heading 1</th><th>Heading 2</th></tr><tr><td>Cell 1</td><td>Cell 2</td></tr></tbody></table><p></p>');
                } else if (action === 'youtube') {
                    const value = window.prompt('Paste a YouTube URL or its embed link:');
                    if (!value) return;
                    const video = youtubeSource(value);
                    if (!video) {
                        window.alert('Only valid HTTPS YouTube watch, share and embed links can be inserted.');
                        return;
                    }
                    execute('insertHTML', videoHtml(video));
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
        if (rich && !source.hidden) {
            event.preventDefault();
            showSourceStatus('Apply and preview the sanitized HTML before saving.', true);
            source.focus();
            return;
        }
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

    // Accessible lower-left drag grip. Pointer and arrow-key resizing work
    // in formatted, plain-text and HTML-source modes.
    const visibleEditor = () => !source?.hidden ? source : (rich ? area : fallback);
    resizeHandle?.addEventListener('pointerdown', (event) => {
        if (event.button !== 0) return;
        const target = visibleEditor();
        if (!target) return;
        event.preventDefault();
        const startY = event.clientY;
        const startHeight = target.getBoundingClientRect().height;
        resizeHandle.setPointerCapture(event.pointerId);
        const move = (moveEvent) => {
            const height = Math.max(240, Math.min(1100, startHeight + moveEvent.clientY - startY));
            target.style.height = height + 'px';
        };
        const end = () => {
            resizeHandle.removeEventListener('pointermove', move);
            resizeHandle.removeEventListener('pointerup', end);
            resizeHandle.removeEventListener('pointercancel', end);
        };
        resizeHandle.addEventListener('pointermove', move);
        resizeHandle.addEventListener('pointerup', end);
        resizeHandle.addEventListener('pointercancel', end);
    });
    resizeHandle?.addEventListener('keydown', (event) => {
        if (!['ArrowUp', 'ArrowDown'].includes(event.key)) return;
        event.preventDefault();
        const target = visibleEditor();
        if (!target) return;
        const delta = event.key === 'ArrowDown' ? 32 : -32;
        target.style.height = Math.max(240, Math.min(1100, target.getBoundingClientRect().height + delta)) + 'px';
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

    const featuredYoutubeWrap = studio.querySelector('[data-featured-youtube-wrap]');
    const featuredYoutubeUrl = studio.querySelector('[data-featured-youtube-url]');
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
        if(kind === 'youtube') {
            const source = youtubeSource(featuredYoutubeUrl?.value || '');
            if(source) {
                const frame=document.createElement('iframe');
                frame.className='studio-featured-youtube';
                frame.src=source;
                frame.title='Featured YouTube video preview';
                frame.loading='lazy';
                frame.referrerPolicy='strict-origin-when-cross-origin';
                frame.setAttribute('sandbox','allow-scripts allow-same-origin allow-presentation');
                frame.allow='encrypted-media; picture-in-picture; fullscreen';
                frame.allowFullscreen=true;
                mediaPreview.appendChild(frame);
                return;
            }
            const hint=document.createElement('span');
            hint.textContent='Enter a valid HTTPS YouTube video link.';
            mediaPreview.appendChild(hint);
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
        empty.textContent = kind === 'none' ? 'No featured media selected' : 'Select ' + kind + ' media above';
        mediaPreview.appendChild(empty);
    }

    function toggleMedia() {
        const kind = currentKind();
        if (chooser) chooser.hidden = !['image','animated','video'].includes(kind);
        if (colorWrap) colorWrap.hidden = kind !== 'color';
        if (featuredYoutubeWrap) featuredYoutubeWrap.hidden = kind !== 'youtube';
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
    featuredYoutubeUrl?.addEventListener('input', showMediaPreview);
    toggleMedia();

    // Terms are always re-accepted for a new submission or edit. Server-side
    // verification binds acceptance to the current, immutable policy digest.
    const termsDialog = studio.querySelector('#studio-terms-popup');
    const termsCheck = studio.querySelector('[data-builder-terms-check]');
    const openTerms = () => {
        if (termsDialog && typeof termsDialog.showModal === 'function' && !termsDialog.open) {
            termsDialog.showModal();
        }
    };
    termsCheck?.addEventListener('click', (event) => {
        if (!termsDialog?.showModal) return;
        if (termsCheck.dataset.confirmed === 'true') {
            termsCheck.dataset.confirmed = 'false';
            return; // Clicking an accepted checkbox revokes acceptance.
        }
        event.preventDefault();
        openTerms();
    });
    studio.querySelector('[data-builder-terms-open]')?.addEventListener('click', openTerms);
    studio.querySelectorAll('[data-builder-terms-close]').forEach(button => {
        button.addEventListener('click', () => termsDialog?.close());
    });
    studio.querySelector('[data-builder-terms-accept]')?.addEventListener('click', () => {
        if (termsCheck) {
            termsCheck.checked = true;
            termsCheck.dataset.confirmed = 'true';
        }
        termsDialog?.close();
    });

    const suggestedTerms = studio.querySelector('[data-suggested-terms]');
    const ownTerms = studio.querySelector('#studio-terms');
    const platformTerms = () => {
        if (!suggestedTerms) return '';
        try { return JSON.parse(suggestedTerms.textContent || '""'); } catch (_) { return ''; }
    };
    studio.querySelector('[data-use-platform-terms]')?.addEventListener('click', () => {
        if (ownTerms && !ownTerms.readOnly) ownTerms.value = platformTerms();
    });
    studio.querySelector('[data-clear-platform-terms]')?.addEventListener('click', () => {
        if (ownTerms && !ownTerms.readOnly) { ownTerms.value = ''; ownTerms.focus(); }
    });

    const gallery = studio.querySelector('[data-gallery-grid]');
    const galleryCount = studio.querySelector('[data-gallery-count]');
    const maxGallery = 8;
    function gallerySelections() {
        return Array.from(gallery?.querySelectorAll('input[name="gallery_media_ids[]"]:checked') || []);
    }
    function refreshGallery() {
        const selected = gallerySelections();
        if (galleryCount) {
            galleryCount.textContent = selected.length + ' of ' + maxGallery + ' screenshots selected. Pending files remain private until review.';
        }
        gallery?.querySelectorAll('input[name="gallery_media_ids[]"]').forEach(input => {
            if (!input.checked && selected.length >= maxGallery) input.disabled = true;
            else if (studio.dataset.builderReadonly !== '1') input.disabled = false;
        });
    }
    gallery?.addEventListener('change', (event) => {
        if (event.target?.matches('input[name="gallery_media_ids[]"]')) refreshGallery();
    });
    refreshGallery();

    // Upload directly into the existing private Media Library without losing
    // unsaved form changes. Each file is independently validated and reviewed.
    const mediaForm = studio.querySelector('[data-builder-media-form]');
    const mediaInput = studio.querySelector('[data-builder-upload-files]');
    const mediaStatus = studio.querySelector('[data-builder-upload-status]');
    if (mediaInput && typeof window.fetch === 'function') {
        mediaInput.multiple = true;
        mediaForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const files = Array.from(mediaInput.files || []);
            if (!files.length) return;
            const submit = mediaForm.querySelector('button[type="submit"]');
            if (submit) submit.disabled = true;
            let done = 0;
            const errors = [];

            for (const file of files) {
                if (mediaStatus) mediaStatus.textContent = 'Uploading ' + (done + 1) + ' of ' + files.length + ' securely…';
                const body = new FormData(mediaForm);
                body.set('image', file, file.name);
                try {
                    const response = await fetch(mediaForm.action, {
                        method: 'POST',
                        body,
                        headers: { Accept:'application/json', 'X-Requested-With':'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    const payload = await response.json();
                    if (!response.ok) {
                        const problem = Object.values(payload.errors || {}).flat()[0] || payload.message || 'Upload failed';
                        throw new Error(problem);
                    }
                    done++;
                    // Pending media can be included in screenshot selections.
                    // It will not become public until its rights review passes.
                    if (payload.mime_type?.startsWith('image/') && gallery && studio.dataset.builderReadonly !== '1') {
                        gallery.querySelector('[data-gallery-empty]')?.remove();
                        const item = document.createElement('label');
                        item.className = 'studio-gallery-item';
                        const checkbox = document.createElement('input');
                        checkbox.type = 'checkbox';
                        checkbox.name = 'gallery_media_ids[]';
                        checkbox.value = String(payload.id);
                        checkbox.checked = gallerySelections().length < maxGallery;
                        const image = document.createElement('img');
                        image.src = payload.preview_url;
                        image.alt = payload.alt_text;
                        image.loading = 'lazy';
                        const label = document.createElement('span');
                        label.textContent = payload.alt_text;
                        const review = document.createElement('small');
                        review.textContent = 'Pending review';
                        item.append(checkbox, image, label, review);
                        gallery.prepend(item);
                    }
                } catch (error) {
                    errors.push(file.name + ': ' + error.message);
                }
            }
            refreshGallery();
            if (mediaStatus) {
                mediaStatus.textContent = done + ' file(s) added to your private Media Library.'
                    + (errors.length ? ' ' + errors.join('; ') : ' Keep this page open to continue editing.');
            }
            if (submit) submit.disabled = false;
            if (!errors.length) mediaInput.value = '';
        });
    }
}
document.addEventListener('DOMContentLoaded', initializeBuilderStudio);

/* Copy only public product URLs from administrator license tables. Do not reveal secret license keys. */
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-license-copy-url]');
    if (!button) return;
    const url = button.dataset.licenseCopyUrl;
    if (!url) return;
    const oldLabel = button.getAttribute('aria-label') || button.textContent.trim();
    try {
        await navigator.clipboard.writeText(url);
        button.setAttribute('aria-label', 'Full product URL copied');
        button.title = 'Copied full product URL';
    } catch (_) {
        const field = button.closest('dialog')?.querySelector('.license-product-url-row input');
        field?.select();
        button.title = 'Select and copy the product URL';
        button.setAttribute('aria-label', 'Select and copy full product URL');
    }
    button.dataset.originalLabel = oldLabel;
});

// Admin-only drafting helpers: never automatically publish a legal definition.
// The explicit tier and review checkbox remain validated on the server.
document.addEventListener('click', (event) => {
    const preset = event.target.closest('[data-license-preset]')?.dataset.licensePreset;
    if (!['regular','extended'].includes(preset)) return;
    const dialog = document.getElementById('license-create');
    if (!dialog) return;
    const set = (id, value) => {
        const field = dialog.querySelector('#' + id);
        if (field) field.value = value;
    };
    const drafts = {
        regular: {
            code: 'BAM_REGULAR',
            name: 'Regular License',
            permissions: 'Proposed starting terms: non-exclusive permission to install and use one copy of the approved product for one end project or registered domain, subject to the exact limitations in the published license. Commercial use is permitted only if expressly authorized in the final approved terms.',
            restrictions: 'Do not resell, redistribute, sublicense, or republish the original source files as a standalone product. Do not transfer ownership or claim copyright. Further uses require specific written authorization.'
        },
        extended: {
            code: 'BAM_EXTENDED',
            name: 'Extended License',
            permissions: 'Proposed starting terms: non-exclusive permission to incorporate one copy of the approved product in an end project, including monetization only to the extent expressly permitted in the final reviewed license. This does not transfer copyright or ownership.',
            restrictions: 'Do not resell, redistribute, sublicense or publish the original source code as a competing standalone product. Additional redistribution rights must be expressly stated in the published definition. Unwritten rights are not granted.'
        }
    };
    const data = drafts[preset];
    set('license-new-tier', preset);
    set('license-new-code', data.code);
    set('license-new-name', data.name);
    set('license-new-version', '1.0.0');
    set('license-new-permissions', data.permissions);
    set('license-new-restrictions', data.restrictions);
    set('license-new-status', 'draft');
    const reviewed = dialog.querySelector('input[name="confirm_review"]');
    if (reviewed) reviewed.checked = false;
});

// Builder Studio links each tier to the administrator-approved definition
// without changing or re-publishing the underlying rights.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-license-definition-select]').forEach((select) => {
        const tier = select.dataset.licenseDefinitionSelect;
        const parent = select.closest('.studio-license-tier');
        const link = parent?.querySelector('[data-license-definition-preview="' + tier + '"]');
        const summary = parent?.querySelector('[data-license-definition-description="' + tier + '"]');
        const sync = () => {
            const option = select.selectedOptions?.[0];
            const url = option?.dataset.licenseDefinitionUrl || '';
            if (link) {
                link.hidden = !url;
                if (url) link.href = url;
                else link.removeAttribute('href');
            }
            if (summary) summary.textContent = option?.dataset.licenseDefinitionSummary || '';
        };
        select.addEventListener('change', sync);
        sync();
    });
});


/* Policy Center: accessible toolbar, secure server-sanitized editor and notices. */
document.addEventListener('DOMContentLoaded', () => {
    const policyForm = document.getElementById('policy-builder-form');
    const policyEditor = document.querySelector('[data-policy-editor]');
    if (policyForm && policyEditor) {
        const source = policyForm.querySelector('[data-policy-body]');
        const toolbar = policyForm.querySelector('.policy-editor-toolbar');
        const sync = () => {
            if (source) source.value = policyEditor.innerHTML.trim();
        };
        toolbar?.querySelectorAll('button[data-policy-command]').forEach((button) => {
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => {
                const command = button.dataset.policyCommand;
                policyEditor.focus();
                if (command === 'createLink') {
                    const selected = window.getSelection()?.toString() || '';
                    if (!selected) {
                        window.alert('Select text in the policy before adding a link.');
                        return;
                    }
                    const link = window.prompt('Enter HTTPS link', 'https://');
                    if (!link) return;
                    try {
                        const url = new URL(link);
                        if (!['https:', 'http:'].includes(url.protocol)) throw new Error('Invalid scheme');
                        document.execCommand('createLink', false, url.href);
                    } catch {
                        window.alert('Only HTTP and HTTPS links are allowed.');
                    }
                } else if (command === 'foreColor' || command === 'hiliteColor') {
                    const color = window.prompt(command === 'foreColor' ? 'Text color (#RRGGBB)' : 'Highlight color (#RRGGBB)', '#c2410c');
                    if (!color || !/^#[0-9a-fA-F]{6}$/.test(color)) return;
                    document.execCommand(command, false, color);
                } else if (command === 'table') {
                    const rows = Number.parseInt(window.prompt('Table rows (1–10)', '3'), 10);
                    const columns = Number.parseInt(window.prompt('Table columns (1–6)', '3'), 10);
                    if (!Number.isInteger(rows) || !Number.isInteger(columns) || rows < 1 || rows > 10 || columns < 1 || columns > 6) return;
                    let html = '<table><tbody>';
                    for (let row = 0; row < rows; row++) {
                        html += '<tr>';
                        for (let col = 0; col < columns; col++) html += row === 0 ? '<th>Heading</th>' : '<td>Content</td>';
                        html += '</tr>';
                    }
                    html += '</tbody></table><p></p>';
                    document.execCommand('insertHTML', false, html);
                } else if (command === 'horizontal') {
                    document.execCommand('insertHorizontalRule');
                } else if (command === 'checklist') {
                    document.execCommand('insertHTML', false, '<ul><li>☐ Checklist item</li></ul>');
                } else if (command === 'blockquote') {
                    document.execCommand('formatBlock', false, 'blockquote');
                } else if (command === 'code') {
                    document.execCommand('formatBlock', false, 'pre');
                } else {
                    document.execCommand(command, false);
                }
                sync();
            });
        });
        toolbar?.querySelector('[data-policy-block]')?.addEventListener('change', (event) => {
            policyEditor.focus();
            document.execCommand('formatBlock', false, event.target.value);
            sync();
        });
        toolbar?.querySelector('[data-policy-fullscreen]')?.addEventListener('click', () => {
            policyForm.querySelector('.policy-editor-panel')?.classList.toggle('is-fullscreen');
        });
        policyForm.addEventListener('submit', (event) => {
            sync();
            const minimum = Number.parseInt(policyForm.dataset.editorMinLength || '50', 10);
            if (!policyEditor.textContent?.trim() || policyEditor.textContent.trim().length < minimum) {
                event.preventDefault();
                policyEditor.focus();
                window.alert('Enter at least ' + minimum + ' characters of readable content.');
            }
        });
        policyEditor.addEventListener('input', sync);
    }

    document.querySelectorAll('[data-policy-copy-url]').forEach((button) => {
        button.addEventListener('click', async () => {
            const url = button.dataset.policyCopyUrl;
            if (!url) return;
            const feedback = button.closest('.policy-builder-main, .policy-single-actions')
                ?.querySelector('.policy-copy-feedback');
            try {
                if (!navigator.clipboard?.writeText) throw new Error('Clipboard unavailable');
                await navigator.clipboard.writeText(url);
                if (feedback) feedback.textContent = 'Full URL copied';
            } catch {
                window.prompt('Copy policy URL', url);
                if (feedback) feedback.textContent = 'Select the link to copy';
            }
        });
    });
    document.querySelectorAll('[data-policy-print]').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });
    document.querySelectorAll('[data-policy-share]').forEach((button) => {
        button.addEventListener('click', async () => {
            const url = button.dataset.policyShare;
            if (!url) return;
            if (navigator.share) {
                try { await navigator.share({ url }); } catch { /* user cancelled */ }
            } else if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(url);
                button.title = 'Full URL copied';
            } else {
                window.prompt('Share policy URL', url);
            }
        });
    });

    const notices = [...document.querySelectorAll('[data-policy-notice]')];
    for (const notice of notices) {
        const id = notice.dataset.policyNotice;
        try {
            if (sessionStorage.getItem('bambodia-policy-notice-' + id) === 'dismissed') continue;
        } catch { /* session storage may be unavailable */ }
        if (typeof notice.showModal === 'function') notice.showModal();
        notice.querySelectorAll('[data-policy-notice-dismiss]').forEach((button) => {
            button.addEventListener('click', () => {
                notice.close();
                try { sessionStorage.setItem('bambodia-policy-notice-' + id, 'dismissed'); } catch { /* private storage */ }
            });
        });
        break;
    }
});


/* Shared notification surface: layouts render flash once; modules can emit
   'bambodia:feedback' or use window.BambodiaFeedback.show(message,kind). */
function initializeGlobalFeedback() {
    const region = document.querySelector('[data-feedback-region]');
    if (!region) return;
    const reduced = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    const icons = {
        success: '<circle cx="12" cy="12" r="9"/><path d="m7 12 3 3 7-7"/>',
        error: '<path d="M12 7v6m0 4h.01"/><path d="M12 2 2 21h20L12 2z"/>',
        warning: '<path d="M12 7v6m0 4h.01"/><path d="M12 2 2 21h20L12 2z"/>',
        info: '<circle cx="12" cy="12" r="9"/><path d="M12 10v7M12 7h.01"/>',
    };
    function dismiss(toast) {
        if (!toast || toast.classList.contains('is-dismissing')) return;
        toast.classList.add('is-dismissing');
        window.setTimeout(() => toast.remove(), reduced ? 0 : 210);
    }
    function mount(toast) {
        if (toast.dataset.feedbackMounted === 'yes') return;
        toast.dataset.feedbackMounted = 'yes';
        const length = Number(toast.dataset.feedbackDuration || 0);
        let left = Number.isFinite(length) ? Math.max(0, length) : 0;
        let timer = null, started = 0;
        const pause = () => {
            if (timer !== null) {
                window.clearTimeout(timer);
                timer = null;
                left = Math.max(0, left - (Date.now() - started));
            }
        };
        const resume = () => {
            if (!left || timer !== null || toast.matches(':hover') || toast.contains(document.activeElement)) return;
            started = Date.now();
            timer = window.setTimeout(() => dismiss(toast), left);
        };
        toast.addEventListener('mouseenter', pause);
        toast.addEventListener('mouseleave', resume);
        toast.addEventListener('focusin', pause);
        toast.addEventListener('focusout', () => window.setTimeout(resume, 0));
        toast.querySelector('[data-feedback-dismiss]')?.addEventListener('click', () => dismiss(toast));
        resume();
    }
    region.querySelectorAll('[data-global-toast]').forEach(mount);
    function show(message, kind = 'success', options = {}) {
        if (typeof message !== 'string' || !message.trim()) return;
        kind = Object.hasOwn(icons, kind) ? kind : 'info';
        const titles = {success: 'Confirmed', error: 'Action needs attention', warning: 'Please review', info: 'Information'};
        const toast = document.createElement('section');
        toast.className = 'global-feedback-toast global-feedback-' + kind;
        toast.dataset.globalToast = '';
        toast.dataset.feedbackDuration = String(options.duration ?? (kind === 'success' ? 6500 : kind === 'info' ? 8500 : 0));
        toast.setAttribute('role', ['error', 'warning'].includes(kind) ? 'alert' : 'status');
        const icon = document.createElement('span');
        icon.className = 'global-feedback-symbol';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + icons[kind] + '</svg>';
        const copy = document.createElement('div');
        copy.className = 'global-feedback-copy';
        const heading = document.createElement('strong');
        heading.textContent = typeof options.title === 'string' && options.title.trim() ? options.title.trim() : titles[kind];
        const body = document.createElement('p');
        body.textContent = message.trim();
        copy.append(heading, body);
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'global-feedback-dismiss';
        close.dataset.feedbackDismiss = '';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>';
        toast.append(icon, copy, close);
        region.prepend(toast);
        mount(toast);
    }
    window.BambodiaFeedback = { show };
    document.addEventListener('bambodia:feedback', (event) => {
        const detail = event.detail || {};
        show(detail.message, detail.kind || 'info', detail);
    });
}
document.addEventListener('DOMContentLoaded', initializeGlobalFeedback);


/* Post Builder: one progressive featured-media workspace. Uploaded objects
   enter Media Library rights review; drafts may reference owned pending objects,
   and publication permissions are validated again by the server. */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-post-builder]');
    const panel = root?.querySelector('[data-post-media]');
    if (!panel) return;
    const mode = panel.querySelector('[data-post-feature-type]');
    const id = panel.querySelector('[data-post-featured-id]');
    const stage = panel.querySelector('[data-post-featured-stage]');
    const drop = panel.querySelector('[data-post-featured-dropzone]');
    const files = panel.querySelector('[data-post-featured-files]');
    const file = panel.querySelector('[data-post-featured-file]');
    const browse = panel.querySelector('[data-post-featured-browse]');
    const library = panel.querySelector('[data-post-featured-library]');
    const libraryControls = panel.querySelector('[data-post-featured-library-controls]');
    const select = panel.querySelector('[data-post-featured-select]');
    const rights = panel.querySelector('[data-post-featured-rights]');
    const youtube = panel.querySelector('[data-post-featured-youtube]');
    const youtubeUrl = panel.querySelector('[data-post-featured-youtube-url]');
    const colorPanel = panel.querySelector('[data-post-featured-color]');
    const color = panel.querySelector('[data-post-featured-color-input]');
    const feedback = panel.querySelector('[data-post-featured-feedback]');
    let uploading = false;

    const message = (text) => { feedback.textContent = text; };
    const clear = () => stage.replaceChildren();
    const placeholder = (text) => {
        clear();
        const label = document.createElement('span');
        label.textContent = text;
        stage.append(label);
    };
    const renderAsset = (url, mime, alt) => {
        clear();
        if (!url) return placeholder('Drop media here or choose from Media Library.');
        const element = document.createElement(mime.startsWith('video/') ? 'video' : 'img');
        element.src = url;
        if (element.tagName === 'VIDEO') {
            element.controls = true;
            element.preload = 'metadata';
            element.playsInline = true;
        } else element.alt = alt || 'Featured media preview';
        stage.append(element);
    };
    const youtubeId = (value) => {
        try {
            const url = new URL(value);
            if (url.protocol !== 'https:') return null;
            const host = url.hostname.toLowerCase();
            let key = null;
            if (['youtu.be', 'www.youtu.be'].includes(host)) key = url.pathname.slice(1);
            if (['youtube.com', 'www.youtube.com', 'm.youtube.com'].includes(host)) {
                key = url.pathname === '/watch'
                    ? url.searchParams.get('v')
                    : url.pathname.match(/^\/(?:embed|shorts)\/([A-Za-z0-9_-]{11})\/?$/)?.[1];
            }
            return /^[A-Za-z0-9_-]{11}$/.test(key || '') ? key : null;
        } catch { return null; }
    };
    const selectedOption = () => [...select.options].find(item => item.value === id.value);
    const sync = () => {
        const kind = mode.value;
        const isFile = kind === 'image' || kind === 'video';
        files.hidden = !isFile;
        youtube.hidden = kind !== 'youtube';
        colorPanel.hidden = kind !== 'color';
        drop.classList.toggle('is-interactive', isFile);
        drop.setAttribute('aria-label', isFile ? 'Drop ' + kind + ' here or click to upload' : 'Featured media preview');
        [...select.options].forEach(option => {
            if (!option.value) return;
            option.hidden = kind === 'video' ? !option.dataset.mime?.startsWith('video/') : !option.dataset.mime?.startsWith('image/');
        });
        const option = selectedOption();
        if (isFile) {
            if (option && (kind === 'video' ? option.dataset.mime?.startsWith('video/') : option.dataset.mime?.startsWith('image/'))) {
                select.value = option.value;
                renderAsset(option.dataset.mediaUrl, option.dataset.mime, option.dataset.alt);
                message('Media Library selection is ready to save.');
            } else {
                id.value = '';
                select.value = '';
                placeholder('Drag & drop a ' + kind + ' here, or click to upload.');
                message('Upload once to Media Library. You can publish with media you have the rights to use.');
            }
        } else if (kind === 'youtube') {
            id.value = '';
            const key = youtubeId(youtubeUrl.value);
            clear();
            if (key) {
                const frame = document.createElement('iframe');
                frame.src = 'https://www.youtube-nocookie.com/embed/' + key;
                frame.title = 'YouTube featured video preview';
                frame.loading = 'lazy';
                frame.referrerPolicy = 'strict-origin-when-cross-origin';
                frame.allow = 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture';
                frame.allowFullscreen = true;
                stage.append(frame);
            } else placeholder('Paste a valid HTTPS YouTube link to preview it.');
            message('Only YouTube video links are accepted.');
        } else if (kind === 'color') {
            id.value = '';
            clear();
            const swatch = document.createElement('div');
            swatch.className = 'post-featured-swatch';
            swatch.style.backgroundColor = color.value || '#2563eb';
            swatch.textContent = 'Featured color';
            stage.append(swatch);
            message('A solid color appears above the published article.');
        } else {
            id.value = '';
            placeholder('No featured media selected.');
            message('Select an option above to add media.');
        }
    };
    mode.addEventListener('change', sync);
    select.addEventListener('change', () => { id.value = select.value; sync(); });
    library.addEventListener('click', () => {
        libraryControls.hidden = !libraryControls.hidden;
        if (!libraryControls.hidden) select.focus();
    });
    youtubeUrl.addEventListener('input', sync);
    color.addEventListener('input', sync);

    const upload = async (incoming) => {
        if (!incoming || uploading) return;
        const kind = incoming.type.startsWith('image/') ? 'image'
            : incoming.type.startsWith('video/') ? 'video' : null;
        if (!kind || !['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm'].includes(incoming.type)) {
            return message('Choose JPEG, PNG, WebP, GIF, MP4 or WebM only.');
        }
        if (!rights.checked) {
            mode.value = kind;
            sync();
            return message('Confirm you own this media and have publishing rights before uploading.');
        }
        const size = incoming.type === 'image/gif' ? 8 : kind === 'video' ? 20 : 4;
        if (incoming.size > size * 1024 * 1024) return message('File exceeds the ' + size + ' MB limit.');
        mode.value = kind;
        uploading = true;
        id.value = '';
        placeholder('Uploading ' + incoming.name + ' securely…');
        message('Saving to private Media Library storage…');
        const payload = new FormData();
        payload.append('image', incoming);
        const title = incoming.name.replace(/\.[^.]+$/, '').trim();
        payload.append('alt_text', (title.length >= 4 ? title : 'Post media ' + incoming.name).slice(0, 180));
        payload.append('rights_basis', 'owned');
        payload.append('rights_holder', panel.dataset.rightsHolder || 'Bambodia');
        payload.append('rights_confirmed', '1');
        try {
            const reply = await fetch(panel.dataset.uploadUrl, {
                method: 'POST', body: payload, credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': root.querySelector('input[name="_token"]')?.value || ''
                }
            });
            const result = await reply.json();
            if (!reply.ok) throw new Error(Object.values(result.errors || {}).flat()[0] || result.message || 'Upload failed.');
            const option = new Option(incoming.name, String(result.id), true, true);
            option.dataset.mediaUrl = result.preview_url;
            option.dataset.mime = result.mime_type;
            option.dataset.alt = result.alt_text;
            select.append(option);
            id.value = String(result.id);
            select.value = id.value;
            sync();
            message('Uploaded to Media Library. Your featured media is ready to save or publish.');
        } catch (error) {
            sync();
            message(error?.message || 'Upload failed. No post media was selected.');
        } finally { uploading = false; file.value = ''; }
    };
    browse.addEventListener('click', () => file.click());
    file.addEventListener('change', () => upload(file.files?.[0]));
    drop.addEventListener('click', (event) => {
        if (event.target.closest('video,iframe')) return;
        if (['image', 'video'].includes(mode.value)) file.click();
    });
    drop.addEventListener('keydown', (event) => {
        if ((event.key === 'Enter' || event.key === ' ') && ['image', 'video'].includes(mode.value)) {
            event.preventDefault();
            file.click();
        }
    });
    drop.addEventListener('dragover', (event) => {
        event.preventDefault();
        drop.classList.add('drag-over');
    });
    drop.addEventListener('dragleave', () => drop.classList.remove('drag-over'));
    drop.addEventListener('drop', (event) => {
        event.preventDefault();
        drop.classList.remove('drag-over');
        upload(event.dataTransfer?.files?.[0]);
    });
    root.querySelector('#policy-builder-form')?.addEventListener('submit', (event) => {
        if (uploading) {
            event.preventDefault();
            message('Complete the media upload before saving this post.');
        }
    });
    sync();
});


/* Public article sharing: native sharing on supported devices, secure copy fallback. */
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-article-share]');
    if (!button) return;
    const url = button.dataset.shareUrl || window.location.href;
    const title = button.dataset.shareTitle || document.title;
    const status = button.parentElement?.querySelector('[data-article-share-status]');
    const feedback = (message) => { if (status) status.textContent = message; };
    if (navigator.share) {
        try {
            await navigator.share({ title, url });
            feedback('Shared');
            return;
        } catch (error) {
            if (error?.name === 'AbortError') return;
        }
    }
    if (navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(url);
            feedback('Link copied');
            return;
        } catch (_) { /* Offer a manual copy if clipboard permission is denied. */ }
    }
    window.prompt('Copy article link:', url);
    feedback('Share link ready');
});


/* Native mobile marketplace search panel: focus input on open and close on outside/Escape. */
document.querySelectorAll('[data-public-mobile-search]').forEach((searchMenu) => {
    searchMenu.addEventListener('toggle', () => {
        if (searchMenu.open) {
            const categoryMenu = document.querySelector('[data-public-category-menu]');
            if (categoryMenu) categoryMenu.open = false;
            searchMenu.querySelector('input[type="search"]')?.focus();
        }
    });
    document.addEventListener('click', (event) => {
        if (searchMenu.open && !searchMenu.contains(event.target)) searchMenu.open = false;
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && searchMenu.open) {
            searchMenu.open = false;
            searchMenu.querySelector('summary')?.focus();
        }
    });
});


/* Accessible public Back to Top: visible only after scrolling, reduced-motion aware. */
(() => {
    const button = document.querySelector('[data-back-to-top]');
    if (!button) return;
    const showAfter = 360;
    const footerBottom = document.querySelector('.site-footer .footer-bottom');
    let scheduled = false;
    const update = () => {
        const visible = window.scrollY > showAfter;
        // When the footer enters view, lift the button so it never covers social links.
        const footerTop = footerBottom?.getBoundingClientRect().top ?? window.innerHeight;
        const offset = visible && footerTop < window.innerHeight
            ? Math.max(17, Math.ceil(window.innerHeight - footerTop + 14))
            : 17;
        button.style.setProperty('--back-to-top-offset', offset + 'px');
        button.classList.toggle('is-visible', visible);
        button.tabIndex = visible ? 0 : -1;
        button.setAttribute('aria-hidden', visible ? 'false' : 'true');
        scheduled = false;
    };
    const onScroll = () => {
        if (scheduled) return;
        scheduled = true;
        window.requestAnimationFrame(update);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    button.addEventListener('click', () => {
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.scrollTo({ top: 0, behavior: reduceMotion ? 'instant' : 'smooth' });
    });
    update();
})();


/* Homepage Blog slider: newest cards move from right to left on advancement.
   Native scroll-snap preserves touch, keyboard and accessible links. */
(() => {
    const slider = document.querySelector('[data-home-blog-slider]');
    const track = slider?.querySelector('[data-home-blog-track]');
    const section = slider?.closest('#bambodia-blog');
    const prev = section?.querySelector('[data-home-blog-prev]');
    const next = section?.querySelector('[data-home-blog-next]');
    const position = section?.querySelector('[data-home-blog-position]');
    if (!slider || !track || !prev || !next || !position) return;
    const cards = [...track.querySelectorAll('.home-blog-card')];
    if (!cards.length) return;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let timer = null, onscreen = false, paused = false;
    let hover = false, focused = false, frame = 0;
    const step = () => cards.length >= 2
        ? cards[1].offsetLeft - cards[0].offsetLeft
        : cards[0].getBoundingClientRect().width;
    const limit = () => Math.max(0,track.scrollWidth-track.clientWidth);
    const visible = () => Math.min(cards.length,Math.max(1,Math.round(
        (track.clientWidth + 1) / Math.max(1,step())
    )));
    const state = () => {
        const max = limit();
        const per = visible();
        const index = Math.min(cards.length-per,Math.max(0,
            Math.round(track.scrollLeft/Math.max(1,step()))));
        prev.disabled = track.scrollLeft <= 2;
        next.disabled = max <= 2 || track.scrollLeft >= max-2;
        position.textContent = (index+1)+'–'+Math.min(cards.length,index+per)+' of '+cards.length;
    };
    function stop() {
        if (timer !== null) { window.clearInterval(timer); timer = null; }
    }
    const move = (direction,automatic = false) => {
        if (!automatic) { paused = true; stop(); }
        const max = limit();
        if (max <= 2) return;
        const target = direction > 0 ? Math.min(max,track.scrollLeft+step())
            : Math.max(0,track.scrollLeft-step());
        track.scrollTo({left:target,behavior:reducedMotion.matches?'instant':'smooth'});
    };
    const start = () => {
        stop();
        if (!onscreen || paused || hover || focused || reducedMotion.matches || limit() <= 2) return;
        timer = window.setInterval(() => {
            if (document.hidden) return;
            if (track.scrollLeft >= limit()-2) track.scrollTo({left:0,behavior:'instant'});
            else move(1,true);
        },6500);
    };
    prev.addEventListener('click',() => move(-1));
    next.addEventListener('click',() => move(1));
    track.addEventListener('keydown',event => {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        event.preventDefault();
        move(event.key === 'ArrowRight' ? 1 : -1);
    });
    track.addEventListener('pointerdown',event => {
        if (event.pointerType === 'touch' || event.pointerType === 'pen') {
            paused = true; stop();
        }
    },{passive:true});
    slider.addEventListener('mouseenter',() => { hover = true; stop(); });
    slider.addEventListener('mouseleave',() => { hover = false; start(); });
    section.addEventListener('focusin',() => { focused = true; stop(); });
    section.addEventListener('focusout',() => {
        focused = section.contains(document.activeElement);
        if (!focused) start();
    });
    track.addEventListener('scroll',() => {
        if (frame) return;
        frame = window.requestAnimationFrame(() => { frame = 0; state(); });
    },{passive:true});
    window.addEventListener('resize',() => { state(); start(); },{passive:true});
    reducedMotion.addEventListener?.('change',start);
    document.addEventListener('visibilitychange',() => {
        if (document.hidden) stop();
        else start();
    });
    if ('IntersectionObserver' in window) {
        const watcher = new IntersectionObserver(entries => {
            onscreen = entries.some(e=>e.isIntersecting);
            if (onscreen) start(); else stop();
        },{threshold:.12});
        watcher.observe(section);
    }
    state();
})();


/* Bambodia detail-tabs progressive navigation.
 * Separate server URLs remain directly accessible and work without JS.
 * Switching Comment / Support / Overview swaps only the detail shell,
 * preserving the global header/footer, browser history and theme. */
(() => {
    let navigationController = null;
    let serial = 0;

    async function navigateDetail(url, updateHistory = true) {
        const current = document.querySelector('[data-detail-root]');
        if (!current || !url || url.origin !== window.location.origin) {
            window.location.assign(url?.href || '/');
            return;
        }
        navigationController?.abort();
        navigationController = new AbortController();
        const requestNo = ++serial;
        current.setAttribute('aria-busy','true');
        try {
            const response = await fetch(url.pathname + url.search, {
                method:'GET',
                credentials:'same-origin',
                headers:{'Accept':'text/html','X-Requested-With':'XMLHttpRequest'},
                signal:navigationController.signal,
            });
            if (!response.ok || response.redirected) throw new Error('Navigation response unavailable');
            const doc = new DOMParser().parseFromString(await response.text(),'text/html');
            const next = doc.querySelector('[data-detail-root]');
            if (!next || requestNo !== serial) throw new Error('Detail content unavailable');
            current.replaceWith(next);
            if (updateHistory) history.pushState({bambodiaDetailTab:true},'',url.href);
            document.title = doc.title || document.title;
            const active = next.querySelector('.premium-detail-tabs a[aria-current="page"]');
            if (active) active.focus({preventScroll:true});
            if (url.hash) {
                const target = next.querySelector(url.hash);
                if (target) target.scrollIntoView({block:'start'});
                else next.scrollIntoView({block:'start'});
            } else {
                next.scrollIntoView({block:'start'});
            }
            document.dispatchEvent(new CustomEvent('bambodia:detail-tab-changed'));
        } catch (error) {
            if (error.name === 'AbortError') return;
            window.location.assign(url.href);
        } finally {
            document.querySelector('[data-detail-root]')?.removeAttribute('aria-busy');
        }
    }

    document.addEventListener('click', event => {
        const link = event.target.closest('a[data-detail-route]');
        if (!link || event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
            || link.target || link.hasAttribute('download')) return;
        const root = link.closest('[data-detail-root]');
        if (!root) return;
        const url = new URL(link.href,window.location.href);
        if (url.origin !== location.origin || !/^\/(?:detail\/|(?:product|project|service|freelance)$|jobs\/)/.test(url.pathname)) return;
        event.preventDefault();
        navigateDetail(url);
    });

    window.addEventListener('popstate', () => {
        if (!document.querySelector('[data-detail-root]')) return;
        navigateDetail(new URL(window.location.href),false);
    });

    document.addEventListener('change',event => {
        const select=event.target.closest('select[data-detail-license-tier]');
        if (!select) return;
        const panel=select.closest('.premium-price-card');
        const option=select.selectedOptions[0];
        if (!panel || !option) return;
        const price=panel.querySelector('[data-detail-license-price]');
        const summary=panel.querySelector('[data-detail-license-description]');
        const terms=panel.querySelector('[data-detail-license-terms]');
        if(price) price.textContent=option.dataset.price || 'Price on request';
        if(summary) summary.textContent=option.dataset.description || '';
        if(terms && option.dataset.termsUrl){
            const url=new URL(option.dataset.termsUrl,window.location.origin);
            if(url.origin === window.location.origin) terms.href=url.href;
        }
    });
})();


/* Lazy, consent-on-click YouTube featured player. Only validated 11-character
 * IDs from persisted listing data can be used to build the embed URL. */
document.addEventListener('click', event => {
    const link=event.target.closest('a[data-featured-youtube-play]');
    if(!link || event.button!==0 || event.metaKey || event.ctrlKey ||
       event.shiftKey || event.altKey || event.defaultPrevented) return;
    const id=link.dataset.youtubeId || '';
    if(!/^[A-Za-z0-9_-]{11}$/.test(id)) return;
    const stage=link.closest('[data-youtube-stage]');
    if(!stage) return;
    event.preventDefault();
    const frame=document.createElement('iframe');
    frame.className='premium-detail-media-content premium-detail-youtube';
    frame.src='https://www.youtube-nocookie.com/embed/'+id+'?autoplay=1';
    frame.title='Featured YouTube video';
    frame.loading='eager';
    frame.referrerPolicy='strict-origin-when-cross-origin';
    frame.setAttribute('sandbox','allow-scripts allow-same-origin allow-presentation');
    frame.setAttribute('allow','autoplay; encrypted-media; picture-in-picture; fullscreen');
    frame.allowFullscreen=true;
    stage.replaceWith(frame);
    frame.focus({preventScroll:true});
});


/* Admin-only Featured state toggle. Keep the serialized value unambiguous
 * without introducing inline scripts in any Blade template. */
document.addEventListener('change', event => {
    const checkbox=event.target.closest('.marketplace-card-feature-form input[type="checkbox"]');
    if (!checkbox) return;
    const hidden=checkbox.form?.querySelector('[name="is_featured"]');
    if (hidden) hidden.value=checkbox.checked ? '1' : '0';
});
