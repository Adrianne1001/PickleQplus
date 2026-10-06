<?php

namespace App\Livewire\Public;

use App\Models\Club;
use App\Models\PlaySession;
use App\Services\CheckInQrService;
use App\Services\PublicSessionView;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Full-screen, read-only TV display for one session. Reached through a secret
 * tv_id (not derivable from the shared queue link), because it shows the live
 * check-in QR. State holds only the club slug and tv id.
 */
#[Layout('layouts::public', ['dark' => true])]
#[Title('TV')]
class Tv extends Component
{
    /** How many waiting players the TV lists before "+N more". */
    public const WAITING_LIMIT = 12;

    #[Locked]
    public string $clubSlug = '';

    #[Locked]
    public string $tvId = '';

    private ?PlaySession $resolved = null;

    public function mount(Club $club, string $tvId): void
    {
        PlaySession::findByTvIdOrFail($club, $tvId);

        $this->clubSlug = $club->slug;
        $this->tvId = $tvId;
    }

    /**
     * The channel is keyed by the session's public id; the 30s/15s poll is the fallback.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $publicId = $this->session()->public_id;

        return ['echo:play-session.'.$publicId.',.session.updated' => '$refresh'];
    }

    public function render(CheckInQrService $qr): View
    {
        $session = $this->session();
        $data = app(PublicSessionView::class)->snapshot($session);
        $token = $session->checkin_token;
        $open = ! $session->isEnded() && $token !== null;

        return view('livewire.public.tv', [
            'data' => $data,
            // The SVG only depends on the token, so don't rebuild it on every refresh.
            'svg' => $open ? Cache::remember('qr:tv:'.hash('sha256', (string) $qr->url($session)), 3600, fn (): ?string => $qr->svg($session, 480)) : null,
            'checkinUrl' => $open ? $qr->url($session) : null,
            'waitingLimit' => self::WAITING_LIMIT,
        ]);
    }

    private function session(): PlaySession
    {
        if ($this->resolved === null) {
            $club = Club::query()->where('slug', $this->clubSlug)->firstOrFail();
            $this->resolved = PlaySession::findByTvIdOrFail($club, $this->tvId);
        }

        return $this->resolved;
    }
}
