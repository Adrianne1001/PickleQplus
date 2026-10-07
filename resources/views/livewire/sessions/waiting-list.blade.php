<div class="space-y-4" wire:poll.10s.visible data-test="waiting-panel">
    <div class="flex flex-wrap items-center gap-3">
        <flux:heading size="lg">{{ __('Waiting (:count)', ['count' => count($waiting)]) }}</flux:heading>
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
                    <flux:heading>{{ $group['label'] }}</flux:heading>
                    <flux:badge color="amber" size="sm">★{{ $group['min_stars'] }}–{{ $group['max_stars'] }}</flux:badge>
                    <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ __(':waiting waiting, :staged staged', ['waiting' => $group['waiting'], 'staged' => $group['staged']]) }}</span>
                </div>
            @elseif ($groups !== [])
                <flux:heading data-test="group-header">{{ __('Not rated, will not be placed') }}</flux:heading>
            @endif
            <ol class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="waiting-list">
                @forelse ($section['rows'] as $i => $row)
                    <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3" wire:key="waiting-{{ $row['id'] }}" data-test="waiting-row">
                        <div class="flex items-center gap-3">
                            <span class="w-6 text-sm tabular-nums text-zinc-500">{{ $row['group_position'] ?? ($i + 1) }}</span>
                            <span class="text-base font-medium">{{ $row['name'] }}</span>
                            @if ($mixed)
                                <x-gender-marker :gender="$row['gender']" />
                            @endif
                            <x-star-rating :stars="$row['stars']" />
                        </div>
                        <div class="flex items-center gap-4 text-sm text-zinc-600 dark:text-zinc-300">
                            <span>{{ trans_choice(':count game|:count games', $row['games_played']) }}</span>
                            <span>{{ __('waited :min min', ['min' => $row['waited_minutes']]) }}</span>
                            @if ($row['needs_gender'])
                                <span class="flex items-center gap-2" data-test="needs-gender">
                                    <flux:badge color="amber" size="sm">{{ __('No gender, will not be placed') }}</flux:badge>
                                    <flux:button size="sm" class="min-h-11 min-w-11" wire:click="setGender({{ $row['id'] }}, 'man')" aria-label="{{ __('Set :name as a man', ['name' => $row['name']]) }}" data-test="set-man">M</flux:button>
                                    <flux:button size="sm" class="min-h-11 min-w-11" wire:click="setGender({{ $row['id'] }}, 'woman')" aria-label="{{ __('Set :name as a woman', ['name' => $row['name']]) }}" data-test="set-woman">W</flux:button>
                                </span>
                            @endif
                            @if ($row['estimate_minutes'] !== null)
                                <flux:badge color="blue" size="sm" data-test="wait-estimate">{{ __('~:min min', ['min' => $row['estimate_minutes']]) }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm" data-test="wait-estimate">{{ __('not placed') }}</flux:badge>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-6 text-center text-zinc-500">{{ __('Nobody is waiting outside Up Next.') }}</li>
                @endforelse
            </ol>
        </section>
    @endforeach

    @if ($onBreak !== [])
        <div data-test="break-list">
            <flux:heading>{{ __('On break (:count)', ['count' => count($onBreak)]) }}</flux:heading>
            <ul class="mt-2 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($onBreak as $row)
                    <li class="flex items-center justify-between gap-2 px-4 py-2" wire:key="break-{{ $row['id'] }}">
                        <span class="flex items-center gap-2 font-medium">{{ $row['name'] }}@if ($mixed) <x-gender-marker :gender="$row['gender']" />@endif</span>
                        <span class="text-sm text-zinc-500">{{ trans_choice(':count game|:count games', $row['games_played']) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
