<div class="space-y-4" wire:poll.10s.visible data-test="waiting-panel">
    <div class="flex flex-wrap items-center gap-3">
        <h2 class="text-lg font-bold tracking-tight text-zinc-900 dark:text-white">{{ __('Waiting (:count)', ['count' => count($waiting)]) }}</h2>
        @if ($mixed)
            <flux:badge color="purple" size="sm" data-test="mode-label">{{ __('Mixed doubles') }}</flux:badge>
        @endif
    </div>

    @if ($errors->any())
        <flux:callout variant="danger" icon="exclamation-circle" data-test="waiting-error">
            @foreach ($errors->all() as $message)
                <flux:callout.text>{{ $message }}</flux:callout.text>
            @endforeach
        </flux:callout>
    @endif

    @if ($mixed && $unplaceable > 0)
        <flux:callout variant="warning" icon="exclamation-triangle" data-test="unplaceable-banner">
            <flux:callout.text>{{ trans_choice(':count waiting player has no gender and will not be placed. Set M or W below.|:count waiting players have no gender and will not be placed. Set M or W below.', $unplaceable, ['count' => $unplaceable]) }}</flux:callout.text>
        </flux:callout>
    @endif

    @php
        // Skill courts: one section per group, plus anyone with no star rating. Other modes: one list.
        $sections = $groups === []
            ? [['group' => null, 'rows' => $waiting]]
            : array_values(array_filter([
                ...array_map(fn (array $g): array => ['group' => $g, 'rows' => array_values(array_filter($waiting, fn (array $r): bool => $r['group'] === $g['index']))], $groups),
                ['group' => null, 'rows' => array_values(array_filter($waiting, fn (array $r): bool => $r['group'] === null))],
            ], fn (array $sec): bool => $sec['group'] !== null || $sec['rows'] !== []));
    @endphp

    @foreach ($sections as $section)
        @php($group = $section['group'])
        <section class="space-y-2" @if ($group) aria-label="{{ $group['label'] }}" data-test="waiting-group" data-group="{{ $group['index'] }}" @endif>
            @if ($group)
                <div class="flex flex-wrap items-center gap-2" data-test="group-header">
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">{{ $group['label'] }}</h3>
                    <flux:badge color="amber" size="sm">★{{ $group['min_stars'] }}–{{ $group['max_stars'] }}</flux:badge>
                    <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ __(':waiting waiting, :staged staged', ['waiting' => $group['waiting'], 'staged' => $group['staged']]) }}</span>
                </div>
            @elseif ($groups !== [])
                <h3 class="text-base font-semibold text-zinc-900 dark:text-white" data-test="group-header">{{ __('Not rated, will not be placed') }}</h3>
            @endif
            <ol class="divide-y divide-zinc-200 overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-900" data-test="waiting-list">
                @forelse ($section['rows'] as $i => $row)
                    <li class="flex items-start gap-3 px-4 py-3" wire:key="waiting-{{ $row['id'] }}" data-test="waiting-row">
                        <span data-test="waiting-position" class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-sm font-semibold tabular-nums text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">{{ $row['group_position'] ?? ($i + 1) }}</span>
                        <div class="min-w-0 flex-1 space-y-1">
                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span class="text-base font-semibold text-zinc-900 dark:text-white">{{ $row['name'] }}</span>
                                @if ($mixed)
                                    <x-gender-marker :gender="$row['gender']" />
                                @endif
                                <x-star-rating :stars="$row['stars']" class="text-sm" />
                            </div>
                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                                <span>{{ trans_choice(':count game|:count games', $row['games_played']) }}</span>
                                <span>{{ __('waited :min min', ['min' => $row['waited_minutes']]) }}</span>
                            </div>
                            @if ($row['needs_gender'])
                                <div class="flex flex-wrap items-center gap-2 pt-1" data-test="needs-gender">
                                    <flux:badge color="amber" size="sm">{{ __('No gender, will not be placed') }}</flux:badge>
                                    <flux:button size="sm" class="min-h-11 min-w-11" wire:click="setGender({{ $row['id'] }}, 'man')" aria-label="{{ __('Set :name as a man', ['name' => $row['name']]) }}" data-test="set-man">M</flux:button>
                                    <flux:button size="sm" class="min-h-11 min-w-11" wire:click="setGender({{ $row['id'] }}, 'woman')" aria-label="{{ __('Set :name as a woman', ['name' => $row['name']]) }}" data-test="set-woman">W</flux:button>
                                </div>
                            @endif
                        </div>
                        @if ($row['estimate_minutes'] !== null)
                            <flux:badge color="blue" size="sm" data-test="wait-estimate">{{ __('~:min min', ['min' => $row['estimate_minutes']]) }}</flux:badge>
                        @else
                            <flux:badge color="zinc" size="sm" data-test="wait-estimate">{{ __('not placed') }}</flux:badge>
                        @endif
                    </li>
                @empty
                    <li class="px-4 py-8 text-center text-sm text-zinc-600 dark:text-zinc-400">{{ __('Nobody is waiting outside Up Next.') }}</li>
                @endforelse
            </ol>
        </section>
    @endforeach

    @if ($onBreak !== [])
        <div data-test="break-list">
            <h3 class="flex items-center gap-2 text-base font-semibold text-zinc-900 dark:text-white">
                <flux:icon.pause-circle class="size-5 text-amber-600 dark:text-amber-400" />
                {{ __('On break (:count)', ['count' => count($onBreak)]) }}
            </h3>
            <ul class="mt-2 divide-y divide-amber-200 overflow-hidden rounded-2xl border border-amber-200 bg-amber-50/60 dark:divide-amber-900 dark:border-amber-900 dark:bg-amber-950/30">
                @foreach ($onBreak as $row)
                    <li class="flex items-center justify-between gap-2 px-4 py-2.5" wire:key="break-{{ $row['id'] }}">
                        <span class="flex items-center gap-2 font-medium text-zinc-900 dark:text-white">{{ $row['name'] }}@if ($mixed) <x-gender-marker :gender="$row['gender']" />@endif</span>
                        <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ trans_choice(':count game|:count games', $row['games_played']) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
