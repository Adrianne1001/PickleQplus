@php
    $record = $profile['record'];
    $card = 'rounded-lg border border-zinc-200 p-4 dark:border-zinc-700';
@endphp

<section class="w-full max-w-4xl space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="xl" level="1" class="flex flex-wrap items-center gap-3" data-test="player-name">
                {{ $player->name }}
                @if ($player->nickname)
                    <span class="font-normal text-zinc-500">"{{ $player->nickname }}"</span>
                @endif
                <flux:badge size="sm" :color="$player->active ? 'green' : 'zinc'">{{ $player->active ? __('Active') : __('Inactive') }}</flux:badge>
            </flux:heading>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-zinc-600 dark:text-zinc-300">
                <x-star-rating :stars="$player->stars" />
                <span>{{ __('DUPR ID') }}: <span class="font-mono">{{ $player->dupr_id ?? '–' }}</span></span>
                <span>{{ __('DUPR rating') }}: {{ $player->dupr_rating !== null ? number_format((float) $player->dupr_rating, 2) : '–' }}</span>
            </div>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live="period" :label="__('Period')" class="w-48" data-test="period-select">
                @foreach ($periods as $p)
                    <flux:select.option value="{{ $p->value }}">{{ __($p->label()) }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button icon="arrow-left" :href="route('clubs.players.index', $club)" wire:navigate>{{ __('Players') }}</flux:button>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6" data-test="record-cards">
        <div class="{{ $card }}"><div class="text-sm text-zinc-500">{{ __('Played') }}</div><div class="text-2xl font-semibold tabular-nums" data-test="record-played">{{ $record['played'] }}</div></div>
        <div class="{{ $card }}"><div class="text-sm text-zinc-500">{{ __('W - L') }}</div><div class="text-2xl font-semibold tabular-nums" data-test="record-wl">{{ $record['wins'] }} - {{ $record['losses'] }}</div></div>
        <div class="{{ $card }}"><div class="text-sm text-zinc-500">{{ __('Win %') }}</div><div class="text-2xl font-semibold tabular-nums" data-test="record-winpct">{{ $record['win_pct'] !== null ? $record['win_pct'].'%' : '–' }}</div></div>
        <div class="{{ $card }}"><div class="text-sm text-zinc-500">{{ __('Point diff') }}</div><div class="text-2xl font-semibold tabular-nums">{{ $record['point_diff'] > 0 ? '+' : '' }}{{ $record['point_diff'] }}</div></div>
        <div class="{{ $card }}"><div class="text-sm text-zinc-500">{{ __('Sessions') }}</div><div class="text-2xl font-semibold tabular-nums">{{ $record['sessions_attended'] }}</div></div>
        <div class="{{ $card }}"><div class="text-sm text-zinc-500">{{ __('Last played') }}</div><div class="text-lg font-semibold">{{ $record['last_played']?->format('j M Y') ?? '–' }}</div></div>
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Partners') }}</flux:heading>
            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700" data-test="partners-table">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-900">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Partner') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Games') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Wins') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Win %') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($profile['partners'] as $row)
                            <tr wire:key="partner-{{ $row['player_id'] }}">
                                <td class="px-3 py-2 font-medium"><a href="{{ route('clubs.players.show', [$club, $row['player_id']]) }}" wire:navigate class="hover:underline">{{ $row['name'] }}</a></td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ $row['games'] }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ $row['wins'] }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ $row['win_pct'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-zinc-500">{{ __('No partners yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="space-y-3">
            <flux:heading size="lg">{{ __('Opponents') }}</flux:heading>
            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700" data-test="opponents-table">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 dark:bg-zinc-900">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Opponent') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Games') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('W') }}</th>
                            <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('L') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($profile['opponents'] as $row)
                            <tr wire:key="opponent-{{ $row['player_id'] }}">
                                <td class="px-3 py-2 font-medium"><a href="{{ route('clubs.players.show', [$club, $row['player_id']]) }}" wire:navigate class="hover:underline">{{ $row['name'] }}</a></td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ $row['games'] }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ $row['wins'] }}</td>
                                <td class="px-3 py-2 text-end tabular-nums">{{ $row['losses'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-zinc-500">{{ __('No opponents yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg">{{ __('Match history') }}</flux:heading>
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700" data-test="history-table">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Date') }}</th>
                        <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Session') }}</th>
                        <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Partner') }}</th>
                        <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Opponents') }}</th>
                        <th scope="col" class="px-3 py-2 text-end font-medium">{{ __('Score') }}</th>
                        <th scope="col" class="px-3 py-2 text-center font-medium">{{ __('Result') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($history as $h)
                        <tr wire:key="history-{{ $h['match_id'] }}" data-test="history-row">
                            <td class="whitespace-nowrap px-3 py-2">{{ $h['date']->format('j M Y') }}</td>
                            <td class="px-3 py-2"><a href="{{ route('clubs.sessions.results', [$club, $h['session_id']]) }}" wire:navigate class="hover:underline">{{ $h['session_name'] }}</a></td>
                            <td class="px-3 py-2">{{ $h['partner'] ?? '–' }}</td>
                            <td class="px-3 py-2">{{ implode(' & ', $h['opponents']) }}</td>
                            <td class="whitespace-nowrap px-3 py-2 text-end font-semibold tabular-nums">{{ $h['score_for'] }} - {{ $h['score_against'] }}</td>
                            <td class="px-3 py-2 text-center">
                                <flux:badge size="sm" :color="$h['won'] ? 'green' : 'red'">{{ $h['won'] ? __('W') : __('L') }}</flux:badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-3 py-6 text-center text-zinc-500" data-test="history-empty">{{ __('No finished matches in this period.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $history->links() }}
    </div>
</section>
