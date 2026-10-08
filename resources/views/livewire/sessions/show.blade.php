<section class="w-full max-w-7xl space-y-6 lg:space-y-8" wire:poll.30s.visible="syncFromBroadcast">
    <x-page-header
        :eyebrow="$club->name"
        :title="$session->name"
        :description="$session->date->format('D, j M Y').' · '.trans_choice(':count court|:count courts', $session->courts).' · '.__('to :to, win by :by', ['to' => $session->scoring['to'], 'by' => $session->scoring['win_by']])"
        :back="route('clubs.sessions.index', $club)"
        :back-label="__('All sessions')"
    >
        <div class="mt-3"><x-session-status-badge :status="$session->status" /></div>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2" data-test="session-actions">
                @if ($session->isDraft())
                    <flux:button variant="primary" icon="play" class="min-h-11" wire:click="start" data-test="start-session-button">{{ __('Start session') }}</flux:button>
                @endif
                @if ($session->isLive())
                    <flux:button icon="qr-code" class="min-h-11" x-on:click="$dispatch('open-share'); $nextTick(() => document.getElementById('share')?.scrollIntoView({ behavior: 'smooth' }))" data-test="share-links-button">{{ __('QR and links') }}</flux:button>
                @endif
                @unless ($session->isDraft())
                    <flux:button icon="chart-bar" class="min-h-11" :href="route('clubs.sessions.results', [$club, $session])" wire:navigate data-test="results-link">{{ __('Results') }}</flux:button>
                @endunless
                @if ($session->isEnded())
                    <flux:button icon="arrow-down-tray" class="min-h-11" :href="route('clubs.sessions.dupr', [$club, $session])" wire:navigate data-test="dupr-export-link">{{ __('DUPR export') }}</flux:button>
                @endif
                @unless ($session->isEnded())
                    <flux:button icon="pencil-square" class="min-h-11" :href="route('clubs.sessions.edit', [$club, $session])" wire:navigate data-test="edit-session-button">{{ __('Edit') }}</flux:button>
                @endunless
                @if ($session->isLive())
                    <flux:modal.trigger name="end-session">
                        <flux:button variant="danger" icon="stop" class="min-h-11" data-test="end-session-button">{{ __('End session') }}</flux:button>
                    </flux:modal.trigger>
                @endif
                @if ($session->isDraft())
                    <flux:modal.trigger name="delete-session">
                        <flux:button variant="danger" icon="trash" class="min-h-11" data-test="delete-session-button">{{ __('Delete') }}</flux:button>
                    </flux:modal.trigger>
                @endif
            </div>
        </x-slot:actions>
    </x-page-header>

    @error('status')
        <flux:callout variant="danger" icon="exclamation-circle" data-test="session-error">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    @if ($session->isDraft())
        <x-card>
            <x-empty-state icon="play-circle" :title="__('This session has not started')" :description="__('Check players in below, then start the session to open the courts and the queue.')" />
        </x-card>
    @endif

    @if ($session->isLive())
        <div class="grid items-start gap-6 xl:grid-cols-3 xl:gap-8">
            <div class="space-y-6 lg:space-y-8 xl:col-span-2">
                <livewire:sessions.courts :session="$session" :key="'courts-'.$session->id" />
                <livewire:sessions.up-next :session="$session" :key="'up-next-'.$session->id" />
            </div>
            <div class="xl:sticky xl:top-4">
                <livewire:sessions.waiting-list :session="$session" :key="'waiting-'.$session->id" />
            </div>
        </div>
    @endif

    @unless ($session->isDraft())
        <livewire:sessions.results :session="$session" :key="'results-'.$session->id" />
    @endunless

    <div class="space-y-6 lg:space-y-8">
        <livewire:sessions.check-in-panel :session="$session" :key="'check-in-'.$session->id" />

        <livewire:sessions.check-in-qr :session="$session" :key="'check-in-qr-'.$session->id" />
    </div>

    <flux:modal name="end-session" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('End this session?') }}</flux:heading>
                <flux:subheading>{{ __('Matches waiting in Up Next are cancelled. An ended session cannot be reopened.') }}</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="end" data-test="confirm-end-session-button">{{ __('End session') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete-session" class="max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this session?') }}</flux:heading>
                <flux:subheading>{{ __('The draft and its check-ins are removed. This cannot be undone.') }}</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled" type="button">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="danger" wire:click="delete" data-test="confirm-delete-session-button">{{ __('Delete session') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
