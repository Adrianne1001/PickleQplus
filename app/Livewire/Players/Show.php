<?php

namespace App\Livewire\Players;

use App\Enums\StatsPeriod;
use App\Models\Club;
use App\Models\Player;
use App\Services\StatsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Staff player profile: record, partners, opponents and match history (P5.3b).
 */
#[Title('Player')]
class Show extends Component
{
    use WithPagination;

    #[Locked]
    public Club $club;

    #[Locked]
    public Player $player;

    #[Url(except: 'all_time')]
    public string $period = 'all_time';

    public function mount(Club $club, Player $player): void
    {
        abort_unless($player->club_id === $club->id, 404);
        $this->authorize('view', $player);

        $this->club = $club;
        $this->player = $player;
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    public function render(StatsService $stats): View
    {
        $period = StatsPeriod::fromInput($this->period);

        return view('livewire.players.show', [
            'profile' => $stats->profile($this->club, $this->player, $period),
            'history' => $stats->matchHistory($this->club, $this->player, $period),
            'periods' => StatsPeriod::cases(),
        ]);
    }
}
