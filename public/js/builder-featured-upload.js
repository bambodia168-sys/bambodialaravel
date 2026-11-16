document.addEventListener('DOMContentLoaded', () => {
    const target = document.querySelector('[data-featured-drop]');
    if (!target || typeof window.fetch !== 'function') return;

    const form = target.closest('form');
    const chooser = form.querySelector('[data-featured-media]');
    const action = target.querySelector('[data-featured-upload-action]');
    const button = action.querySelector('[data-featured-upload-button]');
    const input = action.querySelector('[data-featured-upload-input]');
    const status = form.querySelector('[data-featured-upload-status]');
    const mimeKinds = new Map([
        ['image/jpeg', 'image'], ['image/png', 'image'], ['image/webp', 'image'],
        ['image/gif', 'animated'], ['video/mp4', 'video'], ['video/webm', 'video'],
    ]);
    const extensionMimes = new Map([
        ['jpg', 'image/jpeg'], ['jpeg', 'image/jpeg'], ['png', 'image/png'], ['webp', 'image/webp'],
        ['gif', 'image/gif'], ['mp4', 'video/mp4'], ['webm', 'video/webm'],
    ]);
    let uploading = false;
    let dragDepth = 0;

    const announce = message => {
        status.hidden = false;
        status.textContent = message;
    };

    async function upload(files) {
        if (uploading || !files.length) return;
        if (files.length !== 1) {
            announce('Choose one file for featured media.');
            return;
        }
        const file = files[0];
        const mime = mimeKinds.has(file.type)
            ? file.type : extensionMimes.get(file.name.split('.').pop().toLowerCase());
        if (!mime) {
            announce('Choose a JPEG, PNG, WebP, GIF, MP4, or WebM file.');
            return;
        }
        const maxMiB = mime === 'image/gif' ? 8 : (mime.startsWith('video/') ? 20 : 4);
        if (file.size > maxMiB * 1024 * 1024) {
            announce('This format supports files up to '+maxMiB+' MB.');
            return;
        }

        const filename = file.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ').trim();
        const body = new FormData();
        body.set('_token', form.querySelector('[name="_token"]').value);
        body.set('featured_type', target.dataset.listingType);
        body.set('featured_listing', target.dataset.listingReference);
        body.set('featured_managed', target.dataset.managed);
        body.set('alt_text', filename.length >= 4 ? [...filename].slice(0, 180).join('') : 'Featured media');
        body.set('image', file);
        uploading = true;
        button.disabled = true;
        target.setAttribute('aria-busy', 'true');
        announce('Uploading featured media…');

        try {
            const response = await fetch(target.dataset.uploadUrl, {
                method: 'POST',
                body,
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const asset = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(Object.values(asset.errors || {}).flat()[0]
                    || 'Upload failed. Check your connection or sign-in and try again.');
            }
            const kind = mimeKinds.get(asset.mime_type);
            const radio = form.querySelector('[name="featured_kind"][value="'+kind+'"]');
            if (!radio || !asset.id || !asset.preview_url) throw new Error('Could not select the uploaded file.');

            const option = new Option(asset.alt_text+' · '+asset.public_id, asset.id);
            option.dataset.mediaMime = asset.mime_type;
            option.dataset.mediaUrl = asset.preview_url;
            option.dataset.mediaAlt = asset.alt_text;
            chooser.add(option);
            radio.checked = true;
            chooser.value = option.value;
            radio.dispatchEvent(new Event('change', {bubbles: true}));
            announce('Media selected. Save the listing to apply it.');
        } catch (error) {
            announce(error.message || 'The file could not be uploaded. Try again.');
        } finally {
            uploading = false;
            button.disabled = false;
            target.removeAttribute('aria-busy');
            input.value = '';
        }
    }

    action.hidden = false;
    button.addEventListener('click', () => input.click());
    input.addEventListener('change', () => {
        const files = Array.from(input.files);
        input.value = '';
        void upload(files);
    });
    form.addEventListener('submit', event => {
        if (uploading) {
            event.preventDefault();
            announce('Wait for the featured media upload to finish before saving.');
        }
    });

    const draggingFiles = event => Array.from(event.dataTransfer?.types || []).includes('Files');
    target.addEventListener('dragenter', event => {
        if (!draggingFiles(event)) return;
        event.preventDefault();
        dragDepth++;
        target.classList.add('is-dragging');
    });
    target.addEventListener('dragover', event => {
        if (!draggingFiles(event)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = uploading ? 'none' : 'copy';
    });
    target.addEventListener('dragleave', () => {
        dragDepth = Math.max(0, dragDepth - 1);
        if (!dragDepth) target.classList.remove('is-dragging');
    });
    target.addEventListener('drop', event => {
        if (!draggingFiles(event) && !event.dataTransfer?.files.length) return;
        event.preventDefault();
        dragDepth = 0;
        target.classList.remove('is-dragging');
        void upload(Array.from(event.dataTransfer.files));
    });
});
