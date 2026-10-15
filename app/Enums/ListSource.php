<?php

namespace App\Enums;

/** How a player got onto a session's player list. */
enum ListSource: string
{
    case Manual = 'manual';
    case Paste = 'paste';
    case Reclub = 'reclub';

    public static function fromInput(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Manual) : self::Manual;
    }
}
