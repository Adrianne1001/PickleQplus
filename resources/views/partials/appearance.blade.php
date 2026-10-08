{{--
    Theme bootstrap. Replaces @fluxAppearance so a first-time visitor gets LIGHT
    instead of "system". It runs in <head> before first paint (no flash) and keeps
    Flux's own storage key, so $flux.appearance, Settings -> Appearance and the
    theme toggle all share one choice: 'light' | 'dark' | 'system'.
    A visitor with no stored choice is saved as 'light'. 'system' is stored
    explicitly, because Flux's default applyAppearance would delete the key.
--}}
<style>
    :root.dark {
        color-scheme: dark;
    }
</style>
<script>
    (function () {
        var KEY = 'flux.appearance';
        var root = document.documentElement;

        function read() {
            try { return window.localStorage.getItem(KEY); } catch (e) { return null; }
        }

        function write(value) {
            try { window.localStorage.setItem(KEY, value); } catch (e) {}
        }

        function sync() {
            var pressed = root.classList.contains('dark') ? 'true' : 'false';
            document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
                button.setAttribute('aria-pressed', pressed);
            });
        }

        function apply(mode) {
            var dark = mode === 'dark'
                || (mode === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);

            root.classList.toggle('dark', dark);
            sync();
        }

        if (! window.Flux || ! ('appearance' in window.Flux)) {
            window.Flux = {
                applyAppearance: function (mode) {
                    if (mode !== 'light' && mode !== 'dark' && mode !== 'system') { return; }

                    write(mode);
                    apply(mode);
                },
            };
        }

        var mode = read();

        if (mode !== 'light' && mode !== 'dark' && mode !== 'system') {
            mode = 'light';
            write(mode);
        }

        apply(mode);

        if (window.__pickleqThemeBound) { return; }
        window.__pickleqThemeBound = true;

        try {
            var mq = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)');

            if (mq && mq.addEventListener) {
                mq.addEventListener('change', function () {
                    if (read() === 'system') { apply('system'); }
                });
            }
        } catch (e) {}

        document.addEventListener('click', function (event) {
            var button = event.target.closest && event.target.closest('[data-theme-toggle]');

            if (! button) { return; }

            var next = root.classList.contains('dark') ? 'light' : 'dark';

            if (window.Flux && 'appearance' in window.Flux) {
                window.Flux.appearance = next; // Flux store: persists and applies.
            } else {
                window.Flux.applyAppearance(next);
            }
        });

        document.addEventListener('DOMContentLoaded', sync);
        document.addEventListener('livewire:navigated', sync);
    })();
</script>
