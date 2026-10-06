<section class="space-y-4" data-test="checkin-qr-panel">
    <flux:heading size="lg" level="2">{{ __('Check-in QR and links') }}</flux:heading>

    @if ($svg)
        <div class="flex flex-wrap items-start gap-6">
            <div class="rounded-xl bg-white p-3 shadow" data-test="checkin-qr-svg" aria-label="{{ __('Check-in QR code') }}">
                <div class="size-72 max-w-full [&>svg]:size-full">{!! $svg !!}</div>
            </div>

            <div class="min-w-64 flex-1 space-y-4">
                <x-copy-field :label="__('Check-in link')" :value="$checkinUrl" test="checkin-url" />
                <x-copy-field :label="__('Public queue link')" :value="$queueUrl" test="queue-url" />
                <x-copy-field :label="__('TV link')" :value="$tvUrl" test="tv-url" />

                <div>
                    <flux:button icon="arrow-path" wire:click="resetTvLink" wire:confirm="{{ __('Reset the TV link? Any TV using the old link stops updating; open the new link on the TV.') }}" data-test="reset-tv-link-button">{{ __('Reset TV link') }}</flux:button>
                </div>

                <flux:modal.trigger name="regenerate-qr">
                    <flux:button icon="arrow-path" data-test="regenerate-qr-button">{{ __('Regenerate QR') }}</flux:button>
                </flux:modal.trigger>
            </div>
        </div>

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
    @else
        <flux:subheading data-test="checkin-qr-ended">{{ __('This session has ended, so check-in is closed.') }}</flux:subheading>
        <x-copy-field :label="__('Public queue link')" :value="$queueUrl" test="queue-url" />
    @endif
</section>
