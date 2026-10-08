@php
    $record = $profile['record'];
    $th = 'px-4 py-3 font-semibold';
@endphp

<section class="w-full max-w-5xl space-y-6 lg:space-y-8">
    <x-page-header
        :eyebrow="$club->name"
        :title="$player->name"
        :back="route('clubs.players.index', $club)"
        :back-label="__('Players')"
        data-test="player-name"
    >
        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-zinc-600 dark:text-zinc-400">
            @if ($player->gender)
                <flux:badge size="sm" color="zinc" data-test="player-gender">{{ $player->gender->label() }}</flux:badge>
            @endif
            <flux:badge size="sm" :color="$player->active ? 'green' : 'zinc'">{{ $player->active ? __('Active') : __('Inactive') }}</flux:badge>
            <x-star-rating :stars="$player->stars" />
            <span>{{ __('DUPR ID') }}: <span class="font-mono">{{ $player->dupr_id ?? '–' }}</span></span>
            <span>{{ __('DUPR rating') }}: {{ $player->dupr_rating !== null ? number_format((float) $player->dupr_rating, 2) : '–' }}</span>
        </div>

        <x-slot:actions>
            <flux:select wire:model.live="period" :label="__('Period')" class="w-48" data-test="period-select">
                @foreach ($periods as $p)
                    <flux:select.option value="{{ $p->value }}">{{ __($p->label()) }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>
    </x-page-header>

    @php
        $tiles = [
            [__('Played'), $record['played'], 'record-played'],
            [__('W - L'), $record['wins'].' - '.$record['losses'], 'record-wl'],
            [__('Win %'), $record['win_pct'] !== null ? $record['win_pct'].'%' : '–', 'record-winpct'],
            [__('Point diff'), ($record['point_diff'] > 0 ? '+' : '').$record['point_diff'], 'record-diff'],
            [__('Sessions'), $record['sessions_attended'], 'record-sessions'],
            [__('Last played'), $record['last_played']?->format('j M Y') ?? '–', 'record-last'],
        ];
    @endphp
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6" data-test="record-cards">
        @foreach ($tiles as [$label, $value, $test])
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-700 dark:bg-zinc-900">
                <div class="text-xs font-semibold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">{{ $label }}</div>
                <div class="mt-1 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white" data-test="{{ $test }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-card :title="__('Partners')" :padding="false">
            <div class="overflow-x-auto" data-test="partners-table">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-xs uppercase tracking-wider text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-400">
                        <tr>
                            <th scope="col" class="{{ $th }} text-start">{{ __('Partner') }}</th>
                            <th scope="col" class="{{ $th }} text-end">{{ __('Games') }}</th>
                            <th scope="col" class="{{ $th }} text-end">{{ __('Wins') }}</th>
                            <th scope="col" class="{{ $th }} text-end">{{ __('Win %') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($profile['partners'] as $row)
                            <tr wire:key="partner-{{ $row['player_id'] }}">
                                <td class="px-4 py-3 font-medium"><a href="{{ route('clubs.players.show', [$club, $row['player_id']]) }}" wire:navigate class="hover:text-brand-700 hover:underline dark:hover:text-brand-400">{{ $row['name'] }}</a></td>
                                <td class="px-4 py-3 text-end tabular-nums">{{ $row['games'] }}</td>
                                <td class="px-4 py-3 text-end tabular-nums">{{ $row['wins'] }}</td>
                                <td class="px-4 py-3 text-end tabular-nums">{{ $row['win_pct'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-zinc-600 dark:text-zinc-400">{{ __('No partners yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card :title="__('Opponents')" :padding="false">
            <div class="overflow-x-auto" data-test="opponents-table">
                <table class="w-full text-sm">
                    <thead class="bg-zinc-50 text-xs uppercase tracking-wider text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-400">
                        <tr>
                            <th scope="col" class="{{ $th }} text-start">{{ __('Opponent') }}</th>
                            <th scope="col" class="{{ $th }} text-end">{{ __('Games') }}</th>
                            <th scope="col" class="{{ $th }} text-end">{{ __('W') }}</th>
                            <th scope="col" class="{{ $th }} text-end">{{ __('L') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($profile['opponents'] as $row)
                            <tr wire:key="opponent-{{ $row['player_id'] }}">
                                <td class="px-4 py-3 font-medium"><a href="{{ route('clubs.players.show', [$club, $row['player_id']]) }}" wire:navigate class="hover:text-brand-700 hover:underline dark:hover:text-brand-400">{{ $row['name'] }}</a></td>
                                <td class="px-4 py-3 text-end tabular-nums">{{ $row['games'] }}</td>
                                <td class="px-4 py-3 text-end tabular-nums">{{ $row['wins'] }}</td>
                                <td class="px-4 py-3 text-end tabular-nums">{{ $row['losses'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-zinc-600 dark:text-zinc-400">{{ __('No opponents yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>

    <x-card :title="__('Match history')" :padding="false">
        <div class="overflow-x-auto" data-test="history-table">
            <table class="w-full text-sm">
                <thead class="bg-zinc-50 text-xs uppercase tracking-wider text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-400">
                    <tr>
                        <th scope="col" class="{{ $th }} text-start">{{ __('Date') }}</th>
                        <th scope="col" class="{{ $th }} text-start">{{ __('Session') }}</th>
                        <th scope="col" class="{{ $th }} text-start">{{ __('Partner') }}</th>
                        <th scope="col" class="{{ $th }} text-start">{{ __('Opponents') }}</th>
                        <th scope="col" class="{{ $th }} text-end">{{ __('Score') }}</th>
                        <th scope="col" class="{{ $th }} text-center">{{ __('Result') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($history as $h)
                        <tr wire:key="history-{{ $h['match_id'] }}" data-test="history-row">
                            <td class="whitespace-nowrap px-4 py-3">{{ $h['date']->format('j M Y') }}</td>
                            <td class="px-4 py-3"><a href="{{ route('clubs.sessions.results', [$club, $h['session_id']]) }}" wire:navigate class="hover:text-brand-700 hover:underline dark:hover:text-brand-400">{{ $h['session_name'] }}</a></td>
                            <td class="px-4 py-3">{{ $h['partner'] ?? '–' }}</td>
                            <td class="px-4 py-3">{{ implode(' & ', $h['opponents']) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-end font-semibold tabular-nums">{{ $h['score_for'] }} - {{ $h['score_against'] }}</td>
                            <td class="px-4 py-3 text-center">
                                <flux:badge size="sm" :color="$h['won'] ? 'green' : 'red'">{{ $h['won'] ? __('W') : __('L') }}</flux:badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-600 dark:text-zinc-400" data-test="history-empty">{{ __('No finished matches in this period.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <x-slot:footer>{{ $history->links() }}</x-slot:footer>
    </x-card>
</section>
