<?php

namespace App\Services\Share;

/** Plain data for a podium image: the top 3 of a session (ties share a rank). */
final readonly class PodiumData
{
    /** @param  list<PodiumEntry>  $entries  1 to 3 entries, best first */
    public function __construct(
        public string $clubName,
        public string $sessionName,
        public string $dateLabel,
        public array $entries,
    ) {}

    /** Stable hash of everything that ends up in the picture. */
    public function hash(): string
    {
        return md5((string) json_encode([
            $this->clubName,
            $this->sessionName,
            $this->dateLabel,
            array_map(fn (PodiumEntry $e) => [$e->rank, $e->name, $e->wins, $e->losses, $e->winPct], $this->entries),
        ], JSON_UNESCAPED_UNICODE));
    }
}
