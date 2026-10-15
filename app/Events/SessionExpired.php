<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A guest session's data has been deleted: open public and board pages should stop and say so.
 * Carries only the public id, like SessionUpdated.
 */
class SessionExpired implements ShouldBroadcast
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
        return 'session.expired';
    }

    /**
     * @return array{public_id: string}
     */
    public function broadcastWith(): array
    {
        return ['public_id' => $this->publicId];
    }
}
