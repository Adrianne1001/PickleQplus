/**
 * Guest sessions (Phase 14): leave a page when its data has been deleted, and keep the
 * countdown banner honest.
 *
 * - `guestExpiry(channel, url)`: Alpine component that listens for the `session.expired`
 *   broadcast on the session's public channel and goes to the expired page.
 * - `guestCountdown(iso, texts)`: Alpine component for the banner. It works out "about X h" or
 *   "about X min" and the urgent state from the expiry time, and refreshes every minute, so
 *   pages that don't poll don't go stale.
 * - A Livewire request hook: an answer to a poll or update on a page with a
 *   `[data-guest-expiry]` marker goes to the expired page. The marker lists the statuses that
 *   count (`data-guest-expiry-on`). Board pages list only 410, which the server sends when the
 *   guest club has expired or been purged; a plain 404 there is a normal error (a row that was
 *   already removed). Public pages also list 404, because their club may be gone entirely.
 *   Pages without the marker (every normal club) keep Livewire's default error handling.
 */
function guestExpiry(channel, url) {
    return {
        channel: null,

        init() {
            this.channel = window.Echo?.channel(channel) ?? null;
            this.channel?.listen('.session.expired', () => window.location.assign(url));
        },

        destroy() {
            this.channel?.stopListening('.session.expired');
        },
    };
}

function guestCountdown(iso, texts, urgentMinutes = 120) {
    return {
        text: '',
        urgent: false,
        timer: null,

        init() {
            this.tick();
            this.timer = setInterval(() => this.tick(), 60000);
        },

        destroy() {
            clearInterval(this.timer);
        },

        tick() {
            const minutes = Math.max(0, Math.ceil((new Date(iso).getTime() - Date.now()) / 60000));

            this.urgent = minutes < urgentMinutes;
            this.text = minutes < 1
                ? texts.moment
                : minutes < 60
                    ? texts.minutes.replace(':n', String(minutes))
                    : texts.hours.replace(':n', String(Math.round(minutes / 60)));
        },
    };
}

function registerAlpine() {
    window.Alpine.data('guestExpiry', guestExpiry);
    window.Alpine.data('guestCountdown', guestCountdown);
}

function registerLivewireHook() {
    window.Livewire.interceptRequest(({ onError }) => {
        onError(({ response, preventDefault }) => {
            const marker = document.querySelector('[data-guest-expiry]');
            const url = marker?.dataset.guestExpiry;
            const statuses = (marker?.dataset.guestExpiryOn ?? '410').split(',').map(Number);

            if (url && statuses.includes(response.status)) {
                preventDefault();
                window.location.assign(url);
            }
        });
    });
}

if (window.Alpine) {
    registerAlpine();
} else {
    document.addEventListener('alpine:init', registerAlpine);
}

if (window.Livewire?.interceptRequest) {
    registerLivewireHook();
} else {
    document.addEventListener('livewire:init', registerLivewireHook);
}
