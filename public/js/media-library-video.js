/* Progressive enhancement for private MP4/WebM library playback.
   Uses only the already-authorized same-origin preview endpoint. */
(() => {
  const clock = (seconds) => {
    if (!Number.isFinite(seconds) || seconds < 0) return '0:00';
    const number = Math.floor(seconds);
    return Math.floor(number / 60) + ':' + String(number % 60).padStart(2, '0');
  };

  // Load thumbnails only when in/near the viewport. The persistent play glyph
  // remains useful when the video codec cannot decode a preview frame.
  const thumbs = document.querySelectorAll('[data-media-video-thumb]');
  const loadThumb = (thumb) => {
    if (thumb.dataset.loaded) return;
    thumb.dataset.loaded = '1';
    thumb.preload = 'metadata';
    thumb.src = thumb.dataset.mediaSource || '';
    thumb.addEventListener('loadedmetadata', () => {
      if (Number.isFinite(thumb.duration) && thumb.duration > 0.1) {
        try { thumb.currentTime = Math.min(0.35, thumb.duration / 8); } catch (_) {}
      }
    }, {once:true});
    thumb.load();
  };
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        loadThumb(entry.target);
        observer.unobserve(entry.target);
      });
    }, {rootMargin:'140px'});
    thumbs.forEach(thumb => observer.observe(thumb));
  } else thumbs.forEach(loadThumb);

  document.querySelectorAll('[data-media-player]').forEach(shell => {
    const video = shell.querySelector('video[data-player-video]');
    const dialog = shell.closest('dialog');
    const toggle = shell.querySelector('[data-player-toggle]');
    const overlay = shell.querySelector('[data-player-overlay]');
    const seek = shell.querySelector('[data-player-seek]');
    const timer = shell.querySelector('[data-player-clock]');
    const mute = shell.querySelector('[data-player-mute]');
    const speed = shell.querySelector('[data-player-speed]');
    const fullscreen = shell.querySelector('[data-player-fullscreen]');
    const pip = shell.querySelector('[data-player-pip]');
    const status = dialog?.querySelector('[data-player-status]');
    if (!video || !dialog || !toggle || !seek) return;

    // Native controls remain available when JavaScript is not running.
    video.controls = false;
    shell.classList.add('is-enhanced');
    if (pip && !document.pictureInPictureEnabled) pip.hidden = true;

    const sync = () => {
      const duration = Number.isFinite(video.duration) ? video.duration : 0;
      const current = Number.isFinite(video.currentTime) ? video.currentTime : 0;
      shell.classList.toggle('is-playing', !video.paused && !video.ended);
      shell.classList.toggle('is-muted', video.muted);
      seek.value = duration > 0 ? String(Math.round(current / duration * 1000)) : '0';
      if (timer) timer.textContent = clock(current) + ' / ' + clock(duration);
      toggle.setAttribute('aria-label',video.paused?'Play video':'Pause video');
      if (overlay) overlay.setAttribute('aria-label',video.paused?'Play video':'Pause video');
      if (mute) mute.setAttribute('aria-label',video.muted?'Unmute video':'Mute video');
    };
    const togglePlayback = async () => {
      if (video.paused) {
        try {
          await video.play();
          if (status) status.textContent = '';
        } catch (_) {
          if (status) status.textContent = 'Playback unavailable. Check this video format or your browser.';
        }
      } else video.pause();
      sync();
    };

    toggle.addEventListener('click',togglePlayback);
    overlay?.addEventListener('click',togglePlayback);
    video.addEventListener('click',togglePlayback);
    for (const e of ['timeupdate','durationchange','loadedmetadata','pause','play','ended','volumechange','seeking','seeked']) {
      video.addEventListener(e,sync);
    }
    video.addEventListener('error',() => {
      if (status) status.textContent = 'Unable to load this private video. Check its format and storage connection.';
      sync();
    });
    seek.addEventListener('input',() => {
      if (!Number.isFinite(video.duration) || video.duration <= 0) return;
      video.currentTime = Number(seek.value)/1000*video.duration;
      sync();
    });
    mute?.addEventListener('click',() => {video.muted=!video.muted;sync();});
    speed?.addEventListener('change',() => {
      const value=Number(speed.value);
      if ([0.75,1,1.25,1.5,2].includes(value)) video.playbackRate=value;
    });
    fullscreen?.addEventListener('click',async () => {
      try {
        if (document.fullscreenElement) await document.exitFullscreen();
        else if (shell.requestFullscreen) await shell.requestFullscreen();
        else if (typeof video.webkitEnterFullscreen==='function') video.webkitEnterFullscreen();
      } catch (_) {}
    });
    pip?.addEventListener('click',async () => {
      try {
        if (document.pictureInPictureElement===video) await document.exitPictureInPicture();
        else if (video.requestPictureInPicture) await video.requestPictureInPicture();
      } catch (_) {}
    });
    shell.addEventListener('keydown',event => {
      if (event.target?.matches('input, select, textarea, button')) return;
      if (event.key===' ' || event.key.toLowerCase()==='k') {
        event.preventDefault(); togglePlayback();
      } else if (event.key==='ArrowRight' || event.key==='ArrowLeft') {
        event.preventDefault();
        video.currentTime=Math.max(0,Math.min(video.duration||0,video.currentTime+(event.key==='ArrowRight'?5:-5)));
      }
    });
    dialog.addEventListener('close',() => {
      video.pause();
      if (document.pictureInPictureElement===video) document.exitPictureInPicture().catch(()=>{});
      if (status) status.textContent = 'Private preview. Only authorized users can watch unpublished media.';
      sync();
    });
    sync();
  });
})();
