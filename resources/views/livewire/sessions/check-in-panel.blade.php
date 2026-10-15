@php
    $ended = $session->isEnded();
    $counts = $this->counts;
@endphp

<div class="space-y-6 pb-20 lg:space-y-8 lg:pb-0" data-test="check-in-panel">
    @foreach (['player', 'session', 'players'] as $key)
        @error($key)
            <flux:callout variant="danger" icon="exclamation-circle" data-test="check-in-error">
                <flux:callout.text>{{ $message }}</flux:callout.text>
            </flux:callout>
        @enderror
    @endforeach

    @if ($importResult)
        <flux:callout variant="success" icon="check-circle" data-test="import-result">
            <flux:callout.heading>{{ trans_choice('Added :count player to the list.|Added :count players to the list.', $importResult['listed']) }}</flux:callout.heading>
            <flux:callout.text>
                @if ($importResult['created'] > 0) {{ __(':count new.', ['count' => $importResult['created']]) }} @endif
                @if ($importResult['already_listed'] > 0) {{ __(':count already on the list.', ['count' => $importResult['already_listed']]) }} @endif
                @if ($importResult['errors'] > 0) {{ trans_choice(':count row had an error and was skipped.|:count rows had errors and were skipped.', $importResult['errors']) }} @endif
                @foreach ($importResult['notes'] as $note)
                    <span class="block" data-test="import-result-note">{{ $note }}</span>
                @endforeach
            </flux:callout.text>
            <x-slot name="controls">
                <flux:button type="button" icon="x-mark" size="sm" variant="ghost" class="min-h-11 min-w-11" wire:click="dismissResults" aria-label="{{ __('Dismiss') }}" data-test="dismiss-import-result" />
            </x-slot>
        </flux:callout>
    @endif

    @unless ($ended)
        <x-card :title="__('Add players')" :description="__('Build the player list. Listed players wait under Not Yet Here until you check them in.')" data-test="add-players-card">
            <x-slot:actions>
                <span class="hidden items-center rounded-full bg-zinc-100 px-3 py-1.5 text-sm font-semibold tabular-nums text-zinc-800 lg:inline-flex dark:bg-zinc-800 dark:text-zinc-200" data-test="checked-in-count">{{ __(':in/:total checked in', ['in' => $counts['checked_in'], 'total' => $counts['listed']]) }}</span>
                <flux:modal.trigger name="{{ \App\Livewire\Sessions\ImportPlayers::MODAL }}">
                    <flux:button variant="primary" icon="arrow-up-tray" class="min-h-11" data-test="open-import-button">{{ __('Import player list') }}</flux:button>
                </flux:modal.trigger>
            </x-slot:actions>

            <div class="space-y-3">
                <flux:input
                    wire:model.live.debounce.250ms="search"
                    type="search"
                    icon="magnifying-glass"
                    :label="__('Search or add player')"
                    :placeholder="__('Search or add player…')"
                    autocomplete="off"
                    data-test="check-in-search"
                />

                @if (trim($search) !== '')
                    <ul class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="check-in-results">
                        @forelse ($this->results as $player)
                            <li class="flex items-center justify-between gap-3 px-4 py-2" wire:key="result-{{ $player->id }}">
                                <span class="flex min-w-0 flex-wrap items-center gap-x-2">
                                    <span class="break-words font-medium text-zinc-900 dark:text-white">{{ $player->name }}</span>
                                    <x-star-rating :stars="$player->stars" />
                                </span>
                                <flux:button size="sm" variant="primary" class="min-h-11 shrink-0" wire:click="addToList({{ $player->id }})" data-test="add-to-list-button">{{ __('Add to list') }}</flux:button>
                            </li>
                        @empty
                            <li class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">{{ __('No matching active players who are not already on the list.') }}</li>
                        @endforelse
                    </ul>
                @endif

                <x-quick-add-player
                    :open="$quickOpen"
                    :term="trim($search)"
                    :show="$quickAdd['show']"
                    :deactivated="$quickAdd['deactivated']"
                    :roster="$session->club->is_guest ? null : route('clubs.players.index', $session->club)"
                    action="saveQuick"
                    :submit-label="__('Add to list')"
                />
            </div>
        </x-card>

        <x-card :title="__('Not Yet Here (:count)', ['count' => $this->notYetHere->count()])" :description="__('On the list, not checked in yet.')" data-test="not-yet-here">
            <x-slot:actions>
                @if ($this->notYetHere->isNotEmpty())
                    <flux:button variant="primary" icon="check-circle" class="min-h-11" wire:click="checkInAll" wire:loading.attr="disabled" wire:target="checkInAll" data-test="check-in-all-button">{{ __('Check in all') }}</flux:button>
                @endif
            </x-slot:actions>

            <div class="space-y-4">
                @if ($checkInAllResult)
                    <flux:callout :variant="$checkInAllResult['skipped'] === [] ? 'success' : 'warning'" :icon="$checkInAllResult['skipped'] === [] ? 'check-circle' : 'exclamation-triangle'" data-test="check-in-all-result">
                        <flux:callout.heading>{{ trans_choice(':count player checked in.|:count players checked in.', $checkInAllResult['checked_in']) }}</flux:callout.heading>
                        @if ($checkInAllResult['skipped'] !== [])
                            <flux:callout.text>
                                {{ __('Skipped:') }}
                                <ul class="mt-1 list-disc ps-5">
                                    @foreach ($checkInAllResult['skipped'] as $skipped)
                                        <li data-test="check-in-all-skipped"><span class="font-semibold">{{ $skipped['name'] }}</span>: {{ $skipped['reason'] }}</li>
                                    @endforeach
                                </ul>
                            </flux:callout.text>
                        @endif
                        <x-slot name="controls">
                            <flux:button type="button" icon="x-mark" size="sm" variant="ghost" class="min-h-11 min-w-11" wire:click="dismissResults" aria-label="{{ __('Dismiss') }}" data-test="dismiss-check-in-all-result" />
                        </x-slot>
                    </flux:callout>
                @endif

                @if ($this->notYetHere->isEmpty())
                    <x-empty-state icon="user-plus" :title="__('Nobody is waiting.')" :description="__('Search above or import a list to add players. Players you add show up here until you check them in.')" data-test="not-yet-here-empty" />
                @else
                    <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" data-test="not-yet-here-list">
                        @foreach ($this->notYetHere as $entry)
                            @php($player = $entry->player)
                            <li class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900" wire:key="nyh-{{ $entry->id }}" data-test="not-yet-here-card">
                                <div class="min-w-0 space-y-1">
                                    <p class="break-words text-base font-semibold text-zinc-900 dark:text-white" data-test="not-yet-here-name">{{ $player->name }}</p>
                                    @if ($player->stars_pending)
                                        <p class="flex flex-wrap items-center gap-x-2 text-sm" data-test="skill-pending">
                                            <span class="text-zinc-400 dark:text-zinc-500" aria-hidden="true">★★★</span>
                                            <button type="button" wire:click="openSkill({{ $player->id }})" class="min-h-8 font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800 focus-visible:outline-2 focus-visible:outline-brand-600 dark:text-brand-400" data-test="set-skill-link">{{ __('Set skill') }}</button>
                                        </p>
                                    @else
                                        <x-star-rating :stars="$player->stars" />
                                    @endif
                                </div>

                                @if ($skillFor === $player->id)
                                    @include('livewire.sessions.partials.skill-picker', ['name' => $player->name])
                                @endif

                                <div class="mt-auto space-y-1">
                                    <button type="button" wire:click="checkIn({{ $player->id }})" wire:loading.attr="disabled" wire:target="checkIn({{ $player->id }})" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-green-700 px-4 text-base font-semibold text-white shadow-xs hover:bg-green-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green-700 disabled:opacity-60 dark:bg-green-600 dark:hover:bg-green-500 dark:focus-visible:outline-green-400" data-test="check-in-button">
                                        <flux:icon.check class="size-5" />
                                        {{ __('Check In') }}
                                    </button>
                                    <button type="button" wire:click="removeFromList({{ $player->id }})" class="min-h-11 w-full rounded-lg px-3 text-sm font-semibold text-red-700 hover:bg-red-50 focus-visible:outline-2 focus-visible:outline-red-700 dark:text-red-400 dark:hover:bg-red-950/40" data-test="remove-from-list-button" aria-label="{{ __('Remove :name from the list', ['name' => $player->name]) }}">{{ __('Remove') }}</button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-card>
    @endunless

    <x-card :padding="false" id="checked-in-list" class="scroll-mt-4">
        <div class="overflow-x-auto" data-test="checked-in-list">
            <div class="flex flex-wrap items-center justify-between gap-2 bg-zinc-50 px-5 py-2.5 text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-400">
                <span>{{ __('Checked in (:count)', ['count' => $this->entries->count()]) }}</span>
                @if ($counts['listed'] > 0)
                    <span class="tabular-nums" data-test="checked-in-count-list">{{ __(':in/:total checked in', ['in' => $counts['checked_in'], 'total' => $counts['listed']]) }}</span>
                @endif
            </div>
            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->entries as $entry)
                    <li class="space-y-3 px-5 py-3" wire:key="entry-{{ $entry->id }}" data-test="checked-in-row">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span class="font-semibold text-zinc-900 dark:text-white">{{ $entry->player->name }}</span>
                                @if (in_array($entry->player_id, $this->newPlayerIds, true))
                                    <span data-test="new-badge"><flux:badge size="sm" color="purple">{{ __('new') }}</flux:badge></span>
                                @endif
                                @if ($entry->player->stars_pending && ! $ended)
                                    <button type="button" wire:click="openSkill({{ $entry->player_id }})" class="min-h-8 text-sm font-semibold text-brand-700 underline underline-offset-2 hover:text-brand-800 focus-visible:outline-2 focus-visible:outline-brand-600 dark:text-brand-400" data-test="set-skill-link">{{ __('Set skill') }}</button>
                                @endif
                                <flux:badge size="sm" :color="match ($entry->status->value) { 'playing' => 'green', 'break' => 'amber', default => 'zinc' }">
                                    {{ __(ucfirst($entry->status->value)) }}
                                </flux:badge>
                                <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ trans_choice(':count game|:count games', $entry->games_played) }} · <span data-test="player-wins">{{ trans_choice(':count win|:count wins', $wins[$entry->player_id] ?? 0) }}</span></span>
                            </div>
                            @unless ($ended)
                                <div class="flex flex-wrap gap-2">
                                    @if ($entry->status === \App\Enums\SessionPlayerStatus::Break)
                                        <flux:button size="sm" class="min-h-10" wire:click="returnFromBreak({{ $entry->player_id }})" data-test="return-button">{{ __('Return') }}</flux:button>
                                    @else
                                        <flux:button size="sm" class="min-h-10" wire:click="goOnBreak({{ $entry->player_id }})" data-test="break-button">{{ __('Break') }}</flux:button>
                                    @endif
                                    <flux:button size="sm" variant="subtle" class="min-h-10" wire:click="checkOut({{ $entry->player_id }})" data-test="check-out-button">{{ __('Check out') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" class="min-h-10 text-red-700! dark:text-red-400!" wire:click="removeCheckIn({{ $entry->player_id }})" wire:confirm="{{ __('Remove this check-in? The player goes back to Not Yet Here.') }}" data-test="remove-checkin-button">{{ __('Remove') }}</flux:button>
                                </div>
                            @endunless
                        </div>
                        @if ($skillFor === $entry->player_id)
                            @include('livewire.sessions.partials.skill-picker', ['name' => $entry->player->name])
                        @endif
                    </li>
                @empty
                    <li>
                        <x-empty-state class="m-5" icon="user-plus" :title="__('Nobody is checked in yet.')" :description="$ended ? null : __('Check players in from Not Yet Here, or share the QR code so players check themselves in.')" />
                    </li>
                @endforelse
            </ul>
        </div>
    </x-card>

    @if (! $ended && $counts['listed'] > 0)
        {{-- Phones: a sticky bar with the count; it leaves room for the full screen exit button. --}}
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200 bg-white/95 px-4 py-2 shadow-lg backdrop-blur lg:hidden dark:border-zinc-700 dark:bg-zinc-900/95" x-bind:class="{ 'pe-44': $data.on }" data-test="checked-in-bar">
            <button type="button" x-on:click="document.getElementById('checked-in-list')?.scrollIntoView({ behavior: 'smooth' })" class="flex min-h-11 w-full items-center justify-between gap-3 text-start focus-visible:outline-2 focus-visible:outline-brand-600">
                <span class="text-base font-bold tabular-nums text-zinc-900 dark:text-white">{{ __(':in/:total checked in', ['in' => $counts['checked_in'], 'total' => $counts['listed']]) }}</span>
                <span class="text-sm font-semibold text-brand-700 dark:text-brand-400">{{ __('View list') }}</span>
            </button>
        </div>
    @endif

    @unless ($ended)
        <livewire:sessions.import-players :club="$session->club" :session="$session" :also-listed="[]" :key="'import-players-'.$session->id" />
    @endunless
</div>
