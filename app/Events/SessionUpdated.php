<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Public, player-data-free "refresh now" ping for a session's live views.
 */
class SessionUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $publicId) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new Channel('play-session.'.$this->publicId)];
    }

    public function broadcastAs(): string
    {
        return 'session.updated';
    }

    /**
     * @return array{public_id: string, at: string}
     */
    public function broadcastWith(): array
    {
        return ['public_id' => $this->publicId, 'at' => now()->toIso8601String()];
    }
}
