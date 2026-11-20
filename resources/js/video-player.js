function updateAdFields(profile) {
    const provider = profile.querySelector('[data-ad-provider]').value;
    const formats = profile.querySelector('[data-ad-format]');
    const googleTag = formats.querySelector('[value="google_tag"]');
    googleTag.hidden = googleTag.disabled = provider !== 'google';
    if (googleTag.disabled && formats.value === 'google_tag') formats.value = 'image';

    const format = formats.value;
    profile.querySelector('[data-ad-direct]').hidden = format === 'google_tag';
    profile.querySelector('[data-ad-google]').hidden = format !== 'google_tag';
    profile.querySelector('[data-ad-media-label]').textContent = format === 'google_tag' ? 'Google video ad tag' : 'Ad media URL';
    const displays = { media: format !== 'text', text: format !== 'google_tag', link: format !== 'google_tag', duration: ['image', 'text'].includes(format) };
    for (const [field, visible] of Object.entries(displays)) {
        profile.querySelector('[data-ad-field="' + field + '"]').hidden = !visible;
    }
}

function initSettings(form) {
    const select = form.querySelector('[data-player-type]');
    const profiles = form.querySelectorAll('[data-player-profile]');
    const sync = () => {
        for (const profile of profiles) {
            const active = profile.dataset.playerProfile === select.value;
            profile.hidden = profile.disabled = !active;
            if (active) updateAdFields(profile);
        }
    };
    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-player-type], [data-ad-provider], [data-ad-format]')) sync();
    });
    sync();
}

function initPlayer(shell) {
    const video = shell.querySelector('video');
    if (!video) return;
    const dialog = shell.closest('dialog');
    const plyr = shell.dataset.configuredPlayer === 'plyr';
    const enhance = () => {
        if (!plyr || shell.dataset.plyrRequested) return;
        shell.dataset.plyrRequested = 'true';
        import('./plyr-player.js').then(({ enhancePlyr }) => enhancePlyr(video, shell)).catch(() => {});
    };
    if (dialog) {
        if (dialog.open) enhance();
        if (plyr) {
            const observer = new MutationObserver(() => {
                if (!dialog.open) return;
                observer.disconnect();
                enhance();
            });
            if (!dialog.open) observer.observe(dialog, { attributes: true, attributeFilter: ['open'] });
            dialog.addEventListener('close', () => {
                video.pause();
                if (document.pictureInPictureElement === video) document.exitPictureInPicture().catch(() => {});
            });
        }
    } else {
        enhance();
    }
    const ad = shell.querySelector('[data-player-ad]');
    if (ad) import('./video-player-ads.js').then(({ initPlayerAd }) => initPlayerAd(video, ad)).catch(() => {});
}

export function initConfiguredPlayers() {
    document.querySelectorAll('[data-player-settings-form]').forEach(initSettings);
    document.querySelectorAll('[data-configured-player]').forEach(initPlayer);
}
