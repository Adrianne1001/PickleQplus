<?php

namespace App\Livewire\Sessions;

use App\Livewire\Sessions\Concerns\InteractsWithBoard;
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

    public function render(): View
    {
        $board = app(SessionBoard::class);

        return view('livewire.sessions.waiting-list', [
            'waiting' => $board->waiting($this->session),
            'onBreak' => $board->onBreak($this->session),
        ]);
    }
}
