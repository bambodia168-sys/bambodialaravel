(() => {
    const builder = document.querySelector('[data-email-builder]');
    if (!builder) return;

    const form = builder.querySelector('#email-copy');
    const draft = builder.querySelector('[data-email-draft]');
    const surface = form.querySelector('[data-email-surface]');
    const fallback = form.querySelector('[data-email-fallback]');
    const html = form.querySelector('[data-email-html]');
    const counter = form.querySelector('[data-email-counter]');
    const link = builder.querySelector('#email-builder-link');
    const linkMessage = builder.querySelector('[data-email-link-message]');
    let selection;
    let lastField = surface;

    for (const region of builder.querySelectorAll(
        '[data-email-tools], [data-email-surface], [data-email-counter], [data-email-palette], [data-email-variables], [data-email-preview]'
    )) region.hidden = false;
    fallback.hidden = true;
    fallback.disabled = true;
    form.querySelector('label[for="email-body"]').htmlFor = surface.id;

    function sync() {
        html.value = surface.innerHTML;
        const length = [...surface.innerText.trim()].length;
        counter.textContent = new Intl.NumberFormat().format(length) + ' / 6,000 characters';
    }

    function rememberSelection() {
        const current = window.getSelection();
        if (current.rangeCount && surface.contains(current.getRangeAt(0).commonAncestorContainer)) {
            selection = current.getRangeAt(0).cloneRange();
            lastField = surface;
        }
    }

    function placeCursor() {
        surface.focus();
        const current = window.getSelection();
        const range = selection && surface.contains(selection.commonAncestorContainer)
            ? selection : document.createRange();
        if (range !== selection) {
            range.selectNodeContents(surface);
            range.collapse(false);
        }
        current.removeAllRanges();
        current.addRange(range);
    }

    function command(name, value = null) {
        placeCursor();
        document.execCommand(name, false, value);
        rememberSelection();
        sync();
    }

    document.addEventListener('selectionchange', rememberSelection);
    surface.addEventListener('input', sync);
    surface.addEventListener('paste', event => {
        event.preventDefault();
        command('insertText', event.clipboardData.getData('text/plain'));
    });
    surface.addEventListener('drop', event => event.preventDefault());
    surface.addEventListener('click', event => {
        if (event.target.closest('a')) event.preventDefault();
    });
    for (const input of form.querySelectorAll('input:not([type="hidden"])')) {
        input.addEventListener('focus', () => { lastField = input; });
    }

    builder.querySelector('[data-email-format]').addEventListener('change', event => {
        command('formatBlock', event.target.value);
    });

    builder.addEventListener('pointerdown', event => {
        if (event.target.closest('[data-email-command], [data-email-element], [data-email-token]')) event.preventDefault();
    });
    builder.addEventListener('click', event => {
        const target = event.target.closest('button');
        if (!target) return;

        if (target.dataset.emailCommand) command(target.dataset.emailCommand);
        if (target.dataset.emailElement) {
            const element = target.dataset.emailElement;
            if (element === 'hr') {
                command('insertHorizontalRule');
            } else if (element === 'ul' || element === 'ol') {
                command(element === 'ul' ? 'insertUnorderedList' : 'insertOrderedList');
            } else {
                command('formatBlock', element);
            }
        }
        if (target.dataset.emailToken) {
            if (lastField === surface) {
                command('insertText', target.dataset.emailToken);
            } else {
                lastField.setRangeText(target.dataset.emailToken, lastField.selectionStart, lastField.selectionEnd, 'end');
                lastField.focus();
            }
        }
        if (target.hasAttribute('data-email-link')) {
            let url;
            try { url = new URL(link.value); } catch { /* Require a complete URL. */ }
            if (!url || url.protocol !== 'https:' || url.username || url.password || /[{}\\]/.test(link.value)) {
                linkMessage.textContent = 'Enter a valid HTTPS address.';
                return;
            }
            if (!selection || selection.collapsed) {
                linkMessage.textContent = 'Select text in the message first.';
                return;
            }
            command('createLink', url.href);
            link.value = '';
            linkMessage.textContent = 'Link inserted.';
        }
        if (target.hasAttribute('data-email-preview') && form.reportValidity()) {
            sync();
            for (const old of draft.querySelectorAll('[data-email-preview-value]')) old.remove();
            for (const [name, value] of new FormData(form)) {
                if (name.startsWith('_')) continue;
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                input.setAttribute('data-email-preview-value', '');
                draft.append(input);
            }
            draft.submit();
        }
    });
    form.addEventListener('submit', sync);
    sync();
})();
