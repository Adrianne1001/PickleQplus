@php
    $ended = $session?->isEnded() ?? false;
@endphp

<section class="w-full max-w-3xl space-y-6">
    <x-page-header
        :eyebrow="$club->name"
        :title="$session ? __('Edit session') : __('New session')"
        :description="$session ? __('Change the name, courts or rotation before the session ends.') : __('Set up an open play night. You can start it when players arrive.')"
        :back="$session ? route('clubs.sessions.show', [$club, $session]) : route('clubs.sessions.index', $club)"
        :back-label="$session ? __('Back to session') : __('All sessions')"
    />

    @if ($ended)
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="ended-callout">
            <flux:callout.text>{{ __('This session has ended and can no longer be edited.') }}</flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="save" class="space-y-6" data-test="session-form">
        <flux:error name="status" />

        <x-card :title="__('Basics')" :description="__('What players will see and where it takes place.')" class="space-y-0">
            <div class="space-y-5">
                <flux:input wire:model="name" :label="__('Name')" required maxlength="120" :disabled="$ended" />

                <flux:input wire:model="date" type="date" :label="__('Date')" required :disabled="$ended" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="courts" type="number" min="1" max="50" inputmode="numeric" :label="__('Courts')" required :disabled="$ended" />
                    <flux:input wire:model="up_next_count" type="number" min="1" max="3" inputmode="numeric" :label="__('Up Next slots')" required :disabled="$ended" />
                </div>
            </div>
        </x-card>

        <x-card :title="__('Rotation')" :description="__('How players are grouped into matches.')">
            <div class="space-y-5">
                <div class="space-y-2">
                    <flux:select
                        wire:model="rotation_mode"
                        :label="__('Rotation mode')"
                        :disabled="$ended || count($modes) < 2"
                        data-test="rotation-mode"
                    >
                        @foreach ($modes as $mode)
                            <flux:select.option value="{{ $mode->value }}">{{ $mode->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:text class="text-sm" data-test="rotation-mode-help">
                        {{ match ($rotation_mode) {
                            'mixed' => __('Every team is 1 man + 1 woman. Up Next waits until 2 of each are free.'),
                            'balanced' => __('Fair rotation: fewest games first, mixing partners and opponents.'),
                            'skill_courts' => __('Courts only take matches from their own group, even when idle.'),
                            'social' => __('Rotates partners before repeating, then spreads opponents as fairly as possible. Ratings aren\'t used.'),
                            default => '',
                        } }}
                    </flux:text>
                </div>

                @if ($rotation_mode === 'skill_courts')
                    <div class="space-y-3 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50" data-test="skill-groups-editor">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <flux:heading>{{ __('Skill groups') }}</flux:heading>
                            @unless ($ended)
                                <flux:button type="button" size="sm" variant="ghost" wire:click="resetGroups" data-test="reset-groups">{{ __('Reset to defaults') }}</flux:button>
                            @endunless
                        </div>

                        @foreach ($skill_groups as $i => $group)
                            <div class="grid grid-cols-2 items-end gap-3 rounded-lg bg-white p-3 sm:grid-cols-5 dark:bg-zinc-900" wire:key="skill-group-{{ $i }}" data-test="skill-group-row">
                                <flux:input wire:model="skill_groups.{{ $i }}.from_court" type="number" min="1" inputmode="numeric" :label="__('From court')" :disabled="$ended" data-test="group-from" />
                                <flux:input wire:model="skill_groups.{{ $i }}.to_court" type="number" min="1" inputmode="numeric" :label="__('To court')" :disabled="$ended" data-test="group-to" />
                                <flux:input wire:model="skill_groups.{{ $i }}.min_stars" type="number" min="1" max="6" inputmode="numeric" :label="__('Min ★')" :disabled="$ended" data-test="group-min" />
                                <flux:input wire:model="skill_groups.{{ $i }}.max_stars" type="number" min="1" max="6" inputmode="numeric" :label="__('Max ★')" :disabled="$ended" data-test="group-max" />
                                @unless ($ended)
                                    <flux:button type="button" variant="subtle" class="col-span-2 min-h-10 sm:col-span-1" wire:click="removeGroup({{ $i }})" aria-label="{{ __('Remove group :n', ['n' => $i + 1]) }}" data-test="remove-group">{{ __('Remove') }}</flux:button>
                                @endunless
                            </div>
                        @endforeach

                        @unless ($ended)
                            <flux:button type="button" size="sm" icon="plus" wire:click="addGroup" data-test="add-group">{{ __('Add group') }}</flux:button>
                        @endunless

                        <flux:error name="mode_settings.skill_groups" />
                    </div>
                @endif

                @if ($confirmingMode)
                    <flux:callout variant="warning" icon="exclamation-triangle" data-test="mode-confirm">
                        <flux:callout.heading>{{ __('Change the rotation mode?') }}</flux:callout.heading>
                        <flux:callout.text>{{ __('Up Next matches will be cleared, and matches being played carry on.') }}</flux:callout.text>
                        <x-slot name="actions">
                            <flux:button type="button" variant="primary" wire:click="confirmModeChange" data-test="mode-confirm-button">{{ __('Change mode and save') }}</flux:button>
                            <flux:button type="button" variant="ghost" wire:click="cancelModeChange" data-test="mode-cancel-button">{{ __('Keep current mode') }}</flux:button>
                        </x-slot>
                    </flux:callout>
                @endif

                <flux:field variant="inline">
                    <flux:checkbox wire:model="auto_fill" :disabled="$ended" data-test="auto-fill" />
                    <flux:label>{{ __('Auto-fill courts') }}</flux:label>
                    <flux:description>{{ __('Start the next Up Next match automatically when a court frees up.') }}</flux:description>
                </flux:field>
            </div>
        </x-card>

        <x-card :title="__('Scoring')" :description="__('Points needed to win a game.')">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="to" :label="__('Game to')" :disabled="$ended">
                    @foreach ([11, 15, 21] as $points)
                        <flux:select.option value="{{ $points }}">{{ $points }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="win_by" :label="__('Win by')" :disabled="$ended">
                    @foreach ([1, 2] as $margin)
                        <flux:select.option value="{{ $margin }}">{{ $margin }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </x-card>

        @unless ($ended)
            @php
                $toCheckIn = $this->picked->count() + count($newPlayers);
            @endphp
            <x-card :title="__('Players')" :description="$session ? __('Check in more players now. Everyone is checked in when you save.') : __('Optionally check players in now. Everyone is checked in when you save.')" data-test="players-section">
                <div class="space-y-4">
                    <flux:input
                        wire:model.live.debounce.250ms="search"
                        type="search"
                        icon="magnifying-glass"
                        :label="__('Find a player')"
                        :placeholder="__('Start typing a name')"
                        autocomplete="off"
                        wire:keydown.enter.prevent data-test="player-search"
                    />

                    @if (trim($search) !== '')
                        <ul class="divide-y divide-zinc-200 overflow-hidden rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="player-results">
                            @forelse ($this->results as $player)
                                <li class="flex items-center justify-between gap-3 px-4 py-2" wire:key="result-{{ $player->id }}">
                                    <span class="flex min-w-0 flex-wrap items-center gap-x-2">
                                        <span class="break-words font-medium text-zinc-900 dark:text-white">{{ $player->name }}</span>
                                        <x-star-rating :stars="$player->stars" />
                                    </span>
                                    <flux:button type="button" size="sm" variant="primary" class="min-h-11 shrink-0" wire:click="pick({{ $player->id }})" data-test="pick-player">{{ __('Add') }}</flux:button>
                                </li>
                            @empty
                                <li class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">{{ __('No matching active players left to add.') }}</li>
                            @endforelse
                        </ul>
                    @endif

                    <x-quick-add-player
                        :open="$quickOpen"
                        :term="trim($search)"
                        :show="$quickAdd['show']"
                        :deactivated="$quickAdd['deactivated']"
                        :roster="route('clubs.players.index', $club)"
                        action="addQuick"
                        :submit-label="__('Add to list')"
                    />

                    <flux:error name="players" />

                    <div class="space-y-2" data-test="to-check-in">
                        <flux:heading size="sm">{{ __('To check in (:count)', ['count' => $toCheckIn]) }}</flux:heading>
                        @if ($toCheckIn === 0)
                            <flux:text class="text-sm">{{ __('Nobody picked yet. Search above to add players.') }}</flux:text>
                        @else
                            <ul class="space-y-2">
                                @foreach ($this->picked as $player)
                                    <li class="flex items-center justify-between gap-2 rounded-xl bg-zinc-100 py-1 pl-3 pr-1 dark:bg-zinc-800" wire:key="picked-{{ $player->id }}" data-test="picked-chip">
                                        <span class="flex min-w-0 flex-wrap items-center gap-x-2">
                                            <span class="break-words font-medium text-zinc-900 dark:text-white">{{ $player->name }}</span>
                                            <x-star-rating :stars="$player->stars" />
                                        </span>
                                        <flux:button type="button" size="sm" variant="ghost" class="min-h-11 shrink-0" wire:click="unpick({{ $player->id }})" aria-label="{{ __('Remove :name', ['name' => $player->name]) }}">{{ __('Remove') }}</flux:button>
                                    </li>
                                @endforeach
                                @foreach ($newPlayers as $i => $pending)
                                    @php
                                        $chipErrors = collect(['name', 'stars', 'dupr_id', 'gender'])->map(fn ($k) => $errors->first("new_players.{$i}.{$k}"))->filter()->all();
                                    @endphp
                                    <li class="rounded-xl bg-zinc-100 py-1 pl-3 pr-1 dark:bg-zinc-800 {{ $chipErrors ? 'ring-2 ring-red-600 dark:ring-red-400' : '' }}" wire:key="new-{{ $i }}-{{ $pending['name'] }}" data-test="new-chip">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="flex min-w-0 flex-wrap items-center gap-x-2">
                                                <span class="break-words font-medium text-zinc-900 dark:text-white">{{ $pending['name'] }}</span>
                                                <x-star-rating :stars="$pending['stars']" />
                                                <flux:badge size="sm" color="purple">{{ __('new') }}</flux:badge>
                                            </span>
                                            <flux:button type="button" size="sm" variant="ghost" class="min-h-11 shrink-0" wire:click="removeNew({{ $i }})" aria-label="{{ __('Remove :name', ['name' => $pending['name']]) }}">{{ __('Remove') }}</flux:button>
                                        </div>
                                        @foreach ($chipErrors as $message)
                                            <p class="pb-2 pr-3 text-sm font-medium text-red-700 dark:text-red-400" data-test="chip-error">{{ $message }}</p>
                                        @endforeach
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>

                    @if ($session)
                        <div class="space-y-2 border-t border-zinc-200 pt-4 dark:border-zinc-700" data-test="already-checked-in">
                            <flux:heading size="sm">{{ __('Already checked in (:count)', ['count' => $this->checkedIn->count()]) }}</flux:heading>
                            <flux:text class="text-sm">{{ __('These players are managed on the session board.') }}</flux:text>
                            @if ($this->checkedIn->isNotEmpty())
                                <ul class="flex flex-wrap gap-2">
                                    @foreach ($this->checkedIn as $entry)
                                        <li class="rounded-full bg-zinc-100 px-3 py-1 text-sm text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200" wire:key="in-{{ $entry->id }}">{{ $entry->player->name }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif
                </div>
            </x-card>
        @endunless

        <div class="flex flex-wrap gap-3">
            @unless ($ended)
                <flux:button variant="primary" type="submit" class="min-h-11" data-test="save-session-button">{{ __('Save session') }}</flux:button>
            @endunless
            <flux:button
                variant="ghost"
                class="min-h-11"
                :href="$session ? route('clubs.sessions.show', [$club, $session]) : route('clubs.sessions.index', $club)"
                wire:navigate
            >{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
