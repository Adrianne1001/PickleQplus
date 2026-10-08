<?php

use App\Enums\MatchStatus;
use App\Enums\Team;
use App\Livewire\Sessions\Results;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array{0: User, 1: PlaySession}
 */
function historySession(): array
{
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();

    return [$user, PlaySession::factory()->for($club)->live()->create()];
}

function historyMatch(PlaySession $session, string $a1, string $a2, string $b1, string $b2, ?int $scoreA, ?int $scoreB, int $minutesAgo): GameMatch
{
    $match = GameMatch::factory()->create([
        'play_session_id' => $session->id,
        'status' => MatchStatus::Done,
        'court_no' => 2,
        'team_a_score' => $scoreA,
        'team_b_score' => $scoreB,
        'started_at' => now()->subMinutes($minutesAgo + 12),
        'finished_at' => now()->subMinutes($minutesAgo),
    ]);

    foreach ([[Team::A, 1, $a1], [Team::A, 2, $a2], [Team::B, 1, $b1], [Team::B, 2, $b2]] as [$team, $slot, $name]) {
        MatchPlayer::factory()->create([
            'match_id' => $match->id,
            'player_id' => Player::factory()->for($session->club)->create(['name' => $name])->id,
            'team' => $team,
            'slot' => $slot,
        ]);
    }

    return $match;
}

test('match history lists matches newest first with numbers and matchups', function () {
    [$user, $session] = historySession();
    historyMatch($session, 'Ann Old', 'Bob Old', 'Cy Old', 'Di Old', 11, 3, 60);
    historyMatch($session, 'Ann New', 'Bob New', 'Cy New', 'Di New', 9, 11, 5);

    Livewire::actingAs($user)->test(Results::class, ['session' => $session])
        ->assertSeeInOrder(['#2', 'Ann New & Bob New', 'Cy New & Di New', '#1', 'Ann Old & Bob Old'])
        ->assertSee('Match history')
        ->assertSee('2 matches')
        ->assertDontSee('Recent results');
});

test('the winner marker appears once, on the winning team', function () {
    [$user, $session] = historySession();
    historyMatch($session, 'Ann', 'Bob', 'Cy', 'Di', 5, 11, 5);

    Livewire::actingAs($user)->test(Results::class, ['session' => $session])
        ->assertSeeHtml('data-test="result-winner"')
        ->assertSeeInOrder(['Ann & Bob', 'Cy & Di', 'Won'])
        ->assertDontSeeHtml('data-test="results-panel"><x');

    $html = Livewire::actingAs($user)->test(Results::class, ['session' => $session])->html();
    expect(substr_count($html, 'data-test="result-winner"'))->toBe(1)
        ->and(strpos($html, 'Won'))->toBeGreaterThan(strpos($html, 'Cy &amp; Di'));
});

test('ties and missing scores show no winner', function () {
    [$user, $session] = historySession();
    historyMatch($session, 'Ann', 'Bob', 'Cy', 'Di', 11, 11, 20);
    historyMatch($session, 'Eve', 'Fay', 'Gus', 'Hal', null, null, 10);

    Livewire::actingAs($user)->test(Results::class, ['session' => $session])
        ->assertSee('Ann & Bob')
        ->assertDontSeeHtml('data-test="result-winner"');
});

test('show more reveals older matches', function () {
    [$user, $session] = historySession();
    foreach (range(1, 25) as $i) {
        historyMatch($session, "A$i", "B$i", "C$i", "D$i", 11, 5, 100 - $i);
    }

    Livewire::actingAs($user)->test(Results::class, ['session' => $session])
        ->assertSee('25 matches')
        ->assertSeeHtml('data-test="show-more-button"')
        ->assertDontSee('A1 & B1')
        ->call('showMore')
        ->assertSee('A1 & B1')
        ->assertDontSeeHtml('data-test="show-more-button"');
});

test('an empty history shows the empty state', function () {
    [$user, $session] = historySession();

    Livewire::actingAs($user)->test(Results::class, ['session' => $session])
        ->assertSee('No matches in the history yet.')
        ->assertDontSeeHtml('data-test="show-more-button"');
});
