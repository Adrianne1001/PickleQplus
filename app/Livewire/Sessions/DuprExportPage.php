<?php

namespace App\Livewire\Sessions;

use App\Models\Club;
use App\Models\GameMatch;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\Dupr\DuprEligibility;
use App\Services\Dupr\DuprExportService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Staff page to review what is eligible for DUPR and export it as CSV (P4.4).
 */
#[Title('DUPR export')]
class DuprExportPage extends Component
{
    #[Locked]
    public Club $club;

    #[Locked]
    public PlaySession $session;

    public function mount(Club $club, PlaySession $session): void
    {
        abort_unless($session->club_id === $club->id, 404);
        $this->authorize('view', $session);

        $this->club = $club;
        $this->session = $session;
    }

    public function export(DuprExportService $exports): void
    {
        $this->authorize('manage', $this->session);
        $this->resetErrorBag();

        try {
            $export = $exports->export($this->session, $this->currentUser());
        } catch (ValidationException $e) {
            $this->setErrorBag($e->errors());

            return;
        }

        $url = route('clubs.sessions.dupr.download', [$this->club, $this->session, $export->id]);
        // Navigating to a file download keeps this page open, so the re-render below stays visible.
        $this->js('window.location = '.json_encode($url, JSON_UNESCAPED_SLASHES).';');
    }

    private function currentUser(): User
    {
        $user = auth()->user();
        abort_if($user === null, 403);

        return $user;
    }

    public function render(DuprEligibility $eligibility): View
    {
        $this->session->refresh();

        return view('livewire.sessions.dupr-export-page', [
            'summary' => $eligibility->summarize($this->session),
            'history' => $this->session->duprExports()->with('user')->latest()->latest('id')->get(),
        ]);
    }

    /** "A1 & A2" for one team of a match (matchPlayers.player must be loaded). */
    public static function teamNames(GameMatch $match, string $team): string
    {
        return $match->matchPlayers
            ->filter(fn ($mp) => $mp->team->value === $team)
            ->sortBy('slot')
            ->map(fn ($mp) => $mp->player?->name)
            ->implode(' & ');
    }
}
