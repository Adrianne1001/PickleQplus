<?php

namespace App\Livewire\Sessions;

use App\Livewire\Sessions\Concerns\InteractsWithBoard;
use App\Livewire\Sessions\Concerns\ManagesMatchPlayers;
use App\Services\MatchService;
use App\Services\PlaySessionService;
use App\Services\SessionBoard;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Staged matches: start on a court, re-roll, swap, remove and void, plus the
 * auto-fill toggle and the Up Next count.
 */
class UpNext extends Component
{
    use InteractsWithBoard;
    use ManagesMatchPlayers;

    public bool $autoFill = false;

    public string $upNextCount = '1';

    /** Chosen court for Start; empty means the lowest free court. */
    public string $courtChoice = '';

    protected function bootBoard(): void
    {
        $this->syncSettings();
    }

    #[On('board-changed')]
    #[On('session-changed')]
    public function syncFromSession(): void
    {
        $this->session->refresh();
        $this->syncSettings();
    }

    /**
     * Re-sync from the database on every request (poll, action, toggle), before
     * Livewire applies the user's own change. So a stale tab never writes back
     * another device's settings.
     */
    public function hydrate(): void
    {
        $this->syncSettings();
    }

    public function updatedAutoFill(PlaySessionService $sessions): void
    {
        $this->saveSetting($sessions, ['auto_fill' => $this->autoFill]);
    }

    public function updatedUpNextCount(PlaySessionService $sessions): void
    {
        $this->saveSetting($sessions, [
            'up_next_count' => ctype_digit($this->upNextCount) ? (int) $this->upNextCount : $this->upNextCount,
        ]);
    }

    public function start(MatchService $matches, int $matchId): void
    {
        $this->authorizeManage();

        $court = ctype_digit($this->courtChoice) ? (int) $this->courtChoice : null;
        $matches->startMatch($this->session, $this->matchOrFail($matchId), $court);

        $this->courtChoice = '';
        $this->changed();
    }

    public function reroll(MatchService $matches, int $matchId): void
    {
        $this->authorizeManage();

        $matches->reroll($this->session, $this->matchOrFail($matchId));

        $this->changed();
    }

    /**
     * Waiting players who could be swapped in.
     *
     * @return list<array{id: int, name: string, stars: int|null, games_played: int, waited_minutes: int, estimate_minutes: int|null}>
     */
    #[Computed]
    public function candidates(): array
    {
        if ($this->panel !== 'swap') {
            return [];
        }

        return app(SessionBoard::class)->waiting($this->session);
    }

    public function render(): View
    {
        $board = app(SessionBoard::class);

        return view('livewire.sessions.up-next', [
            'staged' => $board->staged($this->session),
            'freeCourts' => $board->freeCourts($this->session),
        ]);
    }

    /**
     * Saves one setting only, so a value changed on another device is kept.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveSetting(PlaySessionService $sessions, array $data): void
    {
        $this->authorizeManage();

        try {
            $sessions->update($this->session, $data);
        } finally {
            $this->session->refresh();
            $this->syncSettings();
        }

        $this->dispatch('board-changed');
    }

    private function syncSettings(): void
    {
        $this->autoFill = $this->session->auto_fill;
        $this->upNextCount = (string) $this->session->up_next_count;
    }
}
