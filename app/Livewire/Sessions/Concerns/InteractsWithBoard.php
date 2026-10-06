<?php

namespace App\Livewire\Sessions\Concerns;

use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * Shared plumbing for the board panels: authorisation, scoped lookups that
 * report stale or foreign ids as inline errors, and cross-panel refresh.
 */
trait InteractsWithBoard
{
    #[Locked]
    public PlaySession $session;

    public function mount(PlaySession $session): void
    {
        $this->authorize('manage', $session);

        $this->session = $session;
        $this->bootBoard();
    }

    /** Hook for components that need to set up state after mount. */
    protected function bootBoard(): void {}

    /** Re-render after another panel changed the board or the session started or ended. */
    #[On('board-changed')]
    #[On('session-changed')]
    public function refreshBoard(): void
    {
        $this->session->refresh();
    }

    protected function authorizeManage(): void
    {
        $this->authorize('manage', $this->session);
    }

    /**
     * @throws ValidationException
     */
    protected function matchOrFail(int|string $id): GameMatch
    {
        $match = GameMatch::query()
            ->where('play_session_id', $this->session->id)
            ->find((int) $id);

        if ($match === null) {
            throw ValidationException::withMessages(['match' => __('That match is no longer available. The board has been refreshed.')]);
        }

        return $match;
    }

    /**
     * @throws ValidationException
     */
    protected function playerOrFail(int|string $id): Player
    {
        $player = $this->session->club()->firstOrFail()->players()->find((int) $id);

        if ($player === null) {
            throw ValidationException::withMessages(['player' => __('That player is no longer available.')]);
        }

        return $player;
    }

    /** Call after a successful action so every panel refreshes. */
    protected function changed(): void
    {
        $this->session->refresh();
        $this->dispatch('board-changed');
    }
}
