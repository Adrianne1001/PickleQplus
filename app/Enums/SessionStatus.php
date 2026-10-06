<?php

namespace App\Enums;

enum SessionStatus: string
{
    case Draft = 'draft';
    case Live = 'live';
    case Ended = 'ended';
}
