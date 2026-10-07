<?php

namespace App\Livewire\Sessions;

use App\Domain\Scoring\ScoreValidator;
use App\Livewire\Sessions\Concerns\InteractsWithBoard;
use App\Livewire\Sessions\Concerns\ManagesMatchPlayers;
use App\Services\MatchService;
use App\Services\SessionBoard;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Courts 1..N with their playing match: enter score, swap, remove and void.
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
            'courts' => app(SessionBoard::class)->courts($this->session),
            'mixed' => app(SessionBoard::class)->mode($this->session) === 'mixed',
            'groups' => app(SessionBoard::class)->groups($this->session),
        ]);
    }
}
