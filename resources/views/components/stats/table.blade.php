@props(['rows', 'showRank' => true, 'empty' => null])

{{--
    Standings table. Rows: rank, name, nickname?, url? (staff profile link; absent on public pages),
    played, wins, losses, win_pct, point_diff.
--}}
<div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700" {{ $attributes }}>
    <table class="w-full text-sm">
        <thead class="bg-zinc-50 dark:bg-zinc-900">
            <tr class="text-start">
                @if ($showRank)
                    <th scope="col" class="px-3 py-2 text-start font-medium">#</th>
                @endif
                <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Player') }}</th>
                <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Played') }}</th>
                <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('W') }}</th>
                <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('L') }}</th>
                <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Win %') }}</th>
                <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('+/-') }}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($rows as $row)
                <tr data-test="stats-row">
                    @if ($showRank)
                        <td class="px-3 py-2 tabular-nums text-zinc-500">{{ $row['rank'] ?? '–' }}</td>
                    @endif
                    <td class="px-3 py-2 font-medium">
                        @if (! empty($row['url']))
                            <a href="{{ $row['url'] }}" wire:navigate class="hover:underline">{{ $row['name'] }}</a>
                        @else
                            {{ $row['name'] }}
                        @endif
                        @if (! empty($row['nickname']))
                            <span class="font-normal text-zinc-500">"{{ $row['nickname'] }}"</span>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ $row['played'] }}</td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ $row['wins'] }}</td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ $row['losses'] }}</td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ $row['win_pct'] }}%</td>
                    <td class="px-3 py-2 text-end tabular-nums">{{ $row['point_diff'] > 0 ? '+' : '' }}{{ $row['point_diff'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="{{ $showRank ? 7 : 6 }}" class="px-3 py-6 text-center text-zinc-500">{{ $empty ?? __('No finished matches yet.') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
