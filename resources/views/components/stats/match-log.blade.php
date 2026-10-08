@props(['matches', 'sessionDate' => null, 'caption' => null])

{{--
    Match log rows from StatsService::matchLog / SessionResultsService (matches). Void rows stay greyed and struck
    through. The winning team is bold with a "Won" marker (none when the score is missing or tied).
    A finish on a different day than the session (or the previous row) shows the date too.
    Phone: each row becomes a stacked card.
--}}
@php
    $prevDay = $sessionDate?->toDateString();
@endphp
<div class="overflow-hidden rounded-2xl border border-brand-200 bg-white shadow-sm dark:border-brand-900 dark:bg-zinc-900" {{ $attributes }}>
    <table class="w-full text-sm max-sm:block">
        <thead class="bg-brand-50 text-xs uppercase tracking-wider text-brand-800 dark:bg-brand-950/70 dark:text-brand-200 max-sm:hidden">
            <tr>
                <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Court') }}</th>
                <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Finished') }}</th>
                <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Teams') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-semibold">{{ __('Score') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-semibold">{{ __('Time') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200/80 dark:divide-zinc-700/70 max-sm:block">
            @forelse ($matches as $m)
                @php
                    $void = $m['void'] ?? false;
                    $a = $m['score_a'] ?? null;
                    $b = $m['score_b'] ?? null;
                    $winner = (! $void && $a !== null && $b !== null && (int) $a !== (int) $b) ? ((int) $a > (int) $b ? 'a' : 'b') : null;
                    $finished = $m['finished_at'] ?? null;
                    $day = $finished?->toDateString();
                    $showDate = $finished !== null && $prevDay !== null && $day !== $prevDay;
                    if ($sessionDate === null && $day !== null) {
                        $prevDay = $day;
                    }
                    $time = $finished === null ? '–' : $finished->format($showDate ? 'M j · H:i' : 'H:i');
                    $teams = ['a' => $m['team_a'], 'b' => $m['team_b']];
                    $score = ($a ?? '–').' - '.($b ?? '–');
                    $duration = \App\Support\StatsPresenter::duration($m['duration_minutes'] ?? null);
                @endphp
                <tr @class(['text-zinc-600 dark:text-zinc-400' => $void, 'max-sm:flex max-sm:flex-wrap max-sm:items-center max-sm:gap-x-3 max-sm:gap-y-2 max-sm:p-4'])
                    data-test="match-log-row" @if ($void) data-void="1" @endif>
                    <td class="px-4 py-3 max-sm:p-0">
                        <span class="inline-flex min-w-9 items-center justify-center rounded-lg bg-brand-100 px-2 py-1 text-xs font-bold tabular-nums text-brand-900 dark:bg-brand-500/20 dark:text-brand-200" data-test="court-badge">
                            <span class="sr-only">{{ __('Court') }} </span>{{ $m['court'] ?? '–' }}
                        </span>
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 tabular-nums max-sm:p-0 max-sm:text-xs" data-test="finished-at">{{ $time }}</td>
                    <td class="hidden text-xs tabular-nums max-sm:order-3 max-sm:ms-auto max-sm:block">{{ $duration }}</td>
                    <td class="px-4 py-3 max-sm:order-4 max-sm:w-full max-sm:p-0">
                        <div class="flex items-center justify-between gap-3">
                            <div @class(['min-w-0 space-y-1', 'line-through' => $void])>
                                @foreach ($teams as $side => $names)
                                    <div @class([
                                        'flex flex-wrap items-center gap-x-2',
                                        'font-bold text-zinc-900 dark:text-white' => $winner === $side && ! $void,
                                        'text-zinc-600 dark:text-zinc-400' => $winner !== null && $winner !== $side && ! $void,
                                    ]) @if ($winner === $side) data-winner="1" @endif>
                                        <span>{{ implode(' & ', $names) }}</span>
                                        @if ($winner === $side)
                                            <span class="inline-block rounded-full bg-brand-700 px-1.5 py-px text-[10px] font-bold uppercase tracking-wide text-white" data-test="won-marker">{{ __('Won') }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            <span class="shrink-0 rounded-full bg-zinc-100 px-3 py-1 text-sm font-extrabold tabular-nums text-zinc-900 dark:bg-zinc-800 dark:text-white sm:hidden">{{ $score }}</span>
                        </div>
                        @if ($void)
                            <flux:badge size="sm" color="zinc" class="mt-1">{{ __('void') }}</flux:badge>
                        @endif
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-end max-sm:hidden">
                        <span class="inline-block rounded-full bg-zinc-100 px-3 py-1 text-sm font-extrabold tabular-nums text-zinc-900 dark:bg-zinc-800 dark:text-white">{{ $score }}</span>
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-end tabular-nums max-sm:hidden">{{ $duration }}</td>
                </tr>
            @empty
                <tr class="max-sm:block"><td colspan="5" class="px-4 py-8 text-center text-zinc-600 dark:text-zinc-400 max-sm:block">{{ __('No finished matches yet.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
    @if ($caption)
        <p class="border-t border-brand-100 bg-brand-50/60 px-4 py-2 text-center text-xs font-medium text-brand-800 dark:border-brand-900 dark:bg-brand-950/50 dark:text-brand-200" data-test="table-caption">{{ $caption }}</p>
    @endif
</div>
