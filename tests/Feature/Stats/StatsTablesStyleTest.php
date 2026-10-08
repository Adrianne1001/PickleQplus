<?php

use Illuminate\Support\Carbon;

function tblRow(?int $rank, string $name, int $played = 5, int $wins = 3, int $diff = 4): array
{
    return ['rank' => $rank, 'name' => $name, 'played' => $played, 'wins' => $wins, 'losses' => $played - $wins,
        'win_pct' => $played ? (int) round($wins / $played * 100) : 0, 'point_diff' => $diff];
}

function logRow(array $over = []): array
{
    return $over + ['court' => 2, 'finished_at' => Carbon::parse('2026-10-08 13:21'), 'duration_minutes' => 12,
        'team_a' => ['Ann', 'Bob'], 'team_b' => ['Cy', 'Di'], 'score_a' => 11, 'score_b' => 7];
}

it('puts medals on the top 3 and lets tied ranks share one', function () {
    $rows = [tblRow(1, 'Ann'), tblRow(2, 'Bob'), tblRow(2, 'Cy'), tblRow(4, 'Di')];

    $view = $this->blade('<x-stats.table :rows="$rows" />', ['rows' => $rows]);

    $view->assertSee('data-medal="gold"', false)
        ->assertSee('data-medal="silver"', false)
        ->assertDontSee('data-medal="bronze"', false);
    expect(substr_count((string) $view, 'data-test="medal"'))->toBe(3)
        ->and(substr_count((string) $view, 'data-test="stats-row"'))->toBe(4);
});

it('shows no rank or medals on the unranked list but shows games progress', function () {
    $rows = [tblRow(null, 'Eve', played: 6, wins: 3)];

    $this->blade('<x-stats.table :rows="$rows" :show-rank="false" :min-games="10" />', ['rows' => $rows])
        ->assertSee('6 / 10 games')
        ->assertDontSee('data-test="medal"', false);
});

it('formats the point diff and renders the caption', function () {
    $rows = [tblRow(1, 'Ann', diff: 7), tblRow(2, 'Bob', diff: -3)];

    $this->blade('<x-stats.table :rows="$rows" caption="Club · PickleQ+" />', ['rows' => $rows])
        ->assertSee('+7')
        ->assertSee('−3')
        ->assertSee('Club · PickleQ+');
});

it('marks the winning team and nothing on a tie', function () {
    $win = [logRow()];
    $tie = [logRow(['score_a' => 9, 'score_b' => 9])];
    $none = [logRow(['score_a' => null, 'score_b' => null])];

    $view = $this->blade('<x-stats.match-log :matches="$m" />', ['m' => $win]);
    $view->assertSee('Won');
    expect((string) $view)->toMatch('/data-winner="1"\s*>\s*<span>Ann &amp; Bob<\/span>\s*<span[^>]*data-test="won-marker"/');

    $this->blade('<x-stats.match-log :matches="$m" />', ['m' => $tie])->assertDontSee('won-marker', false);
    $this->blade('<x-stats.match-log :matches="$m" />', ['m' => $none])->assertDontSee('won-marker', false);
});

it('formats long durations as hours and minutes', function () {
    $m = [logRow(['duration_minutes' => 65]), logRow(['duration_minutes' => null])];

    $this->blade('<x-stats.match-log :matches="$m" />', ['m' => $m])->assertSee('1 h 5 min')->assertSee('–');
});

it('shows the date when a match finished on another day than the session', function () {
    $m = [logRow()];

    $this->blade('<x-stats.match-log :matches="$m" :session-date="$d" />', ['m' => $m, 'd' => Carbon::parse('2026-10-09')])
        ->assertSee('Oct 8 · 13:21');
    $this->blade('<x-stats.match-log :matches="$m" :session-date="$d" />', ['m' => $m, 'd' => Carbon::parse('2026-10-08')])
        ->assertDontSee('Oct 8')->assertSee('13:21');
});

it('keeps void rows struck through with the badge', function () {
    $m = [logRow(['void' => true])];

    $this->blade('<x-stats.match-log :matches="$m" />', ['m' => $m])
        ->assertSee('data-void="1"', false)->assertSee('line-through', false)->assertSee('void')
        ->assertDontSee('won-marker', false);
});
