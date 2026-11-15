/* Post Builder uploads to the private Media Library. */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-post-builder]');
    const panel = root?.querySelector('[data-editorial-post-media]');
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
                message('Upload a file or select one from Media Library.');
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
