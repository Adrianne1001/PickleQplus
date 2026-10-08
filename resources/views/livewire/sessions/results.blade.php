<div class="space-y-4" wire:poll.10s.visible data-test="results-panel">
    <div class="flex items-baseline justify-between gap-3">
        <h2 class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('Match history') }}</h2>
        @if ($total > 0)
            <span class="text-sm text-zinc-600 dark:text-zinc-400" data-test="results-count">{{ trans_choice(':count match|:count matches', $total) }}</span>
        @endif
    </div>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-circle" data-test="board-error">
            @foreach ($errors->all() as $message)
                <flux:callout.text>{{ $message }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    @if (count($results) > 0)
        <ul class="divide-y divide-zinc-200 rounded-2xl border border-zinc-200 bg-white shadow-xs dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900">
            @foreach ($results as $match)
                @php
                    $winner = $match['winner'] ?? null;
                    $names = fn (string $t) => collect($match['teams'][$t])->pluck('name')->implode(' & ');
                @endphp
                <li class="p-3 sm:p-4" wire:key="result-{{ $match['id'] }}" data-test="result-row">
                    <div class="flex items-start gap-3">
                        <div class="w-10 shrink-0 pt-0.5 text-sm font-bold tabular-nums text-zinc-500 dark:text-zinc-400">#{{ $match['number'] }}</div>

                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-zinc-600 dark:text-zinc-400">
                                @if ($match['court'])
                                    <span>{{ __('Court :n', ['n' => $match['court']]) }}</span>
                                    <span aria-hidden="true">·</span>
                                @endif
                                @if ($match['finished_at'])
                                    <span>{{ $match['finished_at']->format('g:i A') }}</span>
                                @endif
                                @if ($match['duration_minutes'] ?? null)
                                    <span aria-hidden="true">·</span>
                                    <span>{{ __(':n min', ['n' => $match['duration_minutes']]) }}</span>
                                @endif
                                @if ($match['exported'])
                                    <flux:badge size="sm" color="zinc" icon="check-circle" data-test="result-exported">{{ __('DUPR exported') }}</flux:badge>
                                @endif
                            </div>

                            <div class="grid gap-1 sm:grid-cols-[1fr_auto_1fr] sm:items-center sm:gap-3">
                                @foreach (['A', 'B'] as $team)
                                    @php($won = $winner === $team)
                                    @if ($team === 'B')
                                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 sm:text-center">{{ __('vs') }}</span>
                                    @endif
                                    <div @class([
                                        'flex min-w-0 items-center gap-2 rounded-lg px-2 py-1',
                                        'bg-brand-50 text-brand-900 ring-1 ring-brand-600/30 ring-inset dark:bg-brand-950/40 dark:text-brand-100 dark:ring-brand-500/40' => $won,
                                        'text-zinc-900 dark:text-white' => $winner === null,
                                        'text-zinc-500 dark:text-zinc-400' => $winner !== null && ! $won,
                                    ])>
                                        <span @class(['min-w-0 break-words', 'font-bold' => $won, 'font-medium' => ! $won])>{{ $names($team) }}</span>
                                        @if ($won)
                                            <flux:badge size="sm" color="green" icon="trophy" class="shrink-0" data-test="result-winner">{{ __('Won') }}</flux:badge>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <span class="text-xl font-bold tabular-nums text-zinc-900 dark:text-white" data-test="result-score">
                                <span @class(['text-brand-700 dark:text-brand-400' => $winner === 'A', 'text-zinc-500 dark:text-zinc-400 font-semibold' => $winner === 'B'])>{{ $match['score_a'] }}</span>
                                <span class="text-zinc-400">-</span>
                                <span @class(['text-brand-700 dark:text-brand-400' => $winner === 'B', 'text-zinc-500 dark:text-zinc-400 font-semibold' => $winner === 'A'])>{{ $match['score_b'] }}</span>
                            </span>

                            <flux:dropdown align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-vertical" class="min-h-10 min-w-10" :aria-label="__('Match actions')" data-test="result-actions" />
                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="edit({{ $match['id'] }})" data-test="edit-score-button">{{ __('Edit score') }}</flux:menu.item>
                                    @if ($match['id'] === $lastId && ! $session->isEnded())
                                        <flux:menu.item icon="arrow-uturn-left" wire:click="undoLast" data-test="undo-button">{{ __('Undo last result') }}</flux:menu.item>
                                    @endif
                                    @unless ($match['exported'])
                                        <flux:menu.item icon="trash" variant="danger" wire:click="confirmVoid({{ $match['id'] }})" data-test="void-result-button">{{ __('Void') }}</flux:menu.item>
                                    @endunless
                                </flux:menu>
                            </flux:dropdown>
                        </div>
                    </div>

                    @if ($editingId === $match['id'])
                        <form wire:submit="saveScore" class="mt-3 space-y-3 rounded-xl border-2 border-brand-600/60 bg-brand-50/50 p-4 dark:border-brand-500/60 dark:bg-brand-950/30" data-test="edit-score-form">
                            <div class="grid grid-cols-2 gap-4">
                                <flux:input wire:model="scoreA" type="number" min="0" inputmode="numeric" :label="__('Team A')" class:input="h-14! text-center text-2xl! font-bold tabular-nums" data-test="edit-score-a" />
                                <flux:input wire:model="scoreB" type="number" min="0" inputmode="numeric" :label="__('Team B')" class:input="h-14! text-center text-2xl! font-bold tabular-nums" data-test="edit-score-b" />
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <flux:button variant="primary" type="submit" class="min-h-12" data-test="save-score-button">{{ __('Save score') }}</flux:button>
                                <flux:button variant="ghost" type="button" class="min-h-12" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                            </div>
                        </form>
                    @elseif ($voidingId === $match['id'])
                        <div class="mt-3 space-y-3 rounded-xl border-2 border-red-300 bg-red-50 p-4 dark:border-red-800 dark:bg-red-950/30" data-test="void-result-panel">
                            <flux:text class="text-red-900 dark:text-red-200">{{ __('Void this finished match? It is removed from the players\' game counts and from stats. This cannot be undone.') }}</flux:text>
                            <div class="grid grid-cols-2 gap-2">
                                <flux:button variant="danger" class="min-h-12" wire:click="voidMatch" data-test="confirm-void-result">{{ __('Void match') }}</flux:button>
                                <flux:button variant="ghost" class="min-h-12" wire:click="cancelVoid">{{ __('Cancel') }}</flux:button>
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>

        @if ($total > count($results))
            <flux:button class="w-full min-h-12" wire:click="showMore" data-test="show-more-button">{{ __('Show more') }}</flux:button>
        @endif
    @else
        <x-empty-state icon="trophy" :title="__('No matches in the history yet.')" :description="__('Finished matches, who played and who won show up here.')" />
    @endif
</div>
