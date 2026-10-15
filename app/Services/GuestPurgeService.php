<?php

namespace App\Services;

use App\Events\SessionExpired;
use App\Models\Club;
use App\Models\PlaySession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deletes expired guest clubs. Per club, in its own transaction: lock the club row, re-check it
 * is still a guest and expired (a claim may have raced us, and claim locks the same row), collect
 * what the cascade will not clean up, then delete the club. After commit: files and caches, a
 * 7 day "guest-gone" tombstone (so the old link says "expired"), and a session.expired broadcast.
 */
class GuestPurgeService
{
    public const TOMBSTONE_DAYS = 7;

    public function __construct(private readonly ClubCleanup $cleanup) {}

    /**
     * Purge every expired guest club, in chunks. A failure on one club is reported and skipped.
     *
     * @return int how many clubs were purged
     */
    public function purgeExpired(int $chunk = 50): int
    {
        $purged = 0;

        Club::query()->expiredGuests()->select('id')->chunkById($chunk, function ($clubs) use (&$purged): void {
            foreach ($clubs as $club) {
                try {
                    $purged += $this->purge((int) $club->id) ? 1 : 0;
                } catch (Throwable $e) {
                    report($e);
                }
            }
        });

        return $purged;
    }

    /**
     * Purge one club if it is still an expired guest club. Returns false when it was skipped.
     */
    public function purge(int $clubId): bool
    {
        $gone = DB::transaction(function () use ($clubId): ?array {
            $club = Club::query()->whereKey($clubId)->lockForUpdate()->first();

            if ($club === null || ! $club->isGuestExpired()) {
                return null; // gone already, claimed, or revived: never purge it
            }

            $sessions = PlaySession::query()->where('club_id', $club->id)->get(['id', 'public_id']);
            $result = [
                'slug' => $club->slug,
                'session_ids' => array_values($sessions->pluck('id')->map(fn ($id): int => (int) $id)->all()),
                'public_ids' => array_values($sessions->pluck('public_id')->filter()->map(fn ($p): string => (string) $p)->all()),
            ];

            $club->delete();

            return $result;
        });

        if ($gone === null) {
            return false;
        }

        $this->afterCommit($clubId, $gone);

        return true;
    }

    /**
     * @param  array{slug: string, session_ids: list<int>, public_ids: list<string>}  $gone
     */
    private function afterCommit(int $clubId, array $gone): void
    {
        // Each step is isolated: the club is already deleted, so a failing step must not stop the others.
        $steps = [
            fn () => $this->cleanup->afterDelete($clubId, $gone['session_ids']),
            fn () => Cache::put('guest-gone:'.$gone['slug'], true, now()->addDays(self::TOMBSTONE_DAYS)),
            function () use ($gone): void {
                foreach ($gone['public_ids'] as $publicId) {
                    SessionExpired::dispatch($publicId);
                }
            },
        ];

        foreach ($steps as $step) {
            try {
                $step();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
