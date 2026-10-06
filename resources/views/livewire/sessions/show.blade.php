<section class="w-full max-w-5xl space-y-8" wire:poll.30s.visible="syncFromBroadcast">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1" class="flex flex-wrap items-center gap-3">
                {{ $session->name }}
                <x-session-status-badge :status="$session->status" />
            </flux:heading>
            <flux:subheading>
                {{ $session->date->format('D, j M Y') }}
                &middot; {{ trans_choice(':count court|:count courts', $session->courts) }}
                &middot; {{ __('to :to, win by :by', ['to' => $session->scoring['to'], 'by' => $session->scoring['win_by']]) }}
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2" data-test="session-actions">
            @if ($session->isDraft())
                <flux:button variant="primary" icon="play" wire:click="start" data-test="start-session-button">{{ __('Start session') }}</flux:button>
            @endif
            @if ($session->isLive())
                <flux:modal.trigger name="end-session">
                    <flux:button variant="primary" icon="stop" data-test="end-session-button">{{ __('End session') }}</flux:button>
                </flux:modal.trigger>
            @endif
            @unless ($session->isDraft())
                <flux:button icon="chart-bar" :href="route('clubs.sessions.results', [$club, $session])" wire:navigate data-test="results-link">{{ __('Results') }}</flux:button>
            @endunless
            @if ($session->isEnded())
                <flux:button icon="arrow-down-tray" :href="route('clubs.sessions.dupr', [$club, $session])" wire:navigate data-test="dupr-export-link">{{ __('DUPR export') }}</flux:button>
            @endif
            @unless ($session->isEnded())
                <flux:button icon="pencil-square" :href="route('clubs.sessions.edit', [$club, $session])" wire:navigate data-test="edit-session-button">{{ __('Edit') }}</flux:button>
            @endunless
            @if ($session->isDraft())
                <flux:modal.trigger name="delete-session">
                    <flux:button variant="danger" icon="trash" data-test="delete-session-button">{{ __('Delete') }}</flux:button>
                </flux:modal.trigger>
            @endif
        </div>
    </div>

    @error('status')
        <flux:callout variant="danger" icon="exclamation-circle" data-test="session-error">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    @if ($session->isLive())
        <livewire:sessions.courts :session="$session" :key="'courts-'.$session->id" />
        <livewire:sessions.up-next :session="$session" :key="'up-next-'.$session->id" />
        <livewire:sessions.waiting-list :session="$session" :key="'waiting-'.$session->id" />
    @endif

    @unless ($session->isDraft())
        <livewire:sessions.results :session="$session" :key="'results-'.$session->id" />
    @endunless

    <livewire:sessions.check-in-panel :session="$session" :key="'check-in-'.$session->id" />

    <livewire:sessions.check-in-qr :session="$session" :key="'check-in-qr-'.$session->id" />

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
