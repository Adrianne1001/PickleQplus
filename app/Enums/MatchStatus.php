<?php

namespace App\Enums;

enum MatchStatus: string
{
    case Staged = 'staged';
    case Playing = 'playing';
    case Done = 'done';
    case Void = 'void';
}
