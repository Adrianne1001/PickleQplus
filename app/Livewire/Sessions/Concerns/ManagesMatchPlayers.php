<?php

namespace App\Livewire\Sessions\Concerns;

use App\Enums\MatchStatus;
use App\Models\GameMatch;
use App\Services\MatchService;
use App\Services\SessionBoard;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * Inline swap, void and score panels for a match card. Only one panel is open
 * at a time; every action re-resolves the match and players inside the session.
 *
 * The swap lists are rebuilt from the server on every render (poll, broadcast,
 * `board-changed`). The staff member's picks live in server state, so they
 * survive those renders; syncPanel() clears a pick that is no longer valid.
 *
 * @property-read list<array<string, mixed>> $candidates
 */
trait ManagesMatchPlayers
{
    /** swap | void | score */
    public ?string $panel = null;

    #[Locked]
    public ?int $panelMatchId = null;

    /** The player being swapped out. */
    public string $outPlayerId = '';

    /** The player swapped in (waiting, or in another Up Next match). */
    public string $inPlayerId = '';

    /** The panel match's status when the panel opened; the service refuses the swap if it differs. */
    #[Locked]
    public ?string $panelMatchStatus = null;

    /**
     * The staged match the picked swap-in player was in when picked (null: picked from Waiting).
     * Set on the server when the pick is made, so the client cannot change it.
     */
    #[Locked]
    public ?int $inMatchId = null;

    /** Shown in the swap panel when a pick was cleared because it is no longer available. */
    public string $pickNotice = '';

    public function openPanel(string $kind, int $matchId): void
    {
        $this->authorizeManage();
        $this->resetErrorBag();

        $this->panel = in_array($kind, ['swap', 'void', 'score'], true) ? $kind : null;
        $this->panelMatchId = $matchId;
        $this->panelMatchStatus = GameMatch::query()
            ->where('play_session_id', $this->session->id)
            ->whereKey($matchId)
            ->value('status')
            ?->value;
        $this->outPlayerId = '';
        $this->inPlayerId = '';
        $this->inMatchId = null;
        $this->pickNotice = '';
        $this->resetPanelFields();
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->panelMatchId = null;
        $this->panelMatchStatus = null;
        $this->inMatchId = null;
        $this->pickNotice = '';
        $this->resetPanelFields();
        $this->resetErrorBag();
    }

    protected function resetPanelFields(): void {}

    public function updatedOutPlayerId(): void
    {
        $this->pickNotice = '';
    }

    /** Remember where the picked player was (Waiting or an Up Next match) when the pick was made. */
    public function updatedInPlayerId(): void
    {
        $this->pickNotice = '';
        $this->inMatchId = null;

        if ($this->inPlayerId === '' || $this->panel !== 'swap') {
            return;
        }

        unset($this->candidates);
        $picked = $this->pickedCandidate();
        if ($picked === null) {
            $this->inPlayerId = '';
            $this->pickNotice = __('The player you picked is no longer available to swap in. Pick again.');

            return;
        }
        $this->inMatchId = $picked['match_id'] ?? null;
    }

    /**
     * The match statuses this component's panel works on (an Up Next card is staged, a court card is playing).
     *
     * @return list<MatchStatus>
     */
    protected function panelStatuses(): array
    {
        return [MatchStatus::Staged, MatchStatus::Playing];
    }

    public function swap(MatchService $matches): void
    {
        $this->authorizeManage();

        $match = $this->matchOrFail((int) $this->panelMatchId);
        $out = $this->playerOrFail($this->requiredId($this->outPlayerId, 'Choose the player to swap out.'));
        $in = $this->playerOrFail($this->requiredId($this->inPlayerId, 'Choose a player to swap in.'));

        try {
            $matches->swap($this->session, $match, $out, $in, true, $this->inMatchId, $this->panelMatchStatus === null ? null : MatchStatus::from($this->panelMatchStatus));
        } catch (ValidationException $e) {
            if (isset($e->errors()['match'])) {
                $this->closePanel(); // the match changed under the panel: reopen it from the board
            } else {
                $this->inPlayerId = ''; // the pick is stale: choose again
                $this->inMatchId = null;
            }
            $this->dispatch('board-changed');

            throw $e;
        }

        $this->closePanel();
        $this->changed();
    }

    public function voidMatch(MatchService $matches): void
    {
        $this->authorizeManage();

        $matches->void($this->session, $this->matchOrFail((int) $this->panelMatchId));

        $this->closePanel();
        $this->changed();
    }

    /**
     * Players who could be swapped in: free waiting players first, then players in other Up Next
     * matches (each with its Up Next number). Rebuilt on every render while the swap panel is open.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function candidates(): array
    {
        if ($this->panel !== 'swap') {
            return [];
        }

        return app(SessionBoard::class)->swapCandidates($this->session, (int) $this->panelMatchId);
    }

    /**
     * Call at the start of render(). Closes the panel when its match is gone or was finished by
     * someone else, and clears a swap pick that is no longer valid, with a short inline message.
     */
    protected function syncPanel(): void
    {
        if ($this->panel === null || $this->panelMatchId === null) {
            return;
        }

        $match = GameMatch::query()
            ->where('play_session_id', $this->session->id)
            ->whereIn('status', array_map(static fn (MatchStatus $s): string => $s->value, $this->panelStatuses()))
            ->with('matchPlayers')
            ->find($this->panelMatchId);

        if ($match === null) {
            $this->closePanel();
            $this->addError('match', __('That match is no longer open. The board has been refreshed.'));

            return;
        }

        if ($this->panel !== 'swap') {
            return;
        }

        unset($this->candidates); // rebuild from the server, never reuse an earlier result

        $notices = [];
        if ($this->outPlayerId !== '' && ! in_array((int) $this->outPlayerId, $match->matchPlayers->pluck('player_id')->all(), true)) {
            $this->outPlayerId = '';
            $notices[] = __('The player to swap out is no longer in this match. Pick again.');
        }
        if ($this->inPlayerId !== '') {
            $picked = $this->pickedCandidate();
            if ($picked === null) {
                $this->inPlayerId = '';
                $this->inMatchId = null;
                $notices[] = __('The player you picked is no longer available to swap in. Pick again.');
            } elseif (($picked['match_id'] ?? null) !== $this->inMatchId) {
                $this->inPlayerId = '';
                $this->inMatchId = null;
                $notices[] = __('The player you picked has moved to another match or the waiting list. Pick again.');
            }
        }
        if ($notices !== []) {
            $this->pickNotice = implode(' ', $notices);
        }
    }

    /**
     * The current candidate row for the picked swap-in player, or null when they are no longer one.
     *
     * @return array<string, mixed>|null
     */
    private function pickedCandidate(): ?array
    {
        foreach ($this->candidates as $candidate) {
            if ((string) $candidate['id'] === $this->inPlayerId) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    private function requiredId(string $value, string $message): int
    {
        if (! ctype_digit($value)) {
            throw ValidationException::withMessages(['player' => __($message)]);
        }

        return (int) $value;
    }
}
