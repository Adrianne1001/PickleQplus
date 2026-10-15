/**
 * Shared full screen and screen wake lock helpers. Used by the TV page (tvScreen in
 * public-pages.js) and the organizer board (board-fullscreen.js). Everything is best effort:
 * where an API is missing or refuses, the page just carries on.
 */

/** True where the Fullscreen API can take over the whole page (not iPhone Safari). */
export function fullscreenSupported() {
    return typeof document.documentElement.requestFullscreen === 'function';
}

/** Ask the browser for full screen on the whole page. Resolves to false if unsupported or refused. */
export async function enterFullscreen() {
    if (!fullscreenSupported() || document.fullscreenElement) {
        return Boolean(document.fullscreenElement);
    }

    try {
        await document.documentElement.requestFullscreen();

        return true;
    } catch {
        // Refused (no user gesture, or blocked by policy). Callers keep working without it.
        return false;
    }
}

/** Leave browser full screen if we are in it. */
export async function exitFullscreen() {
    if (!document.fullscreenElement) {
        return;
    }

    try {
        await document.exitFullscreen();
    } catch {
        // Already leaving; nothing to do.
    }
}

/** Toggle browser full screen. */
export function toggleFullscreen() {
    return document.fullscreenElement ? exitFullscreen() : enterFullscreen();
}

/**
 * A best-effort screen wake lock. `start()` takes it and re-takes it when the tab becomes visible
 * again (browsers drop it when the tab is hidden); `stop()` releases it for good.
 * The sentinel lives in this closure, so Alpine never wraps it in a proxy.
 */
export function createWakeLock() {
    let sentinel = null;
    let wanted = false;

    const acquire = async () => {
        try {
            if ('wakeLock' in navigator) {
                const lock = await navigator.wakeLock.request('screen');

                if (wanted) {
                    sentinel = lock;
                } else {
                    // stop() ran while the request was pending.
                    lock.release().catch(() => {});
                }
            }
        } catch {
            // Best effort only.
        }
    };

    const onVisible = () => {
        if (wanted && document.visibilityState === 'visible') {
            acquire();
        }
    };

    return {
        start() {
            if (wanted) {
                return Promise.resolve();
            }

            wanted = true;
            document.addEventListener('visibilitychange', onVisible);

            return acquire();
        },

        stop() {
            wanted = false;
            document.removeEventListener('visibilitychange', onVisible);

            if (sentinel) {
                sentinel.release().catch(() => {});
                sentinel = null;
            }
        },
    };
}
