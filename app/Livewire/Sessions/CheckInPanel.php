<?php

namespace App\Livewire\Sessions;

use App\Enums\SessionPlayerStatus;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\SessionBoard;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Staff check-in: search the club's active players, check them in, and manage
 * the checked-in list (check out, break, return).
 */
class CheckInPanel extends Component
{
    #[Locked]
    public PlaySession $session;

    public string $search = '';

    public function mount(PlaySession $session): void
    {
        $this->authorize('manage', $session);

        $this->session = $session;
    }

    /** Re-render when the parent starts or ends the session. */
    #[On('session-changed')]
    public function sessionChanged(): void
    {
        $this->session->refresh();
    }

    public function checkIn(CheckInService $checkIns, int $playerId): void
    {
        $this->authorize('manage', $this->session);

        $checkIns->checkIn($this->session, $this->player($playerId));
        $this->search = '';
    }

    public function checkOut(CheckInService $checkIns, int $playerId): void
    {
        $this->authorize('manage', $this->session);

        $checkIns->checkOut($this->session, $this->player($playerId));
    }

    /** Remove a bogus check-in (P3.7). The service refuses when the player has matches. */
    public function removeCheckIn(CheckInService $checkIns, int $playerId): void
    {
        $this->authorize('manage', $this->session);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $checkIns->removeCheckIn($this->session, $this->player($playerId), $user);
    }

    public function goOnBreak(CheckInService $checkIns, int $playerId): void
    {
        $this->authorize('manage', $this->session);

        $checkIns->goOnBreak($this->session, $this->player($playerId));
    }

    public function returnFromBreak(CheckInService $checkIns, int $playerId): void
    {
        $this->authorize('manage', $this->session);

        $checkIns->returnFromBreak($this->session, $this->player($playerId));
    }

    /**
     * Active club players matching the search who aren't already checked in.
     *
     * @return Collection<int, Player>
     */
    #[Computed]
    public function results(): Collection
    {
        $term = trim($this->search);

        if ($term === '') {
            return new Collection;
        }

        $like = '%'.addcslashes($term, '\\%_').'%';

        return $this->session->club()->firstOrFail()->players()
            ->where('active', true)
            ->where('name', 'like', $like)
            ->whereNotIn('id', $this->session->sessionPlayers()
                ->where('status', '!=', SessionPlayerStatus::Left->value)
                ->select('player_id'))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, SessionPlayer>
     */
    #[Computed]
    public function entries(): Collection
    {
        return $this->session->sessionPlayers()
            ->where('status', '!=', SessionPlayerStatus::Left->value)
            ->with('player')
            ->orderBy('checked_in_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Player ids first self-registered in this session, for the "new" badge.
     *
     * @return list<int>
     */
    #[Computed]
    public function newPlayerIds(): array
    {
        $ids = [];
        foreach ($this->session->sessionPlayers()->selfRegisteredHere()->get(['session_players.player_id']) as $entry) {
            $ids[] = $entry->player_id;
        }

        return $ids;
    }

    public function render(): View
    {
        return view('livewire.sessions.check-in-panel', [
            'wins' => app(SessionBoard::class)->winsByPlayer($this->session),
        ]);
    }

    /**
     * Club-scoped lookup. A stale, deleted, deactivated or foreign id is shown
     * as an inline error rather than a 404 page.
     *
     * @throws ValidationException
     */
    private function player(int $playerId): Player
    {
        $player = $this->session->club()->firstOrFail()->players()->find($playerId);

        if ($player === null) {
            throw ValidationException::withMessages(['player' => __('That player is no longer available. Search again.')]);
        }

        return $player;
    }
}
