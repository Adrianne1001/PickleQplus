<?php

use App\Enums\StatsPeriod;

it('resolves period ranges from a frozen clock', function () {
    $now = new DateTimeImmutable('2026-03-15 14:30:00');

    expect(StatsPeriod::AllTime->range($now))->toBeNull()
        ->and(StatsPeriod::ThisMonth->range($now))->toBe(['2026-03-01', '2026-03-31'])
        ->and(StatsPeriod::Last30Days->range($now))->toBe(['2026-02-14', '2026-03-15'])
        ->and(StatsPeriod::ThisYear->range($now))->toBe(['2026-01-01', '2026-12-31']);
});

it('handles month and year boundaries', function () {
    expect(StatsPeriod::ThisMonth->range(new DateTimeImmutable('2024-02-10')))->toBe(['2024-02-01', '2024-02-29'])
        ->and(StatsPeriod::Last30Days->range(new DateTimeImmutable('2026-01-05')))->toBe(['2025-12-07', '2026-01-05']);
});

it('falls back to all time for unknown input', function () {
    expect(StatsPeriod::fromInput(null))->toBe(StatsPeriod::AllTime)
        ->and(StatsPeriod::fromInput('bogus'))->toBe(StatsPeriod::AllTime)
        ->and(StatsPeriod::fromInput('this_year'))->toBe(StatsPeriod::ThisYear);
});
