<?php

namespace App\Livewire\Sessions;

use App\Domain\Scoring\ScoreValidator;
use App\Livewire\Sessions\Concerns\InteractsWithBoard;
use App\Livewire\Sessions\Concerns\ManagesMatchPlayers;
use App\Services\MatchService;
use App\Services\SessionBoard;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Courts 1..N with their playing match: enter score, swap, remove and void. A free court
 * can start one of the Up Next matches that may play there.
 */
class Courts extends Component
{
    use InteractsWithBoard;
    use ManagesMatchPlayers;

    public string $scoreA = '';

    public string $scoreB = '';

    protected function resetPanelFields(): void
    {
        $this->scoreA = '';
        $this->scoreB = '';
    }

    /** Modal name of a court's "Choose a matchup" picker (the view builds the same name). */
    private function modalName(int $court): string
    {
        return 'choose-matchup-'.$court;
    }

    /**
     * Start a staged match on this free court (the court card's buttons and its
     * "Choose a matchup" modal). The service re-checks the court and the match.
     */
    public function startOnCourt(MatchService $matches, int $court, int $matchId): void
    {
        $this->authorizeManage();

        try {
            $matches->startMatch($this->session, $this->matchOrFail($matchId), $court);
        } catch (ValidationException $e) {
            Flux::modal($this->modalName($court))->close();
            $this->dispatch('board-changed'); // the tap was stale: refresh the whole board

            throw $e;
        }

        Flux::modal($this->modalName($court))->close();
        $this->changed();
    }

    public function finish(MatchService $matches): void
    {
        $this->authorizeManage();

        $this->validate([
            'scoreA' => ['required', 'integer', 'min:0'],
            'scoreB' => ['required', 'integer', 'min:0'],
        ], [], ['scoreA' => __('Team A score'), 'scoreB' => __('Team B score')]);

        $matches->finish($this->session, $this->matchOrFail((int) $this->panelMatchId), (int) $this->scoreA, (int) $this->scoreB);

        $this->closePanel();
        $this->changed();
    }

    /** Live feedback before submitting; the service validates again. */
    #[Computed]
    public function scoreHint(): ?string
    {
        if (! ctype_digit($this->scoreA) || ! ctype_digit($this->scoreB)) {
            return null;
        }

        return ScoreValidator::error($this->session->scoring, (int) $this->scoreA, (int) $this->scoreB);
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
        return view('livewire.sessions.courts', [
            'courts' => app(SessionBoard::class)->courts($this->session, $this->session->isLive()),
            'live' => $this->session->isLive(),
            'mixed' => app(SessionBoard::class)->mode($this->session) === 'mixed',
            'groups' => app(SessionBoard::class)->groups($this->session),
        ]);
    }
}
