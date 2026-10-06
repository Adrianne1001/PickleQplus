<?php

namespace App\Listeners;

use App\Events\PlaySessionChanged;
use App\Events\SessionUpdated;
use App\Models\PlaySession;

/**
 * Turns PlaySessionChanged into at most one SessionUpdated broadcast per
 * session per request/job. The work is deferred with a per-session name, so
 * Laravel's defer() collapses repeats and runs it once when the request, job
 * or command finishes (defer runs callbacks through rescue(), so a broadcasting
 * outage is reported but never fails the organizer action). Tests flush with
 * defer()->invoke().
 */
class BroadcastSessionUpdate
{
    public function handle(PlaySessionChanged $event): void
    {
        $id = $event->playSessionId;

        defer(function () use ($id): void {
            $publicId = PlaySession::query()->whereKey($id)->value('public_id');

            if (is_string($publicId)) {
                SessionUpdated::dispatch($publicId);
            }
        }, 'session-updated:'.$id, always: true);
    }
}
