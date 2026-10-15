<?php

namespace App\Livewire\Guest;

use App\Models\Club;
use App\Services\CheckInQrService;
use App\Services\GuestClubService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The private organizer link of a guest session (Phase 14): copy button and QR, collapsed
 * by default. The link is read back from the organizer cookie, so it only shows on a device
 * that already holds it. It's a separate component so the board's polling never rebuilds the QR.
 */
class OrganizerLink extends Component
{
    #[Locked]
    public Club $club;

    public function mount(Club $club): void
    {
        abort_unless($club->is_guest, 404);

        $this->club = $club;
    }

    public function render(GuestClubService $guests, CheckInQrService $qr): View
    {
        $url = $guests->organizerUrl($this->club);

        return view('livewire.guest.organizer-link', [
            'url' => $url,
            'svg' => $url === null ? null : $qr->svgForUrl($url, 320),
        ]);
    }
}
