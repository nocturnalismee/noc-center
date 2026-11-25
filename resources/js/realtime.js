import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

let echo = null;
let status = 'unavailable';

function realtimeConfig() {
    return window.__NOC_REALTIME__ ?? {};
}

function setStatus(next) {
    status = next;

    document.documentElement.setAttribute('data-realtime-status', next);
}

export function bootRealtime() {
    const config = realtimeConfig();

    if (!config.enabled || !config.key || !config.host) {
        window.NocRealtime = {
            enabled: false,
            subscribe: () => () => {},
            get status() {
                return status;
            },
        };

        setStatus('unavailable');

        return null;
    }

    window.Pusher = Pusher;
    setStatus('connecting');

    echo = new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        disableStats: true,
    });

    echo.connector?.pusher?.connection?.bind('connected', () => setStatus('connected'));
    echo.connector?.pusher?.connection?.bind('disconnected', () => setStatus('connecting'));
    echo.connector?.pusher?.connection?.bind('unavailable', () => setStatus('unavailable'));
    echo.connector?.pusher?.connection?.bind('failed', () => setStatus('unavailable'));

    window.NocRealtime = {
        enabled: true,
        subscribe: subscribeRealtime,
        get status() {
            return status;
        },
    };

    return echo;
}

export function subscribeRealtime(channel, event, handler) {
    if (!echo) {
        return () => {};
    }

    const privateChannel = echo.private(channel);

    privateChannel.listen(`.${event}`, handler);

    return () => privateChannel.stopListening(`.${event}`, handler);
}
