<div class="space-y-4" wire:poll.10s.visible data-test="waiting-panel">
    <flux:heading size="lg">{{ __('Waiting (:count)', ['count' => count($waiting)]) }}</flux:heading>

    <ol class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700" data-test="waiting-list">
        @forelse ($waiting as $i => $row)
            <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-3" wire:key="waiting-{{ $row['id'] }}" data-test="waiting-row">
                <div class="flex items-center gap-3">
                    <span class="w-6 text-sm tabular-nums text-zinc-500">{{ $i + 1 }}</span>
                    <span class="text-base font-medium">{{ $row['name'] }}</span>
                    <x-star-rating :stars="$row['stars']" />
                </div>
                <div class="flex items-center gap-4 text-sm text-zinc-600 dark:text-zinc-300">
                    <span>{{ trans_choice(':count game|:count games', $row['games_played']) }}</span>
                    <span>{{ __('waited :min min', ['min' => $row['waited_minutes']]) }}</span>
                    <flux:badge color="blue" size="sm" data-test="wait-estimate">{{ __('~:min min', ['min' => $row['estimate_minutes']]) }}</flux:badge>
                </div>
            </li>
        @empty
            <li class="px-4 py-6 text-center text-zinc-500">{{ __('Nobody is waiting outside Up Next.') }}</li>
        @endforelse
    </ol>

    @if ($onBreak !== [])
        <div data-test="break-list">
            <flux:heading>{{ __('On break (:count)', ['count' => count($onBreak)]) }}</flux:heading>
            <ul class="mt-2 divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($onBreak as $row)
                    <li class="flex items-center justify-between gap-2 px-4 py-2" wire:key="break-{{ $row['id'] }}">
                        <span class="font-medium">{{ $row['name'] }}</span>
                        <span class="text-sm text-zinc-500">{{ trans_choice(':count game|:count games', $row['games_played']) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
