<section id="share" x-data="{ open: false }" x-on:open-share.window="open = true" class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-700 dark:bg-zinc-900" data-test="checkin-qr-panel">
    <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
        <div class="min-w-0">
            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">{{ __('Check-in QR and links') }}</h2>
            <p class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-400">{{ __('Show the QR code to players, and open the TV and queue links.') }}</p>
        </div>
        <flux:button type="button" size="sm" class="min-h-10" icon="qr-code" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="share-body">
            <span x-show="!open">{{ __('Show') }}</span>
            <span x-show="open" x-cloak>{{ __('Hide') }}</span>
        </flux:button>
    </div>

    <div id="share-body" x-show="open" x-cloak class="border-t border-zinc-100 p-5 dark:border-zinc-800">
        @if ($svg)
            <div class="flex flex-wrap items-start gap-6">
                <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-zinc-200" data-test="checkin-qr-svg" aria-label="{{ __('Check-in QR code') }}">
                    <div class="size-64 max-w-full text-zinc-900 [&>svg]:size-full">{!! $svg !!}</div>
                </div>

                <div class="min-w-64 flex-1 space-y-4">
                    <x-copy-field :label="__('Check-in link')" :value="$checkinUrl" test="checkin-url" />
                    <x-copy-field :label="__('Public queue link')" :value="$queueUrl" test="queue-url" />
                    <x-copy-field :label="__('TV link')" :value="$tvUrl" test="tv-url" />

                    <div class="mt-2 space-y-2 rounded-xl border border-red-200 bg-red-50/60 p-4 dark:border-red-900 dark:bg-red-950/30">
                        <p class="text-xs font-semibold uppercase tracking-wider text-red-800 dark:text-red-300">{{ __('Reset links') }}</p>
                        <div class="flex flex-wrap gap-2">
                            <flux:button icon="arrow-path" wire:click="resetTvLink" wire:confirm="{{ __('Reset the TV link? Any TV using the old link stops updating; open the new link on the TV.') }}" data-test="reset-tv-link-button">{{ __('Reset TV link') }}</flux:button>

                            <flux:modal.trigger name="regenerate-qr">
                                <flux:button icon="arrow-path" data-test="regenerate-qr-button">{{ __('Regenerate QR') }}</flux:button>
                            </flux:modal.trigger>
                        </div>
                    </div>
                </div>
            </div>
        @else
            <flux:subheading data-test="checkin-qr-ended">{{ __('This session has ended, so check-in is closed.') }}</flux:subheading>
            <div class="mt-4">
                <x-copy-field :label="__('Public queue link')" :value="$queueUrl" test="queue-url" />
            </div>
        @endif
    </div>

    @if ($svg)
        <flux:modal name="regenerate-qr" class="max-w-lg">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Regenerate the check-in QR?') }}</flux:heading>
                    <flux:subheading>{{ __('The old QR stops working. Printed or shared copies will no longer check anyone in.') }}</flux:subheading>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="regenerate" data-test="confirm-regenerate-qr-button">{{ __('Regenerate') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</section>
