<?php

namespace App\Livewire\Stats;

use App\Domain\Stats\RankedRow;
use App\Enums\StatsPeriod;
use App\Models\Club;
use App\Services\StatsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Staff club leaderboard with a period dropdown (P5.2b).
 */
#[Title('Stats')]
class Leaderboard extends Component
{
    #[Locked]
    public Club $club;

    #[Url(except: 'all_time')]
    public string $period = 'all_time';

    public function mount(Club $club): void
    {
        $this->authorize('viewStats', $club);

        $this->club = $club;
    }

    public function render(StatsService $stats): View
    {
        $board = $stats->leaderboard($this->club, StatsPeriod::fromInput($this->period));

        $map = fn (array $rows): array => array_map(fn (RankedRow $r): array => [
            'rank' => $r->rank,
            'name' => $r->row->name,
            'nickname' => $r->row->nickname,
            'url' => route('clubs.players.show', [$this->club, (int) $r->row->id]),
            'played' => $r->row->played,
            'wins' => $r->row->wins,
            'losses' => $r->row->losses(),
            'win_pct' => $r->row->winPercent(),
            'point_diff' => $r->row->pointDiff(),
        ], $rows);

        return view('livewire.stats.leaderboard', [
            'ranked' => $map($board['ranked']),
            'unranked' => $map($board['unranked']),
            'minGames' => $board['min_games'],
            'periods' => StatsPeriod::cases(),
        ]);
    }
}
