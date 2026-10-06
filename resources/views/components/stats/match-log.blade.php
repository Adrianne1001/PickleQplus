@props(['matches'])

{{-- Match log rows from StatsService::matchLog / publicEndedSession. Void rows are greyed out. --}}
<div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700" {{ $attributes }}>
    <table class="w-full text-sm">
        <thead class="bg-zinc-50 dark:bg-zinc-900">
            <tr>
                <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Court') }}</th>
                <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Finished') }}</th>
                <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Teams') }}</th>
                <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Score') }}</th>
                <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Time') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($matches as $m)
                @php($void = $m['void'] ?? false)
                <tr @class(['text-zinc-400 dark:text-zinc-500' => $void]) data-test="match-log-row" @if ($void) data-void="1" @endif>
                    <td class="px-3 py-2 tabular-nums">{{ $m['court'] ?? '–' }}</td>
                    <td class="whitespace-nowrap px-3 py-2">{{ $m['finished_at']?->format('H:i') ?? '–' }}</td>
                    <td class="px-3 py-2">
                        <span @class(['line-through' => $void])>
                            {{ implode(' & ', $m['team_a']) }}
                            <span class="text-zinc-500">{{ __('vs') }}</span>
                            {{ implode(' & ', $m['team_b']) }}
                        </span>
                        @if ($void)
                            <flux:badge size="sm" color="zinc">{{ __('void') }}</flux:badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-3 py-2 text-end font-semibold tabular-nums">{{ $m['score_a'] ?? '–' }} - {{ $m['score_b'] ?? '–' }}</td>
                    <td class="whitespace-nowrap px-3 py-2 text-end tabular-nums">{{ $m['duration_minutes'] !== null ? $m['duration_minutes'].' '.__('min') : '–' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-3 py-6 text-center text-zinc-500">{{ __('No finished matches yet.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
