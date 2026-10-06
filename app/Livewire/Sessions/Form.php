<?php

namespace App\Livewire\Sessions;

use App\Models\Club;
use App\Models\PlaySession;
use App\Services\PlaySessionService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Session')]
class Form extends Component
{
    #[Locked]
    public Club $club;

    /** Null while creating. */
    #[Locked]
    public ?PlaySession $session = null;

    public string $name = '';

    public string $date = '';

    public string $courts = '';

    public string $up_next_count = '1';

    public bool $auto_fill = false;

    public string $to = '11';

    public string $win_by = '2';

    public function mount(Club $club, ?PlaySession $session = null): void
    {
        $this->club = $club;

        if ($session === null) {
            $this->authorize('create', [PlaySession::class, $club]);

            $this->name = 'Open Play';
            $this->date = now()->toDateString();
            $this->courts = (string) $club->default_courts;
            $scoring = PlaySessionService::DEFAULT_SCORING;
        } else {
            abort_unless($session->club_id === $club->id, 404);
            $this->authorize('update', $session);

            $this->session = $session;
            $this->name = $session->name;
            $this->date = $session->date->format('Y-m-d');
            $this->courts = (string) $session->courts;
            $this->up_next_count = (string) $session->up_next_count;
            $this->auto_fill = $session->auto_fill;
            $scoring = $session->scoring;
        }

        $this->to = (string) $scoring['to'];
        $this->win_by = (string) $scoring['win_by'];
    }

    public function save(PlaySessionService $sessions): void
    {
        // Type-level input check only; ranges and rules live in PlaySessionService.
        $this->validate([
            'courts' => ['required', 'integer'],
            'up_next_count' => ['required', 'integer'],
            'to' => ['required', 'integer'],
            'win_by' => ['required', 'integer'],
        ]);

        $data = [
            'name' => $this->name,
            'date' => $this->date,
            'courts' => (int) $this->courts,
            'up_next_count' => (int) $this->up_next_count,
            'auto_fill' => $this->auto_fill,
            'scoring' => array_replace(PlaySessionService::DEFAULT_SCORING, [
                'to' => (int) $this->to,
                'win_by' => (int) $this->win_by,
            ]),
        ];

        if ($this->session === null) {
            $this->authorize('create', [PlaySession::class, $this->club]);
            $session = $sessions->create($this->club, $data);
        } else {
            $this->authorize('update', $this->session);
            $session = $sessions->update($this->session, $data);
        }

        $this->redirectRoute('clubs.sessions.show', [$this->club, $session], navigate: true);
    }

    public function render(): View
    {
        return view('livewire.sessions.form');
    }
}
