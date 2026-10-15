<?php

namespace App\Listeners;

use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\PlaySession;
use App\Services\GuestClubService;
use Throwable;

/**
 * A guest club lives until `idle_hours` after its last activity, never longer than
 * `max_hours` after creation. Activity is any PlaySessionChanged event (it fires after
 * commit, so a rolled-back action never counts); page views and polling never fire it.
 * It does nothing for a normal club, and never revives a guest club that has expired.
 */
class TouchGuestActivity
{
    public function __construct(private readonly GuestClubService $guests) {}

    public function handle(PlaySessionChanged $event): void
    {
        // The action has already committed: a failure here must never turn it into a 500.
        try {
            $this->touch($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function touch(PlaySessionChanged $event): void
    {
        $clubId = PlaySession::query()->whereKey($event->playSessionId)->value('club_id');
        if ($clubId === null) {
            return;
        }

        $club = Club::query()->activeGuests()->whereKey($clubId)->first();
        if ($club === null) {
            return;
        }

        $expires = $this->guests->expiryFromNow($club);
        if ($club->guest_expires_at !== null && $expires->lte($club->guest_expires_at)) {
            return;
        }

        // Same conditions again, so a club that expired in the meantime stays expired.
        Club::query()->activeGuests()->whereKey($club->id)->update(['guest_expires_at' => $expires]);
    }
}
