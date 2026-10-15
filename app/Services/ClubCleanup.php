<?php

namespace App\Services;

use App\Enums\StatsPeriod;
use App\Models\Club;
use App\Models\PlaySession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The leftovers of a deleted club that the database cascade cannot remove: files on the
 * local disk and cached public pages. Shared by ClubService::delete and the guest purge.
 * Collect first, then delete the club, then call afterDelete() once the delete committed.
 */
class ClubCleanup
{
    /**
     * Ids of the club's sessions, to hand to afterDelete().
     *
     * @return list<int>
     */
    public function sessionIds(Club $club): array
    {
        return array_values(PlaySession::query()->where('club_id', $club->id)->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * Remove podium images, DUPR export files and the public caches of a deleted club.
     *
     * @param  list<int>  $sessionIds
     */
    public function afterDelete(int $clubId, array $sessionIds): void
    {
        $disk = Storage::disk('local');

        foreach ($sessionIds as $id) {
            $disk->deleteDirectory('podium/'.$id);
            Cache::forget("public-session-results:{$id}");
        }
        $disk->deleteDirectory('dupr-exports/'.$clubId);

        $pages = max(1, (int) ceil(count($sessionIds) / SessionResultsService::PER_PAGE));
        Cache::forget("public-sessions-total:{$clubId}");
        for ($page = 1; $page <= $pages; $page++) {
            Cache::forget("public-sessions:{$clubId}:{$page}");
        }
        foreach (StatsPeriod::cases() as $period) {
            Cache::forget("public-stats:{$clubId}:{$period->value}");
        }
    }
}
