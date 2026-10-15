<?php

namespace App\Services;

use App\Models\Club;
use App\Models\PlaySession;
use Illuminate\Validation\ValidationException;

/**
 * Phase 2 concurrency rule: every state change to a session runs in a
 * transaction that first locks the play_sessions row.
 */
trait LocksPlaySession
{
    /**
     * Refuse a write when the session's club is an expired guest club (Phase 14), even before the
     * purge has run. A plain read, so call it BEFORE the transaction opens (it must not create a
     * snapshot inside the lock-first transactions), or use guardClubNotExpired() on a club row
     * the transaction already holds locked.
     *
     * @throws ValidationException
     */
    protected function guardGuestActive(PlaySession $session): void
    {
        $this->guardClubNotExpired(Club::query()->whereKey($session->club_id)->first(['id', 'is_guest', 'guest_expires_at']));
    }

    /**
     * @throws ValidationException
     */
    protected function guardClubNotExpired(?Club $club): void
    {
        if ($club !== null && $club->isGuestExpired()) {
            throw ValidationException::withMessages(['session' => 'This guest session has expired.']);
        }
    }

    /**
     * Lock the session row and refresh the given instance from it. Call inside
     * a transaction.
     */
    protected function lockSession(PlaySession $session): PlaySession
    {
        $locked = PlaySession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

        $session->setRawAttributes($locked->getAttributes(), true);

        return $session;
    }
}
