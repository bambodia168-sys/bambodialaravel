import Plyr from 'plyr';
import 'plyr/css';
import iconUrl from '../../node_modules/plyr/dist/plyr.svg?url';

export function enhancePlyr(video, shell) {
    const selected = new Set(shell.dataset.configuredControls.split(' '));
    const controls = ['play-large', 'play', ...[
        ['rewind', 'rewind'], ['fast-forward', 'fast-forward'], ['progress', 'progress'],
        ['time', 'current-time', 'duration'], ['volume', 'mute', 'volume'],
        ['speed', 'settings'], ['pip', 'pip'], ['fullscreen', 'fullscreen'],
    ].filter(([setting]) => selected.has(setting)).flatMap(([, ...items]) => items)];
    const adjacent = video.nextSibling;
    try {
        new Plyr(video, {
            controls,
            settings: ['speed'],
            iconUrl,
            storage: { enabled: false },
            fullscreen: { enabled: selected.has('fullscreen'), container: '[data-configured-player]', fallback: true },
            keyboard: { focused: false, global: false },
        });
    } catch {
        const incompletePlayer = video.closest('.plyr');
        if (incompletePlayer?.parentElement === shell) {
            shell.insertBefore(video, adjacent?.parentNode === shell ? adjacent : incompletePlayer);
            incompletePlayer.remove();
        }
        video.controls = true;
    }
}
