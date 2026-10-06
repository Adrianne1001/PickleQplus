<?php

namespace App\Services\Dupr;

enum DuprSkipReason: string
{
    case MissingDuprId = 'missing_dupr_id';
    case AlreadyExported = 'already_exported';
    case NotEligible = 'not_eligible';
    /** A done match without both scores (or without 4 players); it cannot be exported. */
    case Incomplete = 'incomplete';

    public function label(): string
    {
        return match ($this) {
            self::MissingDuprId => 'Missing DUPR ID',
            self::AlreadyExported => 'Already exported',
            self::NotEligible => 'Marked not eligible',
            self::Incomplete => 'Score or players incomplete',
        };
    }
}
