<?php

namespace App\Livewire\Sessions;

use App\Models\Club;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\CheckInQrService;
use App\Services\PlaySessionService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Organizer board panel: check-in QR, regenerate, and the public share links.
 */
class CheckInQr extends Component
{
    #[Locked]
    public PlaySession $session;

    public function mount(PlaySession $session): void
    {
        $this->authorize('manage', $session);

        $this->session = $session;
    }

    #[On('board-changed')]
    #[On('session-changed')]
    public function refreshPanel(): void
    {
        $this->session->refresh();
    }

    public function regenerate(PlaySessionService $sessions): void
    {
        $this->authorize('manage', $this->session);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $sessions->regenerateCheckinToken($this->session, $user);
        $this->session->refresh();

        Flux::modals()->close();
        Flux::toast(variant: 'success', text: __('New QR generated. The old one no longer works.'));
    }

    public function resetTvLink(PlaySessionService $sessions): void
    {
        $this->authorize('manage', $this->session);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $sessions->resetTvLink($this->session, $user);
        $this->session->refresh();

        Flux::toast(variant: 'success', text: __('New TV link created. Open it on the TV.'));
    }

    public function render(CheckInQrService $qr): View
    {
        /** @var Club $club */
        $club = $this->session->club()->firstOrFail();
        $base = url('/c/'.$club->slug.'/s/'.$this->session->public_id);

        return view('livewire.sessions.check-in-qr', [
            'svg' => $this->session->isEnded() ? null : $qr->svg($this->session, 320),
            'checkinUrl' => $this->session->isEnded() ? null : $qr->url($this->session),
            'queueUrl' => $base,
            'tvUrl' => url('/c/'.$club->slug.'/tv/'.$this->session->tv_id),
        ]);
    }
}
