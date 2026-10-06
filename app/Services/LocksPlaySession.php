<?php

namespace App\Services;

use App\Models\PlaySession;

/**
 * Phase 2 concurrency rule: every state change to a session runs in a
 * transaction that first locks the play_sessions row.
 */
trait LocksPlaySession
{
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
