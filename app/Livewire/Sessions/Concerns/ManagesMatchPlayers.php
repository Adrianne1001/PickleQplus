<?php

namespace App\Livewire\Sessions\Concerns;

use App\Enums\SessionPlayerStatus;
use App\Services\MatchService;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Inline swap, remove and void panels for a match card. Only one panel is open
 * at a time; every action re-resolves the match and players inside the session.
 */
trait ManagesMatchPlayers
{
    /** swap | remove | void | score */
    public ?string $panel = null;

    #[Locked]
    public ?int $panelMatchId = null;

    /** The player being swapped out or removed. */
    public string $outPlayerId = '';

    /** The waiting player swapped in. */
    public string $inPlayerId = '';

    public string $removeStatus = 'waiting';

    public function openPanel(string $kind, int $matchId): void
    {
        $this->authorizeManage();
        $this->resetErrorBag();

        $this->panel = in_array($kind, ['swap', 'remove', 'void', 'score'], true) ? $kind : null;
        $this->panelMatchId = $matchId;
        $this->outPlayerId = '';
        $this->inPlayerId = '';
        $this->removeStatus = 'waiting';
        $this->resetPanelFields();
    }

    public function closePanel(): void
    {
        $this->panel = null;
        $this->panelMatchId = null;
        $this->resetErrorBag();
    }

    protected function resetPanelFields(): void {}

    public function swap(MatchService $matches): void
    {
        $this->authorizeManage();

        $matches->swap(
            $this->session,
            $this->matchOrFail((int) $this->panelMatchId),
            $this->playerOrFail($this->requiredId($this->outPlayerId, 'Choose the player to swap out.')),
            $this->playerOrFail($this->requiredId($this->inPlayerId, 'Choose a waiting player to swap in.')),
        );

        $this->closePanel();
        $this->changed();
    }

    public function remove(MatchService $matches): void
    {
        $this->authorizeManage();

        $status = SessionPlayerStatus::tryFrom($this->removeStatus);
        if ($status === null || $status === SessionPlayerStatus::Playing) {
            throw ValidationException::withMessages(['player' => __('Choose waiting, break or left.')]);
        }

        $matches->remove(
            $this->session,
            $this->matchOrFail((int) $this->panelMatchId),
            $this->playerOrFail($this->requiredId($this->outPlayerId, 'Choose the player to remove.')),
            $status,
        );

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
