<?php

namespace App\Services\RosterImport;

use App\Models\Club;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Keeps a roster import preview on the server between the preview and confirm
 * steps, so it never travels in a Livewire snapshot. Entries are keyed by user,
 * club and a random id, and expire after config('pickleq.import_preview_ttl_minutes').
 */
class RosterImportPreviewStore
{
    /**
     * @return string the random id of the stored preview
     */
    public function put(User $user, Club $club, RosterImportPreview $preview): string
    {
        $id = (string) Str::uuid();

        Cache::put($this->key($user, $club, $id), $preview->toArray(), now()->addMinutes($this->ttl()));

        return $id;
    }

    /**
     * Null when missing, expired, or stored for another user or club.
     */
    public function get(User $user, Club $club, string $id): ?RosterImportPreview
    {
        $stored = Cache::get($this->key($user, $club, $id));

        if (! is_array($stored) || ! isset($stored['club_id'], $stored['rows'])) {
            return null;
        }

        /** @var array{club_id: int, rows: list<array{line: int, action: 'create'|'update'|'skip'|'error', messages: list<string>, data: array{name: string, dupr_id: string|null, dupr_rating: string|null}, player_id: int|null}>} $stored */
        $preview = RosterImportPreview::fromArray($stored);

        return $preview->clubId === $club->id ? $preview : null;
    }

    public function forget(User $user, Club $club, string $id): void
    {
        Cache::forget($this->key($user, $club, $id));
    }

    private function key(User $user, Club $club, string $id): string
    {
        return "roster-import-preview:{$user->id}:{$club->id}:{$id}";
    }

    private function ttl(): int
    {
        return max(1, (int) config('pickleq.import_preview_ttl_minutes', 30));
    }
}
