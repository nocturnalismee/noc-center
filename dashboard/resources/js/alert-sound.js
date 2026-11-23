const STORAGE_KEY = 'noc-alert-sound';
const DEFAULT_VOLUME = 0.55;

function isEnabled() {
    try {
        return localStorage.getItem(STORAGE_KEY) !== 'off';
    } catch (error) {
        return true;
    }
}

function remember(enabled) {
    try {
        localStorage.setItem(STORAGE_KEY, enabled ? 'on' : 'off');
    } catch (error) {
        // Storage can be unavailable; the in-page preference still changes.
    }
}

export function initAlertSound() {
    const root = document.querySelector('[data-alert-sound]');

    if (!root || root.dataset.alertSoundReady === 'true') {
        return;
    }

    root.dataset.alertSoundReady = 'true';

    const audio = root.querySelector('[data-alert-sound-audio]');
    const toggle = root.querySelector('[data-alert-sound-toggle]');
    const onIcon = root.querySelector('[data-alert-sound-on]');
    const offIcon = root.querySelector('[data-alert-sound-off]');

    if (!audio || !toggle) {
        return;
    }

    audio.volume = DEFAULT_VOLUME;

    let enabled = isEnabled();
    let unlocked = false;

    const paint = () => {
        toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        toggle.setAttribute('aria-label', enabled ? 'Mute alert sound' : 'Unmute alert sound');

        if (onIcon) {
            onIcon.hidden = !enabled;
        }

        if (offIcon) {
            offIcon.hidden = enabled;
        }
    };

    const play = () => {
        if (!enabled) {
            return;
        }

        audio.currentTime = 0;
        audio.volume = DEFAULT_VOLUME;

        const attempt = audio.play();

        if (attempt && typeof attempt.catch === 'function') {
            attempt.catch(() => {
                // Autoplay policy still blocked playback; the next gesture retries.
            });
        }
    };

    const unlock = () => {
        if (unlocked) {
            return;
        }

        unlocked = true;

        const wasMuted = audio.muted;

        audio.muted = true;

        const attempt = audio.play();

        const restore = () => {
            audio.pause();
            audio.currentTime = 0;
            audio.muted = wasMuted;
        };

        if (attempt && typeof attempt.then === 'function') {
            attempt.then(restore).catch(restore);
        } else {
            restore();
        }
    };

    document.addEventListener('pointerdown', unlock, { once: true, passive: true });
    document.addEventListener('keydown', unlock, { once: true });

    toggle.addEventListener('click', (event) => {
        event.preventDefault();

        enabled = !enabled;
        remember(enabled);
        paint();
    });

    window.addEventListener('alert-created', play);

    paint();
}
