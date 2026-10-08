<?php

namespace App\Livewire\Sessions;

use App\Models\Club;
use App\Models\PlaySession;
use App\Services\CheckInQrService;
use App\Services\SessionResultsService;
use App\Support\ResultsShareText;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Staff results page of one session: shareable results design (P11.4): podium, highlights, standings, match log and the share panel.
 */
#[Title('Session results')]
class ResultsPage extends Component
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

    public function render(SessionResultsService $service, CheckInQrService $qr): View
    {
        $results = $service->staffResults($this->session);

        $standings = array_map(fn (array $r): array => $r + ['url' => route('clubs.players.show', [$this->club, $r['id'] ?? 0])], $results['standings']);

        $neighbour = fn (?array $n): ?array => $n === null ? null : [
            'url' => route('clubs.sessions.results', [$this->club, $n['id']]),
            'name' => $n['name'],
            'date' => $n['date'],
        ];

        $share = null;
        if ($this->session->isEnded() && $this->session->public_id) {
            $shareUrl = route('public.queue', [$this->club, $this->session->public_id]);
            $hasPodium = $results['podium'] !== [];
            $params = [$this->club, $this->session->public_id];
            $share = [
                'url' => $shareUrl,
                'svg' => $qr->svgForUrl($shareUrl, 320),
                'title' => $this->session->name.' · '.$this->club->name,
                'text' => $hasPodium
                    ? $this->session->name.' · '.$this->club->name.' '.ResultsShareText::podiumLine($results['podium'])
                    : $this->session->name.' · '.$this->club->name,
                'gif' => $hasPodium ? route('public.session.podium-gif', $params) : null,
                'png' => $hasPodium ? route('public.session.podium-png', $params) : null,
            ];
        }

        return view('livewire.sessions.results-page', [
            'results' => $results,
            'standings' => $standings,
            'previous' => $neighbour($results['previous']),
            'next' => $neighbour($results['next']),
            'share' => $share,
        ]);
    }
}
