<?php

namespace App\Livewire\Sessions;

use App\Livewire\Sessions\Concerns\InteractsWithBoard;
use App\Livewire\Sessions\Concerns\ManagesMatchPlayers;
use App\Services\MatchService;
use App\Services\PlaySessionService;
use App\Services\SessionBoard;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
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

    /**
     * Chosen court for Start, keyed by staged match id (0 is the single picker of the
     * other modes). Empty means the lowest free court.
     *
     * @var array<array-key, mixed>
     */
    public array $courtChoice = [];

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

        $court = $this->chosenCourt($matchId);
        $matches->startMatch($this->session, $this->matchOrFail($matchId), $court);

        $this->courtChoice = [];
        $this->changed();
    }

    /** The court picked for this match only; the shape of the whole property is validated first. */
    private function chosenCourt(int $matchId): ?int
    {
        foreach ($this->courtChoice as $key => $value) {
            if (! is_int($key) || ! (is_string($value) || is_int($value)) || ! ctype_digit((string) $value) && (string) $value !== '') {
                throw ValidationException::withMessages(['court' => __('Pick a valid court.')]);
            }
        }

        $key = app(SessionBoard::class)->mode($this->session) === 'skill_courts' ? $matchId : 0;
        $value = (string) ($this->courtChoice[$key] ?? '');

        return ctype_digit($value) ? (int) $value : null;
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
            'mixed' => $board->mode($this->session) === 'mixed',
            'groups' => $board->groups($this->session),
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
