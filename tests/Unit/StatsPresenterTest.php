<?php

use App\Support\StatsPresenter;

test('duration formats minutes and hours', function () {
    expect(StatsPresenter::duration(null))->toBe('–')
        ->and(StatsPresenter::duration(12))->toBe('12 min')
        ->and(StatsPresenter::duration(59))->toBe('59 min')
        ->and(StatsPresenter::duration(60))->toBe('1 h')
        ->and(StatsPresenter::duration(65))->toBe('1 h 5 min');
});

test('initials use first and last word', function () {
    expect(StatsPresenter::initials('ana lopez'))->toBe('AL')
        ->and(StatsPresenter::initials('Ana Maria Lopez'))->toBe('AL')
        ->and(StatsPresenter::initials('Bo'))->toBe('B')
        ->and(StatsPresenter::initials('  '))->toBe('?');
});

test('avatar colour is deterministic per name', function () {
    expect(StatsPresenter::avatarClasses('Ana Lopez'))->toBe(StatsPresenter::avatarClasses(' ana lopez '))
        ->and(StatsPresenter::avatarClasses('Ana'))->toContain('bg-');
});
