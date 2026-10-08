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
            @php($match = $row['match'])
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
