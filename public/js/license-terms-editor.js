document.querySelectorAll('[data-license-terms]').forEach((field) => {
    const plain = field.querySelector('[data-license-terms-plain]');
    const html = field.querySelector('[data-license-terms-html]');
    const editor = field.querySelector('[data-license-editor]');
    const surface = field.querySelector('[data-license-terms-surface]');
    const label = field.querySelector('label');
    const dialog = field.closest('dialog');
    const error = field.querySelector('[data-license-terms-error]');
    if (!plain || !html || !editor || !surface || !label || !dialog || !error || !document.execCommand) return;

    const sync = () => {
        plain.value = surface.innerText.trim();
        html.value = surface.innerHTML.trim();
        surface.removeAttribute('aria-invalid');
        error.hidden = true;
    };

    const loadPlain = () => {
        surface.replaceChildren();
        const parts = plain.value.replace(/\r\n?/g, '\n').split(/\n[ \t]*\n+/);
        parts.forEach((part) => {
            const paragraph = document.createElement('p');
            part.split('\n').forEach((line, index) => {
                if (index) paragraph.append(document.createElement('br'));
                paragraph.append(line);
            });
            surface.append(paragraph);
        });
        sync();
    };

    plain.hidden = true;
    plain.required = false;
    html.disabled = false;
    editor.hidden = false;
    label.removeAttribute('for');
    label.addEventListener('click', () => surface.focus());
    surface.addEventListener('input', sync);
    surface.addEventListener('paste', (event) => {
        if (!event.clipboardData) return;
        event.preventDefault();
        document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
        sync();
    });

    editor.querySelectorAll('[data-license-command]').forEach((button) => {
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => {
            surface.focus();
            document.execCommand(button.dataset.licenseCommand, false, button.dataset.licenseArgument);
            sync();
        });
    });

    // The shared draft presets populate the original textareas during the click.
    document.addEventListener('click', (event) => {
        if (dialog.id === 'license-create' && event.target.closest('[data-license-preset]')) {
            queueMicrotask(loadPlain);
        }
    });

    field.closest('form').addEventListener('submit', (event) => {
        sync();
        if (!/\S/.test(plain.value) || plain.value.length < 30 || plain.value.length > 50000) {
            event.preventDefault();
            surface.setAttribute('aria-invalid', 'true');
            error.hidden = false;
            if (!dialog.querySelector('[aria-invalid="true"]:focus')) surface.focus();
        }
    });
});

document.querySelector('dialog[data-license-reopen]')?.showModal();
