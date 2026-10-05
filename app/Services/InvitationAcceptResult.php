<?php

namespace App\Services;

use App\Models\Club;

/**
 * Outcome of accepting an invitation: the club, and whether the user was
 * added (false when they were already a member and kept their role).
 */
final readonly class InvitationAcceptResult
{
    public function __construct(
        public Club $club,
        public bool $joined,
    ) {}
}
