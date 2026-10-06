<?php

namespace App\Services\Dupr;

use App\Models\DuprExport;
use App\Models\GameMatch;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sends a session's eligible matches to DUPR. CsvPublisher (v1) writes a file;
 * PartnerApiPublisher (Phase 7, behind a config flag) will call the DUPR API.
 * Eligibility and locking are the caller's job (DuprExportService).
 */
interface DuprPublisher
{
    /**
     * @param  Collection<int, GameMatch>  $matches  Eligible matches with matchPlayers.player loaded.
     */
    public function publish(PlaySession $session, Collection $matches, User $user): DuprExport;
}
