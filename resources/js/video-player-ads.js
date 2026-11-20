let imaLoading;

function loadIma() {
    if (window.google?.ima) return Promise.resolve(window.google.ima);
    imaLoading ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://imasdk.googleapis.com/js/sdkloader/ima3.js';
        const fail = () => {
            clearTimeout(timeout);
            script.remove();
            reject(new Error('The ad provider could not be loaded.'));
        };
        const timeout = setTimeout(fail, 7000);
        script.onerror = fail;
        script.onload = () => {
            clearTimeout(timeout);
            if (window.google?.ima) resolve(window.google.ima);
            else fail();
        };
        document.head.append(script);
    }).catch((error) => {
        imaLoading = null;
        throw error;
    });
    return imaLoading;
}

function startGoogleAd(ima, video, ad, container, finish) {
    const display = new ima.AdDisplayContainer(container, video);
    const loader = new ima.AdsLoader(display);
    const type = ima.AdEvent.Type;
    let manager;
    let disposed = false;
    let timer = setTimeout(finish, 8000);
    const dispose = () => {
        if (disposed) return;
        disposed = true;
        clearTimeout(timer);
        manager?.destroy();
        loader.destroy();
        display.destroy();
    };
    try {
        display.initialize();
        loader.addEventListener(ima.AdErrorEvent.Type.AD_ERROR, finish);
        loader.addEventListener(ima.AdsManagerLoadedEvent.Type.ADS_MANAGER_LOADED, (event) => {
            if (disposed) return;
            try {
                const settings = new ima.AdsRenderingSettings();
                settings.restoreCustomPlaybackStateOnAdBreakComplete = true;
                manager = event.getAdsManager(video, settings);
                manager.addEventListener(ima.AdErrorEvent.Type.AD_ERROR, finish);
                for (const name of [type.CONTENT_RESUME_REQUESTED, type.ALL_ADS_COMPLETED]) {
                    manager.addEventListener(name, finish);
                }
                manager.addEventListener(type.CONTENT_PAUSE_REQUESTED, () => video.pause());
                manager.addEventListener(type.STARTED, () => {
                    clearTimeout(timer);
                    timer = setTimeout(finish, 120000);
                });
                manager.addEventListener(type.LOADED, (loaded) => {
                    if (!loaded.getAd().isLinear()) finish();
                });
                manager.init(container.clientWidth, container.clientHeight, ima.ViewMode.NORMAL);
                manager.setVolume(0);
                manager.start();
            } catch {
                finish();
            }
        });
        const request = new ima.AdsRequest();
        const tag = new URL(ad.dataset.adUrl);
        tag.searchParams.set('npa', '1');
        request.adTagUrl = tag.href;
        request.linearAdSlotWidth = container.clientWidth;
        request.linearAdSlotHeight = container.clientHeight;
        request.setAdWillAutoPlay(false);
        request.setAdWillPlayMuted(true);
        loader.requestAds(request);
    } catch (error) {
        dispose();
        throw error;
    }
    return dispose;
}

export function initPlayerAd(video, ad) {
    if (!video.paused || ad.dataset.ready) return;
    ad.dataset.ready = 'true';
    const play = ad.querySelector('[data-ad-play]');
    const content = ad.querySelector('[data-ad-content]');
    const container = ad.querySelector('[data-ad-media]');
    const skip = ad.querySelector('[data-ad-skip]');
    const status = ad.querySelector('[data-ad-status]');
    const format = ad.dataset.playerAd;
    let ima;
    let timer;
    let creative;
    let dispose = () => {};
    let finished = false;

    const guard = () => video.pause();
    const finish = () => {
        if (finished) return;
        finished = true;
        clearTimeout(timer);
        video.removeEventListener('play', guard);
        dispose();
        creative?.remove();
        ad.hidden = true;
        video.play().catch(() => {});
    };

    ad.hidden = false;
    video.addEventListener('play', guard);
    skip.addEventListener('click', finish);
    play.addEventListener('click', async () => {
        if (format === 'google_tag' && !ima) {
            play.disabled = true;
            status.textContent = 'Loading video ad.';
            try {
                ima = await loadIma();
            } catch {
                finish();
                return;
            }
            play.disabled = false;
            if (navigator.userActivation && !navigator.userActivation.isActive) {
                status.textContent = 'Ad ready. Select Play video to continue.';
                return;
            }
        }
        play.hidden = true;
        content.hidden = false;
        status.textContent = 'Advertisement.';
        skip.focus({ preventScroll: true });
        if (format === 'google_tag') {
            ad.dataset.googleAd = 'true';
            skip.hidden = true;
            video.removeEventListener('play', guard);
            try {
                dispose = startGoogleAd(ima, video, ad, container, finish);
                if (finished) dispose();
            } catch {
                finish();
            }
            return;
        }
        if (format === 'image' || format === 'video') {
            creative = document.createElement(format === 'image' ? 'img' : 'video');
            creative.addEventListener('error', finish, { once: true });
            if (format === 'image') {
                creative.alt = 'Advertisement';
                creative.referrerPolicy = 'no-referrer';
            } else {
                creative.playsInline = true;
                creative.muted = true;
                creative.addEventListener('ended', finish, { once: true });
            }
            creative.src = ad.dataset.adUrl;
            container.prepend(creative);
        }
        if (creative instanceof HTMLVideoElement) creative.play().catch(finish);
        timer = setTimeout(finish, format === 'video' ? 30000 : Number(ad.dataset.adSeconds) * 1000);
    });
}
