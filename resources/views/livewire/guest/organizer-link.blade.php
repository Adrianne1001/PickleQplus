<div>
    @if ($url)
        <section
            x-data="{ open: false }"
            class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-700 dark:bg-zinc-900"
            data-test="guest-organizer-link"
        >
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('Organizer link') }}</h2>
                    <p class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Run this board from another device.') }}</p>
                </div>
                <flux:button type="button" size="sm" class="min-h-10" icon="device-phone-mobile" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="guest-organizer-body" data-test="guest-organizer-toggle">
                    <span x-show="!open">{{ __('Show') }}</span>
                    <span x-show="open" x-cloak>{{ __('Hide') }}</span>
                </flux:button>
            </div>

            <div id="guest-organizer-body" x-show="open" x-cloak class="space-y-4 border-t border-zinc-100 p-5 dark:border-zinc-800">
                <p class="text-sm font-medium text-amber-900 dark:text-amber-200" data-test="guest-organizer-warning">
                    {{ __('Open this link on another device to run the board. Anyone with it controls this session — don\'t post it publicly.') }}
                </p>
                <div class="flex flex-wrap items-start gap-6">
                    <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-zinc-200" data-test="guest-organizer-qr" role="img" aria-label="{{ __('QR code for the organizer link') }}">
                        <div class="size-48 max-w-full text-zinc-900 [&>svg]:size-full">{!! $svg !!}</div>
                    </div>
                    <div class="min-w-64 flex-1">
                        <x-copy-field :label="__('Organizer link')" :value="$url" test="guest-organizer-url" />
                    </div>
                </div>
            </div>
        </section>
    @endif
</div>
