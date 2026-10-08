<?php

namespace App\Livewire\Sessions;

use App\Livewire\Sessions\Concerns\InteractsWithBoard;
use App\Services\MatchService;
use App\Services\SessionBoard;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Match history (done matches, newest first) with Undo last, Edit score and Void.
 */
class Results extends Component
{
    use InteractsWithBoard;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $voidingId = null;

    #[Locked]
    public int $limit = 20;

    public string $scoreA = '';

    public string $scoreB = '';

    public function showMore(): void
    {
        $this->limit += 20;
    }

    public function undoLast(MatchService $matches): void
    {
        $this->authorizeManage();

        $matches->undoLast($this->session);

        $this->changed();
    }

    public function edit(int $matchId): void
    {
        $this->authorizeManage();
        $this->resetErrorBag();

        $match = $this->matchOrFail($matchId);
        $this->voidingId = null;
        $this->editingId = $match->id;
        $this->scoreA = (string) $match->team_a_score;
        $this->scoreB = (string) $match->team_b_score;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->resetErrorBag();
    }

    public function saveScore(MatchService $matches): void
    {
        $this->authorizeManage();

        $this->validate([
            'scoreA' => ['required', 'integer', 'min:0'],
            'scoreB' => ['required', 'integer', 'min:0'],
        ], [], ['scoreA' => __('Team A score'), 'scoreB' => __('Team B score')]);

        $matches->editScore($this->session, $this->matchOrFail((int) $this->editingId), (int) $this->scoreA, (int) $this->scoreB);

        $this->editingId = null;
        $this->changed();
    }

    public function confirmVoid(int $matchId): void
    {
        $this->authorizeManage();
        $this->resetErrorBag();

        $this->editingId = null;
        $this->voidingId = $this->matchOrFail($matchId)->id;
    }

    public function cancelVoid(): void
    {
        $this->voidingId = null;
    }

    public function voidMatch(MatchService $matches): void
    {
        $this->authorizeManage();

        $matches->void($this->session, $this->matchOrFail((int) $this->voidingId));

        $this->voidingId = null;
        $this->changed();
    }

    public function render(): View
    {
        $board = app(SessionBoard::class);

        return view('livewire.sessions.results', [
            'results' => $board->recent($this->session, $this->limit),
            'total' => $board->doneCount($this->session),
            'lastId' => $board->lastDoneId($this->session),
        ]);
    }
}
