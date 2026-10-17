@php
    $courtGroup = [];
    foreach ($groups as $g) {
        foreach ($g['courts'] as $n) {
            $courtGroup[$n] = $g['label'];
        }
    }
@endphp

<div class="space-y-4" wire:poll.10s.visible data-test="courts-panel">
    <div class="flex items-center justify-between gap-3">
        <h2 class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('Courts') }}</h2>
        <span class="text-sm tabular-nums text-zinc-600 dark:text-zinc-400">
            {{ __(':playing playing, :free free', ['playing' => collect($courts)->filter(fn ($r) => $r['match'])->count(), 'free' => collect($courts)->filter(fn ($r) => ! $r['match'])->count()]) }}
        </span>
    </div>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-circle" data-test="board-error">
            @foreach ($errors->all() as $message)
                <flux:callout.text>{{ $message }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
        @foreach ($courts as $row)
            @php $match = $row['match']; @endphp
            <div @class([
                'overflow-hidden rounded-2xl border bg-white shadow-xs dark:bg-zinc-900',
                'border-brand-600/50 ring-1 ring-brand-600/20 dark:border-brand-500/50' => $match,
                'border-dashed border-zinc-300 dark:border-zinc-600' => ! $match,
            ]) wire:key="court-{{ $row['court'] }}" data-test="court" data-court="{{ $row['court'] }}">
                <div @class([
                    'flex items-center justify-between gap-3 px-4 py-3',
                    'bg-brand-50 dark:bg-brand-950/60' => $match,
                    'bg-zinc-50 dark:bg-zinc-800/50' => ! $match,
                ])>
                    <span class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <span class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('Court :n', ['n' => $row['court']]) }}</span>
                        @if (isset($courtGroup[$row['court']]))
                            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-300" data-test="court-group">{{ $courtGroup[$row['court']] }}</span>
                        @endif
                    </span>
                    @if ($match)
                        <flux:badge color="green" size="sm" icon="clock">{{ __(':min min', ['min' => $match['elapsed_minutes']]) }}</flux:badge>
                    @else
                        <flux:badge color="zinc" size="sm">{{ __('Free') }}</flux:badge>
                    @endif
                </div>

                <div class="p-4">
                    @if ($match)
                        <x-match-teams :match="$match" :mixed="$mixed" />

                        <div class="mt-4 space-y-2">
                            <flux:button variant="primary" icon="pencil-square" class="min-h-14 w-full text-base" wire:click="openPanel('score', {{ $match['id'] }})" data-test="score-button">{{ __('Enter score') }}</flux:button>
                            <div class="grid grid-cols-3 gap-2">
                                <flux:button class="min-h-12" icon="arrows-right-left" wire:click="openPanel('swap', {{ $match['id'] }})" data-test="swap-button">{{ __('Swap') }}</flux:button>
                                <flux:button class="min-h-12" icon="user-minus" wire:click="openPanel('remove', {{ $match['id'] }})" data-test="remove-button">{{ __('Remove') }}</flux:button>
                                <flux:button variant="ghost" class="min-h-12 text-red-700! dark:text-red-400!" icon="x-circle" wire:click="openPanel('void', {{ $match['id'] }})" data-test="void-button">{{ __('Void') }}</flux:button>
                            </div>
                        </div>

                        @if ($panel === 'score' && $panelMatchId === $match['id'])
                            <form wire:submit="finish" class="mt-4 space-y-4 rounded-xl border-2 border-brand-600/60 bg-brand-50/50 p-4 dark:border-brand-500/60 dark:bg-brand-950/30" data-test="score-form">
                                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ __('Final score') }}</p>
                                <div class="grid grid-cols-2 gap-4">
                                    <flux:input wire:model.live.debounce.300ms="scoreA" type="number" min="0" inputmode="numeric" :label="__('Team A')" class:input="h-16! text-center text-3xl! font-bold tabular-nums" data-test="score-a" />
                                    <flux:input wire:model.live.debounce.300ms="scoreB" type="number" min="0" inputmode="numeric" :label="__('Team B')" class:input="h-16! text-center text-3xl! font-bold tabular-nums" data-test="score-b" />
                                </div>
                                @if ($this->scoreHint)
                                    <flux:text class="text-sm font-medium text-amber-800 dark:text-amber-300" data-test="score-hint">{{ $this->scoreHint }}</flux:text>
                                @endif
                                <div class="grid grid-cols-2 gap-2">
                                    <flux:button variant="primary" type="submit" class="min-h-14 text-base" data-test="finish-button">{{ __('Finish match') }}</flux:button>
                                    <flux:button variant="ghost" type="button" class="min-h-14" wire:click="closePanel">{{ __('Cancel') }}</flux:button>
                                </div>
                            </form>
                        @endif

                        @include('livewire.sessions.partials.match-panel', ['match' => $match, 'candidates' => $this->candidates])
                    @elseif ($live && $row['up_next'] !== [])
                        @php
                            $first = $row['up_next'][0];
                            $modal = 'choose-matchup-'.$row['court'];
                        @endphp
                        <div class="rounded-xl bg-amber-50 p-3 ring-1 ring-amber-200 ring-inset dark:bg-amber-950/40 dark:ring-amber-800/60" data-test="court-up-next" data-match="{{ $first['id'] }}">
                            <div class="mb-1.5 text-xs font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">{{ __('Up Next :n', ['n' => $first['number']]) }}</div>
                            <div class="grid gap-1 text-base font-semibold text-zinc-900 sm:grid-cols-[1fr_auto_1fr] sm:items-center sm:gap-3 dark:text-white">
                                <span>{{ collect($first['teams']['A'])->pluck('name')->join(' & ') }}</span>
                                <span class="text-xs font-medium uppercase text-zinc-600 dark:text-zinc-400">{{ __('vs') }}</span>
                                <span>{{ collect($first['teams']['B'])->pluck('name')->join(' & ') }}</span>
                            </div>
                        </div>

                        <div class="mt-4 space-y-2">
                            <flux:button variant="primary" icon="play" class="min-h-14 w-full text-base" wire:click="startOnCourt({{ $row['court'] }}, {{ $first['id'] }})" data-test="court-start-up-next">{{ __('Start Up Next :n', ['n' => $first['number']]) }}</flux:button>
                            <flux:modal.trigger :name="$modal">
                                <flux:button class="min-h-12 w-full" icon="user-group" data-test="choose-players-button">{{ __('Choose players') }}</flux:button>
                            </flux:modal.trigger>
                        </div>

                        <flux:modal :name="$modal" class="w-full max-w-lg" data-test="choose-matchup-modal" data-court="{{ $row['court'] }}">
                            <div class="space-y-4">
                                <flux:heading size="lg">{{ __('Choose a matchup for Court :n', ['n' => $row['court']]) }}</flux:heading>

                                <div class="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
                                    @foreach ($row['up_next'] as $option)
                                        <div class="overflow-hidden rounded-2xl border border-amber-300 bg-white dark:border-amber-700/60 dark:bg-zinc-900" wire:key="court-{{ $row['court'] }}-option-{{ $option['id'] }}" data-test="choose-matchup-option" data-match="{{ $option['id'] }}">
                                            <div class="bg-amber-50 px-4 py-2 text-xs font-bold uppercase tracking-wider text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">{{ __('Up Next :n', ['n' => $option['number']]) }}</div>
                                            <div class="space-y-3 p-4">
                                                <x-match-teams :match="$option" :mixed="$mixed" />
                                                <flux:button variant="primary" icon="play" class="min-h-14 w-full text-base" wire:click="startOnCourt({{ $row['court'] }}, {{ $option['id'] }})" data-test="choose-matchup-start">{{ __('Start Up Next :n', ['n' => $option['number']]) }}</flux:button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="flex justify-end">
                                    <flux:modal.close>
                                        <flux:button variant="ghost" class="min-h-12" data-test="choose-matchup-cancel">{{ __('Cancel') }}</flux:button>
                                    </flux:modal.close>
                                </div>
                            </div>
                        </flux:modal>
                    @elseif ($live)
                        <div class="flex items-center gap-3 py-4 text-zinc-600 dark:text-zinc-400">
                            <flux:icon.sparkles class="size-6 shrink-0" />
                            <flux:text data-test="court-no-up-next">{{ __('No one up next for this court') }}</flux:text>
                        </div>
                    @else
                        <div class="flex items-center gap-3 py-4 text-zinc-600 dark:text-zinc-400">
                            <flux:icon.sparkles class="size-6 shrink-0" />
                            <flux:text>{{ __('Nobody is playing on this court.') }}</flux:text>
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
