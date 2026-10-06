import { decide, messageFor, stateFor } from './queue-alerts';

const meKey = (publicId) => `pickleq.me.${publicId}`;
const seenKey = (publicId) => `pickleq.seen.${publicId}`;

function readMe(publicId) {
    try {
        // Player public ids are opaque strings; never cast them.
        return window.localStorage.getItem(meKey(publicId)) || null;
    } catch {
        return null;
    }
}

/** Alpine component for the public queue page: "this is me" and alerts. */
function queueMe(publicId) {
    return {
        me: readMe(publicId),
        alertsSupported: typeof Notification !== 'undefined',
        permission: typeof Notification !== 'undefined' ? Notification.permission : 'denied',

        init() {
            this.$wire.$watch('live', () => this.check());
            this.check();
        },

        get live() {
            return this.$wire.live;
        },

        get state() {
            return stateFor(this.live, this.me);
        },

        get banner() {
            return messageFor(this.state);
        },

        pick(id) {
            this.me = id;
            window.localStorage.setItem(meKey(publicId), String(id));
            this.check();
        },

        clear() {
            this.me = null;
            window.localStorage.removeItem(meKey(publicId));
            window.sessionStorage.removeItem(seenKey(publicId));
        },

        async enableAlerts() {
            if (!this.alertsSupported) {
                return;
            }

            this.permission = await Notification.requestPermission();
        },

        check() {
            // Someone who left the session can't be "me" any more.
            if (this.me !== null && this.live?.players && !this.live.players.includes(this.me)) {
                if (this.live.status === 'live') {
                    this.clear();
                }

                return;
            }

            if (this.me === null) {
                return;
            }

            const previous = window.sessionStorage.getItem(seenKey(publicId));
            const { alert, key } = decide(previous, this.state);
            window.sessionStorage.setItem(seenKey(publicId), key);

            if (alert) {
                this.notify(messageFor(this.state), key);
            }
        },

        notify(message, key) {
            if (typeof navigator.vibrate === 'function') {
                navigator.vibrate([300, 150, 300]);
            }

            if (this.permission === 'granted' && typeof Notification !== 'undefined') {
                try {
                    new Notification(message.title, { body: message.body, tag: `pickleq-${publicId}-${key}`, renotify: true });
                } catch {
                    // Some mobile browsers only allow notifications from a service worker. The banner still shows.
                }
            }
        },
    };
}

/** Alpine component for the self check-in page: remember who "me" is on this device. */
function checkinMe() {
    return {
        remember(publicId, playerId) {
            try {
                window.localStorage.setItem(meKey(publicId), String(playerId));
            } catch {
                // Storage may be blocked; the queue page just won't highlight them.
            }
        },
    };
}

/** Alpine component for the TV: full screen button and best-effort wake lock. */
function tvScreen() {
    return {
        supported: typeof document.documentElement.requestFullscreen === 'function',
        wakeLock: null,

        init() {
            this.requestWakeLock();
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') {
                    this.requestWakeLock();
                }
            });
        },

        toggleFullscreen() {
            if (document.fullscreenElement) {
                document.exitFullscreen();
            } else {
                document.documentElement.requestFullscreen();
            }
        },

        async requestWakeLock() {
            try {
                if ('wakeLock' in navigator) {
                    this.wakeLock = await navigator.wakeLock.request('screen');
                }
            } catch {
                // Best effort only.
            }
        },
    };
}

function register() {
    window.Alpine.data('queueMe', queueMe);
    window.Alpine.data('checkinMe', checkinMe);
    window.Alpine.data('tvScreen', tvScreen);
}

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
