<?php

namespace App\Console\Commands;

use App\Services\GuestPurgeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pickleq:purge-guest-clubs {--chunk=50 : Clubs handled per batch}')]
#[Description('Delete expired guest clubs with their sessions, files and caches')]
class PurgeGuestClubs extends Command
{
    public function handle(GuestPurgeService $purger): int
    {
        $purged = $purger->purgeExpired(max(1, (int) $this->option('chunk')));

        $this->info("Purged {$purged} guest club(s).");

        return self::SUCCESS;
    }
}
