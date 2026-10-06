<?php

namespace App\Enums;

enum SessionPlayerStatus: string
{
    case Waiting = 'waiting';
    case Playing = 'playing';
    case Break = 'break';
    case Left = 'left';
}
