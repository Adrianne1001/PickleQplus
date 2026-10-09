<?php

namespace App\Enums;

enum RotationMode: string
{
    case Balanced = 'balanced';
    case Mixed = 'mixed';
    case SkillCourts = 'skill_courts';
    case Social = 'social';
    case WinnersStay = 'winners_stay';
    case KingOfCourt = 'king_of_court';

    /**
     * Modes a session may be set to right now. Later P7.4 items add their mode
     * to config('pickleq.rotation_modes_enabled').
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        $enabled = (array) config('pickleq.rotation_modes_enabled', ['balanced']);

        return array_values(array_filter(self::cases(), fn (self $m): bool => in_array($m->value, $enabled, true)));
    }

    public function label(): string
    {
        return match ($this) {
            self::Balanced => 'Balanced',
            self::Mixed => 'Mixed doubles',
            self::SkillCourts => 'Skill courts',
            self::Social => 'Social mix',
            self::WinnersStay => 'Winners stay',
            self::KingOfCourt => 'King of the Court',
        };
    }
}
