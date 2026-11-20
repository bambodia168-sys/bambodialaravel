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

let plyrModule;

function initPlayer(shell) {
    const video = shell.querySelector('video');
    if (!video || shell.dataset.playerInitialized) return;
    shell.dataset.playerInitialized = 'true';
    const dialog = shell.closest('dialog');
    const plyr = shell.dataset.configuredPlayer === 'plyr';
    const recovery = shell.querySelector('[data-player-recovery]');
    let enhancementRequested = false;
    let automaticRetryUsed = false;
    let wantsPlayback = false;
    let primeRequested = false;
    let resumeTime = null;

    const showRecovery = () => {
        recovery.hidden = false;
    };
    const restorePlayback = () => {
        const position = resumeTime;
        resumeTime = null;
        if (position > 0 && Number.isFinite(video.duration)) {
            video.currentTime = Math.min(position, video.duration);
        }
        if (wantsPlayback && (!dialog || dialog.open)) video.play().catch(() => {});
    };
    const reload = (resume) => {
        resumeTime = video.currentTime || resumeTime || 0;
        wantsPlayback = resume;
        recovery.hidden = true;
        video.load();
    };
    video.addEventListener('play', () => { wantsPlayback = true; });
    video.addEventListener('pause', () => {
        if (!video.error && video.readyState >= HTMLMediaElement.HAVE_METADATA) wantsPlayback = false;
    });
    video.addEventListener('loadedmetadata', () => {
        recovery.hidden = true;
        if (resumeTime !== null) restorePlayback();
    });
    video.addEventListener('error', () => {
        if (video.error?.code === MediaError.MEDIA_ERR_NETWORK && !automaticRetryUsed && (!dialog || dialog.open)) {
            automaticRetryUsed = true;
            reload(wantsPlayback);
        } else {
            showRecovery();
        }
    });
    shell.querySelector('[data-player-retry]').addEventListener('click', () => {
        if (dialog && !dialog.open) return;
        reload(true);
        // Call play in the click handler so mobile browsers retain user activation.
        video.play().catch(() => {});
    });

    const enhance = () => {
        if (!plyr || enhancementRequested) return;
        enhancementRequested = true;
        plyrModule ??= import('./plyr-player.js').catch(error => {
            plyrModule = undefined;
            throw error;
        });
        plyrModule.then(({ enhancePlyr }) => {
            const mount = () => enhancePlyr(video, shell);
            if (video.paused) mount();
            else video.addEventListener('pause', mount, { once: true });
        }).catch(() => { video.controls = true; });
    };
    const prepare = () => {
        if (dialog && !primeRequested) {
            primeRequested = true;
            video.preload = 'metadata';
            if (video.networkState === HTMLMediaElement.NETWORK_EMPTY
                || (video.networkState === HTMLMediaElement.NETWORK_IDLE
                    && video.readyState === HTMLMediaElement.HAVE_NOTHING)) {
                video.load();
            }
        }
        enhance();
    };

    if (dialog) {
        if (dialog.open) prepare();
        const observer = new MutationObserver(() => {
            if (dialog.open) prepare();
        });
        observer.observe(dialog, { attributes: true, attributeFilter: ['open'] });
        dialog.addEventListener('close', () => {
            wantsPlayback = false;
            video.pause();
            if (document.pictureInPictureElement === video) document.exitPictureInPicture().catch(() => {});
        });
    } else {
        prepare();
    }
    if (video.error) showRecovery();
    const ad = shell.querySelector('[data-player-ad]');
    if (ad) import('./video-player-ads.js').then(({ initPlayerAd }) => initPlayerAd(video, ad)).catch(() => {});
}

export function initConfiguredPlayers() {
    document.querySelectorAll('[data-player-settings-form]').forEach(initSettings);
    document.querySelectorAll('[data-configured-player]').forEach(initPlayer);
}
