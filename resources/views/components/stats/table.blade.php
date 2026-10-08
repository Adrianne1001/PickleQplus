@props(['rows', 'showRank' => true, 'empty' => null])

{{--
    Standings table. Rows: rank, name, nickname?, url? (staff profile link; absent on public pages),
    played, wins, losses, win_pct, point_diff.
--}}
<div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-700 dark:bg-zinc-900" {{ $attributes }}>
    <table class="w-full text-sm">
        <thead class="bg-zinc-50 text-xs uppercase tracking-wider text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-400">
            <tr class="text-start">
                @if ($showRank)
                    <th scope="col" class="px-4 py-3 text-start font-semibold">#</th>
                @endif
                <th scope="col" class="px-4 py-3 text-start font-semibold">{{ __('Player') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-semibold">{{ __('Played') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-semibold">{{ __('W') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-semibold">{{ __('L') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-semibold">{{ __('Win %') }}</th>
                <th scope="col" class="px-4 py-3 text-end font-semibold">{{ __('+/-') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($rows as $row)
                <tr data-test="stats-row" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/40">
                    @if ($showRank)
                        <td class="px-4 py-3 tabular-nums text-zinc-600 dark:text-zinc-400">{{ $row['rank'] ?? '–' }}</td>
                    @endif
                    <td class="px-4 py-3 font-medium">
                        @if (! empty($row['url']))
                            <a href="{{ $row['url'] }}" wire:navigate class="hover:text-brand-700 hover:underline dark:hover:text-brand-400">{{ $row['name'] }}</a>
                        @else
                            {{ $row['name'] }}
                        @endif
                        @if (! empty($row['nickname']))
                            <span class="font-normal text-zinc-600 dark:text-zinc-400">"{{ $row['nickname'] }}"</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-end tabular-nums">{{ $row['played'] }}</td>
                    <td class="px-4 py-3 text-end tabular-nums">{{ $row['wins'] }}</td>
                    <td class="px-4 py-3 text-end tabular-nums">{{ $row['losses'] }}</td>
                    <td class="px-4 py-3 text-end tabular-nums">{{ $row['win_pct'] }}%</td>
                    <td class="px-4 py-3 text-end tabular-nums">{{ $row['point_diff'] > 0 ? '+' : '' }}{{ $row['point_diff'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showRank ? 7 : 6 }}" class="px-4 py-8 text-center text-zinc-600 dark:text-zinc-400">{{ $empty ?? __('No finished matches yet.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
