<div class="space-y-4" data-test="check-in-panel">
    <flux:heading size="lg">{{ __('Check-in') }}</flux:heading>

    @error('player')
        <flux:callout variant="danger" icon="exclamation-circle" data-test="check-in-error">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror
    @error('session')
        <flux:callout variant="danger" icon="exclamation-circle" data-test="check-in-error">
            <flux:callout.text>{{ $message }}</flux:callout.text>
        </flux:callout>
    @enderror

    @unless ($session->isEnded())
        <div class="space-y-2">
            <flux:input
                wire:model.live.debounce.250ms="search"
                type="search"
                icon="magnifying-glass"
                :label="__('Find a player')"
                :placeholder="__('Start typing a name')"
                autocomplete="off"
                data-test="check-in-search"
            />

            @if (trim($search) !== '')
                <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="check-in-results">
                    @forelse ($this->results as $player)
                        <li class="flex items-center justify-between gap-3 px-4 py-2" wire:key="result-{{ $player->id }}">
                            <span class="flex items-center gap-2">
                                <span class="font-medium">{{ $player->name }}</span>
                                <x-star-rating :stars="$player->stars" />
                            </span>
                            <flux:button size="sm" variant="primary" wire:click="checkIn({{ $player->id }})" data-test="check-in-button">{{ __('Check in') }}</flux:button>
                        </li>
                    @empty
                        <li class="px-4 py-3 text-zinc-500">{{ __('No matching active players who are not already checked in.') }}</li>
                    @endforelse
                </ul>
            @endif
        </div>
    @endunless

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700" data-test="checked-in-list">
        <div class="border-b border-zinc-200 bg-zinc-50 px-4 py-2 text-sm font-medium dark:border-zinc-700 dark:bg-zinc-900">
            {{ __('Checked in (:count)', ['count' => $this->entries->count()]) }}
        </div>
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($this->entries as $entry)
                <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-2" wire:key="entry-{{ $entry->id }}" data-test="checked-in-row">
                    <div class="flex items-center gap-3">
                        <span class="font-medium">{{ $entry->player->name }}</span>
                        <flux:badge size="sm" :color="match ($entry->status->value) { 'playing' => 'green', 'break' => 'amber', default => 'zinc' }">
                            {{ __(ucfirst($entry->status->value)) }}
                        </flux:badge>
                        <span class="text-sm text-zinc-500">{{ trans_choice(':count game|:count games', $entry->games_played) }}</span>
                    </div>
                    @unless ($session->isEnded())
                        <div class="flex flex-wrap gap-2">
                            @if ($entry->status === \App\Enums\SessionPlayerStatus::Break)
                                <flux:button size="sm" wire:click="returnFromBreak({{ $entry->player_id }})" data-test="return-button">{{ __('Return') }}</flux:button>
                            @else
                                <flux:button size="sm" wire:click="goOnBreak({{ $entry->player_id }})" data-test="break-button">{{ __('Break') }}</flux:button>
                            @endif
                            <flux:button size="sm" variant="subtle" wire:click="checkOut({{ $entry->player_id }})" data-test="check-out-button">{{ __('Check out') }}</flux:button>
                        </div>
                    @endunless
                </li>
            @empty
                <li class="px-4 py-6 text-center text-zinc-500">{{ __('Nobody is checked in yet.') }}</li>
            @endforelse
        </ul>
    </div>
</div>
