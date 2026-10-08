@props(['matches'])

{{-- Match log rows from StatsService::matchLog / publicEndedSession. Void rows are greyed out. --}}
<div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-700 dark:bg-zinc-900" {{ $attributes }}>
    <table class="w-full text-sm">
        <thead class="bg-zinc-50 text-xs uppercase tracking-wider text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-400">
            <tr>
                <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Court') }}</th>
                <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Finished') }}</th>
                <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Teams') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-semibold">{{ __('Score') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-semibold">{{ __('Time') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($matches as $m)
                @php($void = $m['void'] ?? false)
                <tr @class(['text-zinc-600 dark:text-zinc-400' => $void]) data-test="match-log-row" @if ($void) data-void="1" @endif>
                    <td class="px-4 py-3 tabular-nums">{{ $m['court'] ?? '–' }}</td>
                    <td class="whitespace-nowrap px-4 py-3">{{ $m['finished_at']?->format('H:i') ?? '–' }}</td>
                    <td class="px-4 py-3">
                        <span @class(['line-through' => $void])>
                            {{ implode(' & ', $m['team_a']) }}
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('vs') }}</span>
                            {{ implode(' & ', $m['team_b']) }}
                        </span>
                        @if ($void)
                            <flux:badge size="sm" color="zinc">{{ __('void') }}</flux:badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-end font-semibold tabular-nums">{{ $m['score_a'] ?? '–' }} - {{ $m['score_b'] ?? '–' }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-end tabular-nums">{{ $m['duration_minutes'] !== null ? $m['duration_minutes'].' '.__('min') : '–' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-600 dark:text-zinc-400">{{ __('No finished matches yet.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
