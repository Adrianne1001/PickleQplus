<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after any committed change to a play session (settings, lifecycle,
 * check-ins, queue, courts, matches). Becomes a broadcast event in P3.1.
 */
class PlaySessionChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $playSessionId) {}
}
