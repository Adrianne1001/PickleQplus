@props(['rows', 'showRank' => true, 'empty' => null, 'minGames' => null, 'caption' => null])

{{--
    Standings table, styled to screenshot well. Rows: rank, name, url? (staff profile link; absent on
    public pages), played, wins, losses, win_pct, point_diff. `minGames` shows "N / min games" progress
    on the unranked list. `caption` is a small branding line in the card footer.
    Phone: Played / W / L fold into a "W-L . N played" line under the name (no sideways scroll).
--}}
@php
    $medals = [
        1 => ['gold', __('Gold'), 'bg-amber-400 text-amber-950 ring-amber-500/40', 'bg-amber-50/80 dark:bg-amber-400/10'],
        2 => ['silver', __('Silver'), 'bg-zinc-300 text-zinc-900 ring-zinc-400/40', 'bg-zinc-100/80 dark:bg-zinc-300/10'],
        3 => ['bronze', __('Bronze'), 'bg-orange-400 text-orange-950 ring-orange-500/40', 'bg-orange-50/80 dark:bg-orange-400/10'],
    ];
@endphp
<div class="overflow-hidden rounded-2xl border border-brand-200 bg-white shadow-sm dark:border-brand-900 dark:bg-zinc-900" {{ $attributes }}>
    <table class="w-full text-sm">
        <thead class="bg-brand-50 text-xs uppercase tracking-wider text-brand-800 dark:bg-brand-950/70 dark:text-brand-200">
            <tr class="text-start">
                @if ($showRank)
                    <th scope="col" class="w-12 py-3 ps-3 pe-1 text-start font-semibold sm:ps-4">#</th>
                @endif
                <th scope="col" @class(['py-3 pe-2 text-start font-semibold', 'ps-4' => ! $showRank, 'ps-1' => $showRank])>{{ __('Player') }}</th>
                <th scope="col" class="hidden px-3 py-3 text-end font-semibold sm:table-cell">{{ __('Played') }}</th>
                <th scope="col" class="hidden px-3 py-3 text-end font-semibold sm:table-cell">{{ __('W') }}</th>
                <th scope="col" class="hidden px-3 py-3 text-end font-semibold sm:table-cell">{{ __('L') }}</th>
                <th scope="col" class="px-2 py-3 text-end font-semibold sm:px-3">{{ __('Win %') }}</th>
                <th scope="col" class="py-3 pe-3 ps-1 text-end font-semibold sm:pe-4">{{ __('+/-') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200/80 dark:divide-zinc-700/70">
            @forelse ($rows as $row)
                @php
                    $rank = $showRank ? ($row['rank'] ?? null) : null;
                    $medal = $rank !== null ? ($medals[$rank] ?? null) : null;
                    $diff = (int) $row['point_diff'];
                    $pct = max(0, min(100, (int) round((float) $row['win_pct'])));
                @endphp
                <tr data-test="stats-row" @if ($medal) data-medal="{{ $medal[0] }}" @endif @class(['hover:bg-zinc-50 dark:hover:bg-zinc-800/40' => ! $medal, $medal[3] ?? '' => $medal])>
                    @if ($showRank)
                        <td class="py-3 ps-3 pe-1 sm:ps-4">
                            @if ($medal)
                                <span class="inline-flex size-8 items-center justify-center rounded-full text-sm font-extrabold tabular-nums shadow-sm ring-2 {{ $medal[2] }}" data-test="medal" title="{{ $medal[1] }}">
                                    <span class="sr-only">{{ $medal[1] }} </span>{{ $rank }}
                                </span>
                            @else
                                <span class="inline-flex size-8 items-center justify-center text-sm font-semibold tabular-nums text-zinc-600 dark:text-zinc-400">{{ $rank ?? '–' }}</span>
                            @endif
                        </td>
                    @endif
                    <td @class(['py-3 pe-2', 'ps-4' => ! $showRank, 'ps-1' => $showRank])>
                        <div class="flex items-center gap-2.5">
                            <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ \App\Support\StatsPresenter::avatarClasses($row['name']) }}" aria-hidden="true">{{ \App\Support\StatsPresenter::initials($row['name']) }}</span>
                            <div class="min-w-0">
                                <div @class(['truncate font-semibold', 'text-base' => $medal])>
                                    @if (! empty($row['url']))
                                        <a href="{{ $row['url'] }}" wire:navigate class="hover:text-brand-700 hover:underline dark:hover:text-brand-400">{{ $row['name'] }}</a>
                                    @else
                                        {{ $row['name'] }}
                                    @endif
                                </div>
                                <div class="mt-0.5 text-xs tabular-nums text-zinc-600 dark:text-zinc-400 sm:hidden" data-test="record-line">
                                    <span class="font-semibold text-emerald-700 dark:text-emerald-400">{{ $row['wins'] }}</span>–<span class="font-semibold text-rose-700 dark:text-rose-400">{{ $row['losses'] }}</span>
                                    · {{ $row['played'] }} {{ __('played') }}
                                </div>
                                @if (! $showRank && $minGames)
                                    <div class="mt-0.5 text-xs tabular-nums text-zinc-600 dark:text-zinc-400" data-test="min-games-progress">{{ $row['played'] }} / {{ $minGames }} {{ __('games') }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="hidden px-3 py-3 text-end tabular-nums sm:table-cell">{{ $row['played'] }}</td>
                    <td class="hidden px-3 py-3 text-end font-semibold tabular-nums text-emerald-700 dark:text-emerald-400 sm:table-cell">{{ $row['wins'] }}</td>
                    <td class="hidden px-3 py-3 text-end font-semibold tabular-nums text-rose-700 dark:text-rose-400 sm:table-cell">{{ $row['losses'] }}</td>
                    <td class="px-2 py-3 sm:px-3">
                        <div class="ms-auto w-14 text-end sm:w-20">
                            <div class="text-sm font-bold tabular-nums">{{ $row['win_pct'] }}%</div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" aria-hidden="true">
                                <div class="h-full rounded-full bg-brand-600 dark:bg-brand-500" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>
                    </td>
                    <td class="py-3 pe-3 ps-1 text-end sm:pe-4">
                        <span @class([
                            'inline-block min-w-11 rounded-full px-2 py-0.5 text-center text-xs font-bold tabular-nums',
                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-300' => $diff > 0,
                            'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-300' => $diff < 0,
                            'bg-zinc-100 text-zinc-700 dark:bg-zinc-700/60 dark:text-zinc-300' => $diff === 0,
                        ])>{{ $diff > 0 ? '+' : ($diff < 0 ? '−' : '') }}{{ abs($diff) }}</span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showRank ? 7 : 6 }}" class="px-4 py-8 text-center text-zinc-600 dark:text-zinc-400">{{ $empty ?? __('No finished matches yet.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    @if ($caption)
        <p class="border-t border-brand-100 bg-brand-50/60 px-4 py-2 text-center text-xs font-medium text-brand-800 dark:border-brand-900 dark:bg-brand-950/50 dark:text-brand-200" data-test="table-caption">{{ $caption }}</p>
    @endif
</div>
