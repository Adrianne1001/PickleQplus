<?php

namespace App\Livewire\Sessions;

use App\Livewire\Sessions\Concerns\InteractsWithBoard;
use App\Services\CheckInService;
use App\Services\SessionBoard;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Read-only waiting list in engine priority order with wait estimates, and
 * the players on break.
 */
class WaitingList extends Component
{
    use InteractsWithBoard;

    /** Quick M/W action for a waiting player with no gender. */
    public function setGender(CheckInService $checkIns, int $playerId, string $gender): void
    {
        $this->authorizeManage();

        $checkIns->setGender($this->session, $this->playerOrFail($playerId), $gender);

        $this->changed();
    }

    public function render(): View
    {
        $board = app(SessionBoard::class);

        $wins = $board->winsByPlayer($this->session);
        $waiting = $board->waiting($this->session, $wins);

        return view('livewire.sessions.waiting-list', [
            'waiting' => $waiting,
            'onBreak' => $board->onBreak($this->session, $wins),
            'mixed' => $board->mode($this->session) === 'mixed',
            'social' => $board->mode($this->session) === 'social',
            'unplaceable' => $board->unplaceableIn($waiting),
            'groups' => $board->groups($this->session, $waiting),
        ]);
    }
}
