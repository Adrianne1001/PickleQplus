<?php

namespace App\Livewire\Sessions;

use App\Enums\MatchStatus;
use App\Enums\Team;
use App\Livewire\Sessions\Concerns\HintsScore;
use App\Livewire\Sessions\Concerns\InteractsWithBoard;
use App\Livewire\Sessions\Concerns\ManagesMatchPlayers;
use App\Services\MatchService;
use App\Services\SessionBoard;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Courts 1..N with their playing match: Team A / Team B wins, swap and void. A free court
 * can start one of the Up Next matches that may play there.
 */
class Courts extends Component
{
    use HintsScore;
    use InteractsWithBoard;
    use ManagesMatchPlayers;

    public string $scoreA = '';

    public string $scoreB = '';

    /** The team whose "wins" button opened the score form (A or B). Only declareWinner sets it. */
    #[Locked]
    public string $winner = '';

    /** @return list<MatchStatus> */
    protected function panelStatuses(): array
    {
        return [MatchStatus::Playing];
    }

    protected function resetPanelFields(): void
    {
        $this->scoreA = '';
        $this->scoreB = '';
        $this->winner = '';
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

    /**
     * A tap on "Team A wins" / "Team B wins". When the session does not require a score the
     * result is recorded at once (winner only); otherwise the score form opens with the winner's
     * score prefilled with the points a game goes to.
     */
    public function declareWinner(MatchService $matches, string $team, int $matchId): void
    {
        $this->authorizeManage();
        $this->resetErrorBag();

        $winner = Team::tryFrom($team);
        if ($winner === null) {
            throw ValidationException::withMessages(['score' => __('Choose which team won.')]);
        }

        $match = $this->matchOrFail($matchId);

        if (! $this->session->require_score) {
            $matches->finish($this->session, $match, $winner);

            $this->closePanel();
            $this->changed();

            return;
        }

        $this->openPanel('score', $match->id);
        $this->winner = $winner->value;
        $to = (string) ($this->session->scoring['to'] ?? 11);
        $this->scoreA = $winner === Team::A ? $to : '';
        $this->scoreB = $winner === Team::B ? $to : '';
    }

    /** Submit of the score form: the tapped winner plus both scores. */
    public function finish(MatchService $matches): void
    {
        $this->authorizeManage();

        $winner = Team::tryFrom($this->winner);
        if ($winner === null) {
            throw ValidationException::withMessages(['score' => __('Choose which team won.')]);
        }

        $this->validate([
            'scoreA' => ['required', 'integer', 'min:0'],
            'scoreB' => ['required', 'integer', 'min:0'],
        ], [], ['scoreA' => __('Team A score'), 'scoreB' => __('Team B score')]);

        $matches->finish($this->session, $this->matchOrFail((int) $this->panelMatchId), $winner, (int) $this->scoreA, (int) $this->scoreB);

        $this->closePanel();
        $this->changed();
    }

    /** Live feedback before submitting. */
    #[Computed]
    public function scoreHint(): ?string
    {
        return $this->resultHint($this->scoreA, $this->scoreB, $this->winner);
    }

    public function render(): View
    {
        $this->syncPanel();

        return view('livewire.sessions.courts', [
            'courts' => app(SessionBoard::class)->courts($this->session, $this->session->isLive()),
            'live' => $this->session->isLive(),
            'mixed' => app(SessionBoard::class)->mode($this->session) === 'mixed',
            'groups' => app(SessionBoard::class)->groups($this->session),
        ]);
    }
}
