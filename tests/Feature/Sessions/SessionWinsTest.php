<?php

use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Livewire\Public\Queue;
use App\Livewire\Sessions\CheckInPanel;
use App\Livewire\Sessions\WaitingList;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\PublicSessionView;
use App\Services\SessionBoard;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * A match with $hero on team A and three others, with the given status and scores.
 *
 * @param  list<Player>  $others
 */
function winsMatch(PlaySession $session, Player $hero, array $others, MatchStatus $status, ?int $a, ?int $b, bool $heroOnA = true): GameMatch
{
    $match = GameMatch::factory()->for($session)->create([
        'status' => $status,
        'court_no' => $status === MatchStatus::Playing ? 1 : null,
        'started_at' => now(),
        'team_a_score' => $a,
        'team_b_score' => $b,
    ]);
    $four = $heroOnA ? [$hero, ...$others] : [...$others, $hero];
    foreach ($four as $i => $p) {
        MatchPlayer::factory()->create(['match_id' => $match->id, 'player_id' => $p->id, 'team' => $i < 2 ? 'A' : 'B', 'slot' => $i % 2 + 1]);
    }

    return $match;
}

/** @return array{0: Club, 1: PlaySession, 2: Player, 3: list<Player>} */
function winsSetup(): array
{
    $club = Club::factory()->withOwner(User::factory()->create())->create();
    $session = PlaySession::factory()->for($club)->live()->create();
    $hero = Player::factory()->for($club)->manual(3)->create();
    $others = Player::factory()->for($club)->manual(3)->count(3)->create()->all();
    foreach ([$hero, ...$others] as $p) {
        SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $p->id, 'status' => SessionPlayerStatus::Waiting]);
    }

    // Counts: hero 2, others[0] 1, others[1] 1, others[2] 2. Nothing below the loss line may count.
    winsMatch($session, $hero, $others, MatchStatus::Done, 11, 5);
    winsMatch($session, $hero, $others, MatchStatus::Done, 4, 11, heroOnA: false);
    winsMatch($session, $hero, $others, MatchStatus::Done, 3, 11);
    winsMatch($session, $hero, $others, MatchStatus::Void, 11, 0);
    winsMatch($session, $hero, $others, MatchStatus::Done, null, null);
    winsMatch($session, $hero, $others, MatchStatus::Done, 7, 7);
    winsMatch($session, $hero, $others, MatchStatus::Done, 11, null);
    $other = PlaySession::factory()->for($club)->live()->create();
    winsMatch($other, $hero, $others, MatchStatus::Done, 11, 2);
    $foreignSession = PlaySession::factory()->for(Club::factory()->create())->live()->create();
    winsMatch($foreignSession, $hero, $others, MatchStatus::Done, 11, 2);

    return [$club, $session, $hero, $others];
}

it('counts only won done matches of this session per player', function (): void {
    [, $session, $hero, $others] = winsSetup();

    $wins = app(SessionBoard::class)->winsByPlayer($session);

    expect($wins)->toBe([
        $hero->id => 2,
        $others[0]->id => 1,
        $others[1]->id => 1,
        $others[2]->id => 2,
    ]);
});

it('reports 0 for a player with no matches', function (): void {
    [$club, $session] = winsSetup();
    $idle = Player::factory()->for($club)->manual(3)->create();
    SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $idle->id, 'status' => SessionPlayerStatus::Waiting]);

    $row = collect(app(SessionBoard::class)->waiting($session))->firstWhere('id', $idle->id);

    expect($row['wins'])->toBe(0)
        ->and(app(SessionBoard::class)->winsByPlayer($session))->not->toHaveKey($idle->id);
});

it('shows wins in waiting and on-break rows, ignoring a playing match', function (): void {
    [, $session, $hero, $others] = winsSetup();
    $playing = winsMatch($session, $others[0], [$others[1], $others[2], $hero], MatchStatus::Playing, null, null);
    $playing->matchPlayers()->where('player_id', $hero->id)->delete();

    $board = app(SessionBoard::class);
    $map = $board->winsByPlayer($session);
    expect(collect($board->waiting($session, $map))->firstWhere('id', $hero->id)['wins'])->toBe(2);

    SessionPlayer::query()->where('play_session_id', $session->id)->where('player_id', $hero->id)->update(['status' => SessionPlayerStatus::Break]);
    expect(collect($board->onBreak($session, $map))->firstWhere('id', $hero->id)['wins'])->toBe(2);
});

it('uses one grouped query per board read', function (): void {
    [, $session] = winsSetup();
    $board = app(SessionBoard::class);

    DB::enableQueryLog();
    $board->winsByPlayer($session);
    expect(DB::getQueryLog())->toHaveCount(1);
});

it('passes the wins map to the check-in panel view', function (): void {
    [$club, $session, $hero] = winsSetup();
    $owner = $club->users()->first();

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->assertViewHas('wins', fn (array $w): bool => $w[$hero->id] === 2);
});

it('exposes wins in every public snapshot place', function (): void {
    [, $session, $hero, $others] = winsSetup();
    $pid = (string) $hero->public_id;
    $find = fn (array $list): array => collect($list)->firstWhere('id', $pid);

    // hero in a playing match (court) and a staged match (up next, other players)
    $playing = winsMatch($session, $hero, $others, MatchStatus::Playing, null, null);
    $snap = app(PublicSessionView::class)->snapshot($session);
    $team = $snap['courts'][0]['match']['teams']['A'];
    expect($find($team)['wins'])->toBe(2)
        ->and($find($snap['players'])['wins'])->toBe(2);

    $playing->update(['status' => MatchStatus::Staged, 'court_no' => null]);
    $snap = app(PublicSessionView::class)->snapshot($session);
    expect($find($snap['up_next'][0]['teams']['A'])['wins'])->toBe(2);

    $playing->matchPlayers()->delete();
    $playing->delete();
    expect($find(app(PublicSessionView::class)->snapshot($session)['waiting'])['wins'])->toBe(2);
    SessionPlayer::query()->where('play_session_id', $session->id)->where('player_id', $hero->id)->update(['status' => SessionPlayerStatus::Break]);
    expect($find(app(PublicSessionView::class)->snapshot($session)['on_break'])['wins'])->toBe(2);
});

it('renders a side-by-side match board without wins badges on the public queue', function (): void {
    [$club, $session, $hero, $others] = winsSetup();
    winsMatch($session, $hero, $others, MatchStatus::Playing, null, null);

    $html = Livewire::test(Queue::class, ['club' => $club, 'publicId' => $session->public_id])->html();

    expect($html)->toContain('data-test="match-board"')
        ->toContain('data-test="team-a"')
        ->toContain('data-test="team-b"')
        ->toContain('data-test="match-vs"')
        ->toContain($hero->name)
        ->toContain('data-test="court-open"')
        ->not->toContain('2W');
    // Wins appear only in the Players section, never on the match board.
    expect(substr($html, 0, strpos($html, 'data-test="queue-players"')))->not->toContain('player-wins');
    expect(substr_count($html, 'data-test="queue-court"'))->toBeGreaterThan(1);
});

it('does not leak extra player data on the public queue', function (): void {
    [$club, $session, $hero, $others] = winsSetup();
    winsMatch($session, $hero, $others, MatchStatus::Playing, null, null);

    $html = Livewire::test(Queue::class, ['club' => $club, 'publicId' => $session->public_id])->html();

    expect($html)->not->toContain('email')
        ->not->toContain('"stars"')
        ->not->toContain('dupr');
});

it('shows wins on organizer waiting and check-in rows', function (): void {
    [$club, $session, $hero] = winsSetup();
    $owner = $club->users()->first();

    Livewire::actingAs($owner)->test(CheckInPanel::class, ['session' => $session])
        ->assertSeeHtml('data-test="player-wins"')->assertSee('2 wins');
    Livewire::actingAs($owner)->test(WaitingList::class, ['session' => $session])
        ->assertSeeHtml('data-test="player-wins"')->assertSee('2 wins');
});

it('runs the wins query once per public snapshot', function (): void {
    [, $session] = winsSetup();

    DB::enableQueryLog();
    app(PublicSessionView::class)->snapshot($session);
    $count = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'match_players') && str_contains($q['query'], 'sum(case'))->count();

    expect($count)->toBe(1);
});

it('runs no wins query for waiting and on-break reads without a map', function (): void {
    [, $session] = winsSetup();
    $board = app(SessionBoard::class);

    DB::enableQueryLog();
    $rows = $board->waiting($session);
    $board->onBreak($session);
    $board->unplaceableCount($session);
    $count = collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'sum(case'))->count();

    expect($count)->toBe(0)
        ->and(collect($rows)->pluck('wins')->unique()->all())->toBe([0]);
});

it('lists games played and wins on public players and leaves out players who left', function (): void {
    [$club, $session, $hero, $others] = winsSetup();
    SessionPlayer::query()->where('play_session_id', $session->id)->where('player_id', $hero->id)->update(['games_played' => 3]);
    $gone = Player::factory()->for($club)->manual(3)->create();
    SessionPlayer::factory()->create(['play_session_id' => $session->id, 'player_id' => $gone->id, 'status' => SessionPlayerStatus::Left]);

    $players = collect(app(PublicSessionView::class)->snapshot($session)['players']);
    $row = $players->firstWhere('id', (string) $hero->public_id);

    expect($row)->toBe(['id' => (string) $hero->public_id, 'name' => $hero->name, 'games_played' => 3, 'wins' => 2])
        ->and($players->firstWhere('id', (string) $others[2]->public_id)['wins'])->toBe(2)
        ->and($players->firstWhere('id', (string) $others[2]->public_id)['games_played'])->toBe(0)
        ->and($players->firstWhere('id', (string) $gone->public_id))->toBeNull()
        ->and($players)->toHaveCount(4);
});
