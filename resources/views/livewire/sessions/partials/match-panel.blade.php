{{--
    Inline swap / void panel for one match. Needs $match, $candidates and the ManagesMatchPlayers state.
    The swap picks are plain buttons that set server state (not a native select), and every option has a
    wire:key, so the lists rebuilt by a poll or a board change keep the staff member's picks.
--}}
@if ($panel !== null && $panelMatchId === $match['id'] && in_array($panel, ['swap', 'void'], true))
    @php
        $mid = $match['id'];
        $waitingRows = array_values(array_filter($candidates, fn (array $c): bool => ($c['source'] ?? 'waiting') === 'waiting'));
        $upNextRows = array_values(array_filter($candidates, fn (array $c): bool => ($c['source'] ?? 'waiting') === 'up_next'));
        $pick = 'flex min-h-12 w-full items-center justify-between gap-2 rounded-xl border px-3 py-2 text-start text-base transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600';
        $picked = 'border-brand-600 bg-brand-50 font-bold text-brand-900 ring-2 ring-brand-600 dark:border-brand-400 dark:bg-brand-950/60 dark:text-brand-100 dark:ring-brand-400';
        $idle = 'border-zinc-300 bg-white text-zinc-900 hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white dark:hover:bg-zinc-800';
    @endphp
    <div class="mt-4 space-y-4 rounded-xl border border-zinc-300 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800/60" data-test="match-panel-{{ $panel }}">
        @if ($panel === 'swap')
            @if ($pickNotice !== '')
                <flux:callout variant="warning" icon="exclamation-triangle" data-test="swap-pick-notice">
                    <flux:callout.text>{{ $pickNotice }}</flux:callout.text>
                </flux:callout>
            @endif

            <div role="group" aria-labelledby="swap-out-{{ $mid }}" class="space-y-2" data-test="out-player">
                <p id="swap-out-{{ $mid }}" class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Swap out') }}</p>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (['A', 'B'] as $team)
                        @foreach ($match['teams'][$team] as $player)
                            @php $on = $outPlayerId === (string) $player['id']; @endphp
                            <button type="button" wire:key="out-{{ $mid }}-{{ $player['id'] }}" wire:click="$set('outPlayerId', '{{ $player['id'] }}')" aria-pressed="{{ $on ? 'true' : 'false' }}" class="{{ $pick }} {{ $on ? $picked : $idle }}" data-test="out-option" data-player="{{ $player['id'] }}" @if ($on) data-selected="1" @endif>
                                <span class="flex min-w-0 items-center gap-2">
                                    <span class="truncate">{{ $player['name'] }}</span>
                                    @if ($mixed ?? false)
                                        <x-gender-marker :gender="$player['gender'] ?? null" />
                                    @endif
                                </span>
                                <span class="shrink-0 text-xs font-semibold uppercase text-zinc-600 dark:text-zinc-400">{{ __('Team :team', ['team' => $team]) }}</span>
                            </button>
                        @endforeach
                    @endforeach
                </div>
            </div>

            <div role="group" aria-labelledby="swap-in-{{ $mid }}" class="space-y-2" data-test="in-player">
                <p id="swap-in-{{ $mid }}" class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Swap in') }}</p>

                @if ($candidates === [])
                    <div class="space-y-2 rounded-xl border border-dashed border-zinc-300 p-4 dark:border-zinc-600" data-test="swap-empty">
                        <flux:text>{{ __('Nobody is waiting. Void the match instead.') }}</flux:text>
                        <flux:button variant="danger" size="sm" class="min-h-11" wire:click="openPanel('void', {{ $mid }})" data-test="swap-empty-void">{{ __('Void match') }}</flux:button>
                    </div>
                @else
                    <div class="max-h-72 space-y-3 overflow-y-auto pr-1">
                        @foreach ([['key' => 'waiting', 'label' => __('Waiting'), 'rows' => $waitingRows], ['key' => 'up_next', 'label' => __('Up Next'), 'rows' => $upNextRows]] as $group)
                            @if ($group['rows'] !== [])
                                <div class="space-y-1.5" wire:key="in-group-{{ $mid }}-{{ $group['key'] }}" data-test="in-group" data-group="{{ $group['key'] }}">
                                    <p class="text-xs font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">{{ $group['label'] }}</p>
                                    @foreach ($group['rows'] as $candidate)
                                        @php $on = $inPlayerId === (string) $candidate['id']; @endphp
                                        <button type="button" wire:key="in-{{ $mid }}-{{ $candidate['id'] }}" wire:click="$set('inPlayerId', '{{ $candidate['id'] }}')" aria-pressed="{{ $on ? 'true' : 'false' }}" class="{{ $pick }} {{ $on ? $picked : $idle }}" data-test="in-option" data-player="{{ $candidate['id'] }}" data-source="{{ $candidate['source'] ?? 'waiting' }}" @if ($on) data-selected="1" @endif>
                                            <span class="flex min-w-0 items-center gap-2">
                                                <span class="truncate">{{ $candidate['name'] }}</span>
                                                @if ($mixed ?? false)
                                                    <x-gender-marker :gender="$candidate['gender'] ?? null" />
                                                @endif
                                            </span>
                                            @if (($candidate['source'] ?? 'waiting') === 'up_next')
                                                <span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-900 dark:bg-amber-900/60 dark:text-amber-100" data-test="up-next-label">{{ __('Up Next :n', ['n' => $candidate['up_next_number']]) }}</span>
                                            @else
                                                <span class="shrink-0 text-xs tabular-nums text-zinc-600 dark:text-zinc-400">{{ trans_choice(':count game|:count games', $candidate['games_played']) }}</span>
                                            @endif
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($mixed ?? false)
                <flux:text class="text-sm" data-test="swap-gender-hint">{{ __('Mixed doubles: swap in a player of the same gender as the player leaving.') }}</flux:text>
            @endif
            <flux:button variant="primary" class="min-h-12 w-full" wire:click="swap" :disabled="$outPlayerId === '' || $inPlayerId === ''" data-test="confirm-swap">{{ __('Swap') }}</flux:button>
        @else
            <flux:text>{{ $match['status'] === 'playing' ? __('Void this match? Nobody gets a game counted and the court is freed.') : __('Void this Up Next match? The players go back to the waiting list.') }}</flux:text>
            <flux:button variant="danger" class="min-h-12 w-full" wire:click="voidMatch" data-test="confirm-void">{{ __('Void match') }}</flux:button>
        @endif

        <flux:button variant="ghost" class="min-h-12 w-full" wire:click="closePanel">{{ __('Cancel') }}</flux:button>
    </div>
@endif
