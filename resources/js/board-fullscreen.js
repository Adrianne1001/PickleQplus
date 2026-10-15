import { createWakeLock, enterFullscreen, exitFullscreen } from './fullscreen';

/** Class on <html> while board full screen is on. CSS hides every `.board-chrome` element (app.css). */
const ROOT_CLASS = 'board-fullscreen';

/**
 * Alpine component for the organizer board's full screen mode. State is client side only and lives
 * on the board's root element, so Livewire re-renders and polling never reset it.
 * On: hide the app chrome, ask the browser for real full screen where supported, hold a wake lock.
 * Off: the reverse. Leaving browser full screen (Esc, swipe) or navigating away also turns it off.
 */
function boardFullscreen() {
    const wakeLock = createWakeLock();

    return {
        on: false,

        init() {
            this.onFullscreenChange = () => {
                // Browser full screen ended (Esc, swipe, system gesture): the two must not disagree.
                if (this.on && !document.fullscreenElement && this.hadBrowserFullscreen) {
                    this.turnOff();
                }
            };
            this.onNavigating = () => this.turnOff();
            this.hadBrowserFullscreen = false;

            document.addEventListener('fullscreenchange', this.onFullscreenChange);
            document.addEventListener('livewire:navigating', this.onNavigating);
        },

        destroy() {
            document.removeEventListener('fullscreenchange', this.onFullscreenChange);
            document.removeEventListener('livewire:navigating', this.onNavigating);
            this.turnOff();
        },

        async turnOn() {
            this.on = true;
            document.documentElement.classList.add(ROOT_CLASS);
            wakeLock.start();

            // Without the Fullscreen API (iPhone Safari) or if it is refused, the chrome stays hidden.
            this.hadBrowserFullscreen = await enterFullscreen();

            // Turned off again while the request was pending.
            if (!this.on) {
                exitFullscreen();
            }
        },

        turnOff() {
            if (!this.on) {
                return;
            }

            this.on = false;
            this.hadBrowserFullscreen = false;
            document.documentElement.classList.remove(ROOT_CLASS);
            wakeLock.stop();
            exitFullscreen();
        },

        toggle() {
            return this.on ? this.turnOff() : this.turnOn();
        },
    };
}

function register() {
    window.Alpine.data('boardFullscreen', boardFullscreen);
}

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
