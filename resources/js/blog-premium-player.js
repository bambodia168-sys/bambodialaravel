/* Accessible progressive controls for the single-post uploaded video player.
 * The original <video controls> stays functional if initialization fails.
 * Never autoplay, alter authorization, or change YouTube article embeds. */
const formatTime = (seconds) => {
    if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
    const total = Math.floor(seconds);
    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);
    const secs = String(total % 60).padStart(2, '0');
    return hours > 0 ? `${hours}:${String(minutes).padStart(2, '0')}:${secs}` : `${minutes}:${secs}`;
};

export function initBlogPremiumPlayers() {
    document.querySelectorAll('[data-blog-premium-player]').forEach((root) => {
        if (root.dataset.initialized === 'true') return;

        const video = root.querySelector('[data-blog-player-video]');
        const hud = root.querySelector('[data-blog-player-hud]');
        const center = root.querySelector('[data-blog-player-center]');
        const playButtons = root.querySelectorAll('[data-blog-player-toggle]');
        const playIcon = root.querySelector('[data-blog-player-play-icon]');
        const pauseIcon = root.querySelector('[data-blog-player-pause-icon]');
        const seek = root.querySelector('[data-blog-player-seek]');
        const time = root.querySelector('[data-blog-player-time]');
        const muteButton = root.querySelector('[data-blog-player-mute]');
        const volumeIcon = root.querySelector('[data-blog-player-volume-icon]');
        const mutedIcon = root.querySelector('[data-blog-player-muted-icon]');
        const volume = root.querySelector('[data-blog-player-volume]');
        const rate = root.querySelector('[data-blog-player-rate]');
        const pip = root.querySelector('[data-blog-player-pip]');
        const fullscreen = root.querySelector('[data-blog-player-fullscreen]');
        const status = root.querySelector('[data-blog-player-status]');
        if (![video, hud, center, playIcon, pauseIcon, seek, time, muteButton,
            volumeIcon, mutedIcon, volume, rate, fullscreen, status].every(Boolean) ||
            playButtons.length !== 2) return;

        const duration = () => Number.isFinite(video.duration) && video.duration > 0 ? video.duration : 0;
        const updateProgress = () => {
            const total = duration();
            const elapsed = Math.min(Math.max(video.currentTime || 0, 0), total || Infinity);
            const percent = total ? 100 * elapsed / total : 0;
            seek.disabled = total === 0;
            seek.value = String(percent);
            seek.style.setProperty('--blog-played', `${percent}%`);
            seek.setAttribute('aria-valuetext', `${formatTime(elapsed)} of ${formatTime(total)}`);
            time.textContent = `${formatTime(elapsed)} / ${formatTime(total)}`;
        };
        const updatePlayback = () => {
            const playing = !video.paused && !video.ended;
            root.classList.toggle('is-playing', playing);
            center.hidden = playing;
            playIcon.hidden = playing;
            pauseIcon.hidden = !playing;
            for (const button of playButtons) {
                button.setAttribute('aria-label', playing ? 'Pause video' : 'Play video');
            }
        };
        const updateVolume = () => {
            const muted = video.muted || video.volume === 0;
            mutedIcon.hidden = !muted;
            volumeIcon.hidden = muted;
            volume.value = String(video.volume);
            muteButton.setAttribute('aria-label', muted ? 'Unmute video' : 'Mute video');
        };
        const updateRate = () => {
            const speed = `${video.playbackRate}×`;
            rate.textContent = speed;
            rate.setAttribute('aria-label', `Playback speed: ${speed}`);
        };
        const setStatus = (message) => { status.textContent = message; };
        const togglePlay = async () => {
            if (video.paused || video.ended) {
                try {
                    if (video.ended) video.currentTime = 0;
                    await video.play();
                    setStatus('');
                } catch (_) {
                    setStatus('Playback could not start. Use your browser controls to try again.');
                    video.controls = true;
                }
            } else {
                video.pause();
            }
        };

        playButtons.forEach(button => button.addEventListener('click', togglePlay));
        video.addEventListener('click', togglePlay);
        seek.addEventListener('input', () => {
            if (duration()) video.currentTime = (Number(seek.value) / 100) * duration();
        });
        muteButton.addEventListener('click', () => { video.muted = !video.muted; });
        volume.addEventListener('input', () => {
            video.volume = Number(volume.value);
            video.muted = video.volume === 0;
        });
        rate.addEventListener('click', () => {
            const speeds = [1, 1.25, 1.5, 2];
            const next = (speeds.findIndex(value => Math.abs(value - video.playbackRate) < .01) + 1) % speeds.length;
            video.playbackRate = speeds[next];
        });

        if (pip && document.pictureInPictureEnabled && typeof video.requestPictureInPicture === 'function') {
            pip.hidden = false;
            pip.addEventListener('click', async () => {
                try {
                    if (document.pictureInPictureElement === video) {
                        await document.exitPictureInPicture();
                    } else {
                        await video.requestPictureInPicture();
                    }
                } catch (_) {
                    setStatus('Picture in picture is not available for this video.');
                }
            });
        }

        const updateFullscreen = () => {
            fullscreen.setAttribute('aria-label',
                document.fullscreenElement === root ? 'Exit fullscreen' : 'Enter fullscreen');
        };
        if (typeof root.requestFullscreen === 'function') {
            fullscreen.addEventListener('click', async () => {
                try {
                    if (document.fullscreenElement === root) {
                        await document.exitFullscreen();
                    } else {
                        await root.requestFullscreen();
                    }
                } catch (_) {
                    setStatus('Fullscreen is not available on this device.');
                }
            });
            document.addEventListener('fullscreenchange', updateFullscreen);
        } else if (typeof video.webkitEnterFullscreen === 'function') {
            fullscreen.addEventListener('click', () => video.webkitEnterFullscreen());
        } else {
            fullscreen.hidden = true;
        }

        root.addEventListener('keydown', (event) => {
            if (event.target !== root && event.target !== video) return;
            const key = event.key.toLowerCase();
            if (key === ' ' || key === 'k') {
                event.preventDefault();
                togglePlay();
            } else if (key === 'arrowright' || key === 'arrowleft') {
                if (!duration()) return;
                event.preventDefault();
                video.currentTime = Math.max(0, Math.min(duration(), video.currentTime + (key === 'arrowright' ? 5 : -5)));
            } else if (key === 'm') {
                event.preventDefault();
                video.muted = !video.muted;
            } else if (key === 'f' && !fullscreen.hidden) {
                event.preventDefault();
                fullscreen.click();
            }
        });

        ['loadedmetadata', 'durationchange', 'timeupdate', 'seeking', 'seeked', 'ended'].forEach(name => {
            video.addEventListener(name, updateProgress);
        });
        ['play', 'pause', 'ended', 'playing'].forEach(name => video.addEventListener(name, updatePlayback));
        video.addEventListener('volumechange', updateVolume);
        video.addEventListener('ratechange', updateRate);
        video.addEventListener('error', () => {
            video.controls = true;
            hud.hidden = true;
            center.hidden = true;
            root.classList.remove('is-playing');
            setStatus('This video is unavailable. Check your connection or try again.');
        });

        // Only remove browser-native controls once the complete custom UI is wired.
        root.dataset.initialized = 'true';
        hud.hidden = false;
        center.hidden = false;
        video.controls = false;
        updateProgress();
        updatePlayback();
        updateVolume();
        updateRate();
    });
}
