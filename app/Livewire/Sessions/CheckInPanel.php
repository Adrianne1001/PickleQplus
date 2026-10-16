<?php

namespace App\Livewire\Sessions;

use App\Domain\Stars\StarLevels;
use App\Enums\SessionPlayerStatus;
use App\Livewire\Sessions\Concerns\QuickAddsPlayer;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionListPlayer;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\SessionBoard;
use App\Services\SessionListService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Staff check-in (Phase 16): build the session's player list (search, quick-add, import),
 * check listed players in from "Not Yet Here" (one by one or all), and manage the
 * checked-in list (check out, break, return).
 */
class CheckInPanel extends Component
{
    use QuickAddsPlayer;

    #[Locked]
    public PlaySession $session;

    public string $search = '';

    /**
     * What the last import did, shown until dismissed.
     *
     * @var array{listed: int, created: int, already_listed: int, errors: int, notes: list<string>}|null
     */
    #[Locked]
    public ?array $importResult = null;

    /**
     * What the last "Check in all" did, shown until dismissed.
     *
     * @var array{checked_in: int, skipped: list<array{name: string, reason: string}>}|null
     */
    #[Locked]
    public ?array $checkInAllResult = null;

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

    /** Check in a player who is on the session's list (Not Yet Here). */
    public function checkIn(SessionListService $list, int $playerId): void
    {
        $this->authorize('manage', $this->session);

        $list->checkIn($this->session, $playerId);
    }

    /**
     * Check in every Not Yet Here player. Players the check-in rules refuse are skipped and named.
     */
    public function checkInAll(SessionListService $list): void
    {
        $this->authorize('manage', $this->session);

        $this->checkInAllResult = $list->checkInAll($this->session);
    }

    /** Take a player off the list. Only players who aren't checked in can be removed. */
    public function removeFromList(SessionListService $list, int $playerId): void
    {
        $this->authorize('manage', $this->session);

        $list->remove($this->session, $playerId);
    }

    /**
     * A tap on a star saves the player's skill at once (no Save step). The service allows any
     * manual-rated listed player, pending or not, and clears the "Set skill" flag.
     */
    public function setSkill(SessionListService $list, int $playerId, int $stars): void
    {
        $this->authorize('manage', $this->session);

        $this->resetErrorBag(['stars', 'player']);
        $list->setSkill($this->session, $playerId, $stars);
    }

    /** Hide the import and check-in-all results. */
    public function dismissResults(): void
    {
        $this->importResult = null;
        $this->checkInAllResult = null;
    }

    /**
     * The import modal added players to the list.
     *
     * The event only carries a token; the result itself is read (once) from the server's cache, where
     * the import component put it, so nothing the browser sends is shown as a result.
     */
    #[On('players-imported')]
    public function importFinished(string $token): void
    {
        $this->authorize('manage', $this->session);

        $result = Cache::pull(ImportPlayers::resultKey($this->session, $token));
        if (! is_array($result)) {
            return;
        }

        $notes = [];
        $given = $result['notes'] ?? [];
        foreach (is_array($given) ? $given : [] as $note) {
            if (is_string($note)) {
                $notes[] = mb_substr($note, 0, 300);
            }
        }

        $count = fn (string $key): int => is_numeric($result[$key] ?? null) ? max(0, (int) $result[$key]) : 0;

        $this->importResult = [
            'listed' => $count('listed'),
            'created' => $count('created'),
            'already_listed' => $count('already_listed'),
            'errors' => $count('errors'),
            'notes' => array_slice($notes, 0, 20),
        ];
        $this->checkInAllResult = null;
    }

    /** Search result tap: put the player on the session's list. Nobody is checked in by this. */
    public function addToList(SessionListService $list, int $playerId): void
    {
        $this->authorize('manage', $this->session);

        $user = auth()->user();
        $list->add($this->session, [$this->player($playerId)->id], $user instanceof User ? $user : null);
        $this->search = '';
    }

    /** Create the player from the inline form and put them on the list, in one transaction. */
    public function saveQuick(SessionListService $list): void
    {
        $this->authorize('manage', $this->session);
        $this->authorize('create', [Player::class, $this->quickClub()]);

        $user = auth()->user();

        try {
            // Stars are optional: blank lists the player at 3 stars with "Set skill" pending.
            $list->addNewPlayer($this->session, $this->quickInput(), $user instanceof User ? $user : null);
        } catch (ValidationException $e) {
            throw $this->prefixed($e);
        }

        $this->search = '';
        $this->closeQuickAdd();
    }

    protected function quickClub(): Club
    {
        return $this->session->club()->firstOrFail();
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

        $checkIns->removeCheckIn($this->session, $this->player($playerId), $user instanceof User ? $user : null);
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
     * Active club players matching the search who aren't already on the session's list.
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

        return app(SessionListService::class)->search($this->session, $term);
    }

    /**
     * Listed players who haven't been checked in.
     *
     * @return Collection<int, SessionListPlayer>
     */
    #[Computed]
    public function notYetHere(): Collection
    {
        return app(SessionListService::class)->notYetHere($this->session);
    }

    /**
     * The club's star levels (label, hint, DUPR range from its bands) for the skill stars.
     *
     * @return list<array{stars: int, label: string, hint: string, min: float|null, max: float|null, max_inclusive: bool, decimals: int}>
     */
    #[Computed]
    public function levels(): array
    {
        return StarLevels::forClub($this->session->club ?? abort(404));
    }

    /**
     * @return array{checked_in: int, listed: int, not_yet_here: int}
     */
    #[Computed]
    public function counts(): array
    {
        return app(SessionListService::class)->counts($this->session);
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
            'quickAdd' => $this->session->isEnded() ? ['show' => false, 'deactivated' => null] : $this->quickAddState(),
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
