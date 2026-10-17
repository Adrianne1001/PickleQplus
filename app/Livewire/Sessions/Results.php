<?php

namespace App\Livewire\Sessions;

use App\Enums\Team;
use App\Livewire\Sessions\Concerns\HintsScore;
use App\Livewire\Sessions\Concerns\InteractsWithBoard;
use App\Services\MatchService;
use App\Services\SessionBoard;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Match history (done matches, newest first) with Undo last, Edit result and Void.
 */
class Results extends Component
{
    use HintsScore;
    use InteractsWithBoard;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $voidingId = null;

    #[Locked]
    public int $limit = 20;

    public string $scoreA = '';

    public string $scoreB = '';

    /** The winner chosen in the Edit result form (A or B). */
    public string $editWinner = '';

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
        $this->scoreA = $match->team_a_score === null ? '' : (string) $match->team_a_score;
        $this->scoreB = $match->team_b_score === null ? '' : (string) $match->team_b_score;
        $this->editWinner = (string) $match->getRawOriginal('winner');
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->resetErrorBag();
    }

    public function saveResult(MatchService $matches): void
    {
        $this->authorizeManage();

        $winner = Team::tryFrom($this->editWinner);
        if ($winner === null) {
            throw ValidationException::withMessages(['score' => __('Choose which team won.')]);
        }

        $this->validate([
            'scoreA' => ['nullable', 'integer', 'min:0'],
            'scoreB' => ['nullable', 'integer', 'min:0'],
        ], [], ['scoreA' => __('Team A score'), 'scoreB' => __('Team B score')]);

        $matches->editResult(
            $this->session,
            $this->matchOrFail((int) $this->editingId),
            $winner,
            $this->scoreA === '' ? null : (int) $this->scoreA,
            $this->scoreB === '' ? null : (int) $this->scoreB,
        );

        $this->editingId = null;
        $this->changed();
    }

    /** Live feedback in the Edit result form. */
    #[Computed]
    public function editHint(): ?string
    {
        return $this->resultHint($this->scoreA, $this->scoreB, $this->editWinner);
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
