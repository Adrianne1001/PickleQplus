@php
    // Skill courts: one section per group. Every other mode: a single section.
    $sections = $groups === []
        ? [['group' => null, 'matches' => $staged]]
        : array_map(fn (array $g): array => [
            'group' => $g,
            'matches' => array_values(array_filter($staged, fn (array $m): bool => ($m['group'] ?? null) === $g['index'])),
        ], $groups);
@endphp

<div class="space-y-4" wire:poll.10s.visible data-test="up-next-panel">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <h2 class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('Up Next') }}</h2>

        <div class="flex flex-wrap items-end gap-4 rounded-xl bg-zinc-100 px-4 py-2 dark:bg-zinc-800">
            <flux:field variant="inline">
                <flux:switch wire:model.live="autoFill" data-test="auto-fill-toggle" />
                <flux:label>{{ __('Auto-fill courts') }}</flux:label>
            </flux:field>
            <flux:select wire:model.live="upNextCount" :label="__('Up Next slots')" class="w-24" data-test="up-next-count">
                @foreach ([1, 2, 3] as $n)
                    <flux:select.option value="{{ $n }}">{{ $n }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-circle" data-test="board-error">
            @foreach ($errors->all() as $message)
                <flux:callout.text>{{ $message }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    @if ($groups === [] && $freeCourts !== [] && $staged !== [])
        <flux:select wire:model="courtChoice.0" :label="__('Start on court')" data-test="court-choice">
            <flux:select.option value="">{{ __('Lowest free court (:n)', ['n' => $freeCourts[0]]) }}</flux:select.option>
            @foreach ($freeCourts as $court)
                <flux:select.option value="{{ $court }}">{{ __('Court :n', ['n' => $court]) }}</flux:select.option>
            @endforeach
        </flux:select>
    @endif

    @foreach ($sections as $section)
        @php
            $group = $section['group'];
            $groupFree = $group === null ? $freeCourts : array_values(array_intersect($group['courts'], $freeCourts));
        @endphp
        <section class="space-y-3" @if ($group) aria-label="{{ $group['label'] }}" data-test="up-next-group" data-group="{{ $group['index'] }}" @endif>
            @if ($group)
                <div class="flex flex-wrap items-center gap-2" data-test="group-header">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ $group['label'] }}</h3>
                    <flux:badge color="amber" size="sm">★{{ $group['min_stars'] }}–{{ $group['max_stars'] }}</flux:badge>
                    <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ __(':waiting waiting, :staged staged', ['waiting' => $group['waiting'], 'staged' => $group['staged']]) }}</span>
                </div>
            @endif

            <div class="grid gap-4 lg:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                @forelse ($section['matches'] as $match)
                    <div class="overflow-hidden rounded-2xl border border-amber-300 bg-white shadow-xs dark:border-amber-700/60 dark:bg-zinc-900" wire:key="staged-{{ $match['id'] }}" data-test="staged-match">
                        <div class="flex items-center justify-between gap-2 bg-amber-50 px-4 py-2 dark:bg-amber-950/50">
                            <span class="text-xs font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300">{{ __('Staged') }} #{{ $loop->iteration }}</span>
                            <span class="text-xs font-medium text-amber-800 dark:text-amber-300">{{ $groupFree === [] ? __('Waiting for a court') : __('Court :n is free', ['n' => $groupFree[0]]) }}</span>
                        </div>
                        <div class="p-4">
                            <x-match-teams :match="$match" :mixed="$mixed" />

                            @if ($group && $groupFree !== [])
                                <flux:select wire:model="courtChoice.{{ $match['id'] }}" :label="__('Start on court')" class="mt-3" data-test="court-choice">
                                    <flux:select.option value="">{{ __('Lowest free court (:n)', ['n' => $groupFree[0]]) }}</flux:select.option>
                                    @foreach ($groupFree as $court)
                                        <flux:select.option value="{{ $court }}">{{ __('Court :n', ['n' => $court]) }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            @endif

                            <div class="mt-4 space-y-2">
                                <flux:button variant="primary" icon="play" class="min-h-14 w-full text-base" wire:click="start({{ $match['id'] }})" :disabled="$groupFree === []" data-test="start-match-button">
                                    {{ $groupFree === [] ? __('No free court') : __('Start') }}
                                </flux:button>
                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-2 2xl:grid-cols-4">
                                    <flux:button class="min-h-12" icon="arrow-path" wire:click="reroll({{ $match['id'] }})" data-test="reroll-button">{{ __('Re-roll') }}</flux:button>
                                    <flux:button class="min-h-12" icon="arrows-right-left" wire:click="openPanel('swap', {{ $match['id'] }})" data-test="swap-button">{{ __('Swap') }}</flux:button>
                                    <flux:button class="min-h-12" icon="user-minus" wire:click="openPanel('remove', {{ $match['id'] }})" data-test="remove-button">{{ __('Remove') }}</flux:button>
                                    <flux:button variant="ghost" class="min-h-12 text-red-700! dark:text-red-400!" icon="x-circle" wire:click="openPanel('void', {{ $match['id'] }})" data-test="void-button">{{ __('Void') }}</flux:button>
                                </div>
                            </div>

                            @include('livewire.sessions.partials.match-panel', ['match' => $match, 'candidates' => $this->candidates])
                        </div>
                    </div>
                @empty
                    <div class="rounded-2xl border border-dashed border-zinc-300 px-4 py-6 text-center lg:col-span-2 xl:col-span-1 2xl:col-span-2 dark:border-zinc-600">
                        @if ($group)
                            <flux:text data-test="group-empty">{{ __('Nothing staged for this group.') }}</flux:text>
                        @else
                            <flux:text data-test="up-next-empty">{{ __('Nothing staged. Up Next fills when four players are waiting.') }}</flux:text>
                        @endif
                    </div>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
