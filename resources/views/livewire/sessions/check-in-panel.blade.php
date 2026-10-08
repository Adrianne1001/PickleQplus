<x-card :title="__('Check-in')" :description="__('Find a player and check them in, or manage who is here.')" :padding="false" data-test="check-in-panel">
    @error('player')
        <div class="p-5 pb-0">
            <flux:callout variant="danger" icon="exclamation-circle" data-test="check-in-error">
                <flux:callout.text>{{ $message }}</flux:callout.text>
            </flux:callout>
        </div>
    @enderror
    @error('session')
        <div class="p-5 pb-0">
            <flux:callout variant="danger" icon="exclamation-circle" data-test="check-in-error">
                <flux:callout.text>{{ $message }}</flux:callout.text>
            </flux:callout>
        </div>
    @enderror

    @unless ($session->isEnded())
        <div class="space-y-2 p-5">
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
                <ul class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="check-in-results">
                    @forelse ($this->results as $player)
                        <li class="flex items-center justify-between gap-3 px-4 py-2" wire:key="result-{{ $player->id }}">
                            <span class="flex items-center gap-2">
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $player->name }}</span>
                                <x-star-rating :stars="$player->stars" />
                            </span>
                            <flux:button size="sm" variant="primary" class="min-h-11" wire:click="checkIn({{ $player->id }})" data-test="check-in-button">{{ __('Check in') }}</flux:button>
                        </li>
                    @empty
                        <li class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">{{ __('No matching active players who are not already checked in.') }}</li>
                    @endforelse
                </ul>
            @endif
        </div>
    @endunless

    <div class="overflow-x-auto border-t border-zinc-200 dark:border-zinc-700" data-test="checked-in-list">
        <div class="bg-zinc-50 px-5 py-2.5 text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-400">
            {{ __('Checked in (:count)', ['count' => $this->entries->count()]) }}
        </div>
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($this->entries as $entry)
                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3" wire:key="entry-{{ $entry->id }}" data-test="checked-in-row">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $entry->player->name }}</span>
                        @if ($entry->player->nickname)
                            <span class="text-sm text-zinc-600 dark:text-zinc-400" data-test="entry-nickname">"{{ $entry->player->nickname }}"</span>
                        @endif
                        @if (in_array($entry->player_id, $this->newPlayerIds, true))
                            <span data-test="new-badge"><flux:badge size="sm" color="purple">{{ __('new') }}</flux:badge></span>
                        @endif
                        <flux:badge size="sm" :color="match ($entry->status->value) { 'playing' => 'green', 'break' => 'amber', default => 'zinc' }">
                            {{ __(ucfirst($entry->status->value)) }}
                        </flux:badge>
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ trans_choice(':count game|:count games', $entry->games_played) }}</span>
                    </div>
                    @unless ($session->isEnded())
                        <div class="flex flex-wrap gap-2">
                            @if ($entry->status === \App\Enums\SessionPlayerStatus::Break)
                                <flux:button size="sm" class="min-h-10" wire:click="returnFromBreak({{ $entry->player_id }})" data-test="return-button">{{ __('Return') }}</flux:button>
                            @else
                                <flux:button size="sm" class="min-h-10" wire:click="goOnBreak({{ $entry->player_id }})" data-test="break-button">{{ __('Break') }}</flux:button>
                            @endif
                            <flux:button size="sm" variant="subtle" class="min-h-10" wire:click="checkOut({{ $entry->player_id }})" data-test="check-out-button">{{ __('Check out') }}</flux:button>
                            <flux:button size="sm" variant="ghost" class="min-h-10 text-red-700! dark:text-red-400!" wire:click="removeCheckIn({{ $entry->player_id }})" wire:confirm="{{ __('Remove this check-in? Self-registered players with no games are deleted.') }}" data-test="remove-checkin-button">{{ __('Remove') }}</flux:button>
                        </div>
                    @endunless
                </li>
            @empty
                <li>
                    <x-empty-state class="m-5" icon="user-plus" :title="__('Nobody is checked in yet.')" :description="$session->isEnded() ? null : __('Search for a player above, or share the QR code so players check themselves in.')" />
                </li>
            @endforelse
        </ul>
    </div>
</x-card>
