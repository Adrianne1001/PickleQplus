<?php

namespace App\Enums;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Stats period, resolved to an inclusive range of session dates in the
 * timezone of the given "now". Pure: pass a frozen time to test it.
 */
enum StatsPeriod: string
{
    case AllTime = 'all_time';
    case ThisMonth = 'this_month';
    case Last30Days = 'last_30_days';
    case ThisYear = 'this_year';

    public static function fromInput(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::AllTime;
    }

    public function label(): string
    {
        return match ($this) {
            self::AllTime => 'All time',
            self::ThisMonth => 'This month',
            self::Last30Days => 'Last 30 days',
            self::ThisYear => 'This year',
        };
    }

    /**
     * Inclusive [from, to] as Y-m-d, or null for all time.
     *
     * @return array{0: string, 1: string}|null
     */
    public function range(DateTimeInterface $now): ?array
    {
        $today = DateTimeImmutable::createFromInterface($now)->setTime(0, 0);

        return match ($this) {
            self::AllTime => null,
            self::ThisMonth => [$today->modify('first day of this month')->format('Y-m-d'), $today->modify('last day of this month')->format('Y-m-d')],
            self::Last30Days => [$today->modify('-29 days')->format('Y-m-d'), $today->format('Y-m-d')],
            self::ThisYear => [$today->format('Y').'-01-01', $today->format('Y').'-12-31'],
        };
    }
}
