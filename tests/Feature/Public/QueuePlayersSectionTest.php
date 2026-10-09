<?php

use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Livewire\Public\Queue;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use Livewire\Livewire;

/** @return array{0: Club, 1: PlaySession, 2: Player} */
function playersSectionSetup(): array
{
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->live()->create();
    $players = Player::factory()->for($club)->manual(3)->count(4)->create();
    foreach ($players as $p) {
        SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $p->id, 'status' => SessionPlayerStatus::Waiting, 'games_played' => $p->is($players[0]) ? 3 : 0]);
    }
    foreach ([[11, 5], [11, 2]] as [$a, $b]) {
        $match = GameMatch::factory()->for($session)->create(['status' => MatchStatus::Done, 'court_no' => null, 'team_a_score' => $a, 'team_b_score' => $b]);
        foreach ($players as $i => $p) {
            MatchPlayer::factory()->create(['match_id' => $match->id, 'player_id' => $p->id, 'team' => $i < 2 ? 'A' : 'B', 'slot' => $i % 2 + 1]);
        }
    }

    return [$club, $session, $players[0]];
}

it('lists players with games and wins at the bottom and shows no wins badge elsewhere', function (): void {
    [$club, $session, $hero] = playersSectionSetup();
    $match = GameMatch::factory()->for($session)->create(['status' => MatchStatus::Playing, 'court_no' => 1, 'started_at' => now()]);
    $others = Player::factory()->for($club)->manual(3)->count(4)->create();
    foreach ($others as $i => $p) {
        SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $p->id, 'status' => SessionPlayerStatus::Playing]);
        MatchPlayer::factory()->create(['match_id' => $match->id, 'player_id' => $p->id, 'team' => $i < 2 ? 'A' : 'B', 'slot' => $i % 2 + 1]);
    }

    $html = Livewire::test(Queue::class, ['club' => $club, 'publicId' => $session->public_id])->html();

    expect($html)->toContain('data-test="queue-players"')
        ->toContain('Players (8)')
        ->toContain('3 games')
        ->toContain('2 wins')
        ->toContain('data-test="player-games"')
        ->toContain('data-test="match-board"');

    // The only wins text is inside the Players section.
    $beforePlayers = substr($html, 0, strpos($html, 'data-test="queue-players"'));
    expect($beforePlayers)->not->toContain('data-test="player-wins"');
    expect(preg_match('/>\s*\d+ wins?\s*</', $beforePlayers))->toBe(0);
    expect(strpos($html, 'data-test="queue-players"'))->toBeGreaterThan(strpos($html, 'data-test="queue-waiting"'));
});

it('does not show the players section before the session is live', function (): void {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->create();

    Livewire::test(Queue::class, ['club' => $club, 'publicId' => $session->public_id])->assertDontSeeHtml('data-test="queue-players"');
});
