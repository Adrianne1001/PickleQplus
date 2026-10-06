<?php

namespace App\Livewire\Sessions;

use App\Models\Club;
use App\Models\PlaySession;
use App\Services\PlaySessionService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The session page. In P2.8 this becomes the organizer board: courts and Up
 * Next panels are added next to the check-in panel as child components.
 */
#[Title('Session')]
class Show extends Component
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

    /**
     * Listen on this session's public channel so several staff devices stay in sync.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return [
            'echo:play-session.'.$this->session->public_id.',.session.updated' => 'syncFromBroadcast',
        ];
    }

    /**
     * Refresh this page and every board panel. Also the 30s poll fallback.
     * It only dispatches browser-side events, never a broadcast, so it can't loop.
     */
    public function syncFromBroadcast(): void
    {
        $this->session->refresh();
        $this->dispatch('session-changed');
    }

    public function start(PlaySessionService $sessions): void
    {
        $this->authorize('manage', $this->session);

        $sessions->start($this->session);
        $this->afterChange(__('Session started.'));
    }

    public function end(PlaySessionService $sessions): void
    {
        $this->authorize('manage', $this->session);

        $sessions->end($this->session);
        $this->afterChange(__('Session ended.'));
    }

    public function delete(PlaySessionService $sessions): void
    {
        $this->authorize('delete', $this->session);

        $sessions->delete($this->session);

        $this->redirectRoute('clubs.sessions.index', $this->club, navigate: true);
    }

    public function render(): View
    {
        return view('livewire.sessions.show');
    }

    private function afterChange(string $message): void
    {
        $this->session->refresh();
        $this->dispatch('session-changed');
        Flux::modals()->close();
        Flux::toast(variant: 'success', text: $message);
    }
}
