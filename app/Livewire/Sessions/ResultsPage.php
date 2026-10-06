<?php

namespace App\Livewire\Sessions;

use App\Domain\Stats\RankedRow;
use App\Models\Club;
use App\Models\PlaySession;
use App\Services\StatsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Staff results page of one session: standings plus the match log (P5.1b, P5.4b).
 */
#[Title('Session results')]
class ResultsPage extends Component
{
    #[Locked]
    public Club $club;

    #[Locked]
    public PlaySession $session;

    public function mount(Club $club, PlaySession $session): void
    {
        abort_unless($session->club_id === $club->id, 404);
        $this->authorize('view', $session);

        $this->club = $club;
        $this->session = $session;
    }

    public function render(StatsService $stats): View
    {
        $standings = $stats->sessionStandings($this->session);

        $rows = array_map(fn (RankedRow $r): array => [
            'rank' => $r->rank,
            'name' => $r->row->name,
            'nickname' => $r->row->nickname,
            'url' => route('clubs.players.show', [$this->club, (int) $r->row->id]),
            'played' => $r->row->played,
            'wins' => $r->row->wins,
            'losses' => $r->row->losses(),
            'win_pct' => $r->row->winPercent(),
            'point_diff' => $r->row->pointDiff(),
        ], $standings);

        return view('livewire.sessions.results-page', [
            'standings' => $rows,
            'log' => $stats->matchLog($this->session, includeVoid: true),
        ]);
    }
}
