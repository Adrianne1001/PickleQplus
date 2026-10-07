<?php

use App\Enums\ClubRole;
use App\Enums\Gender;
use App\Enums\MatchStatus;
use App\Enums\RotationMode;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Notifications\ClubInvitationNotification;
use App\Services\CheckInService;
use App\Services\InvitationService;
use App\Services\RosterImport\RosterImportPreview;
use App\Services\RosterImport\RosterImportRow;
use App\Services\RosterImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full Laravel application and get a fresh database
| per test. Unit tests stay framework-free (important for the pure-PHP
| rotation engine).
|
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/**
 * A verified user who owns a fresh club.
 *
 * @return array{0: User, 1: Club}
 */
function memberOf(): array
{
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();

    return [$user, $club];
}

function inviteSetup(): array
{
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    return [$owner, $club];
}

/** Send an invite through the service and return [invitation, plainToken]. */
function sendInvite(Club $club, User $owner, string $email, ClubRole $role = ClubRole::Staff): array
{
    Notification::fake();
    $invitation = app(InvitationService::class)->invite($club, $owner, $email, $role);

    $token = null;
    Notification::assertSentOnDemand(ClubInvitationNotification::class, function ($n) use (&$token) {
        $token = $n->plainToken;

        return true;
    });

    return [$invitation, (string) $token];
}

function csvFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'roster');
    file_put_contents($path, $contents);

    return $path;
}

function importPreview(Club $club, string $contents): RosterImportPreview
{
    return app(RosterImportService::class)->preview($club, csvFile($contents));
}

/** @return array<int, RosterImportRow> keyed by line */
function rowsByLine(RosterImportPreview $preview): array
{
    $out = [];
    foreach ($preview->rows as $row) {
        $out[$row->line] = $row;
    }

    return $out;
}

/**
 * A live session with $n players checked in through the service (so Up Next staging runs).
 *
 * @param  array<string, mixed>  $attrs
 * @return array{0: PlaySession, 1: list<Player>}
 */
function board(int $n, array $attrs = []): array
{
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->live()->create($attrs);
    $players = [];
    for ($i = 0; $i < $n; $i++) {
        $player = Player::factory()->for($club)->manual(3)->create();
        app(CheckInService::class)->checkIn($session, $player);
        $players[] = $player;
        test()->travel(1)->seconds();
    }

    return [$session->fresh(), $players];
}

function stagedOf(PlaySession $s)
{
    return GameMatch::query()->where('play_session_id', $s->id)->where('status', MatchStatus::Staged->value)->orderBy('id')->get();
}

function matchIds(GameMatch $m): array
{
    return $m->matchPlayers()->pluck('player_id')->map(fn ($i) => (int) $i)->sort()->values()->all();
}

function entryOf(PlaySession $s, Player $p): SessionPlayer
{
    return SessionPlayer::query()->where('play_session_id', $s->id)->where('player_id', $p->id)->firstOrFail();
}

/**
 * A live mixed session. $genders is a string like 'MMWWM' (M man, W woman, N no gender),
 * checked in left to right.
 *
 * @param  array<string, mixed>  $attrs
 * @return array{0: PlaySession, 1: list<Player>}
 */
function mixedBoard(string $genders, array $attrs = []): array
{
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->live()->create(['rotation_mode' => RotationMode::Mixed, ...$attrs]);
    $players = [];
    foreach (str_split($genders) as $g) {
        $player = Player::factory()->for($club)->manual(3)->create(['gender' => match ($g) {
            'M' => Gender::Man,
            'W' => Gender::Woman,
            default => null,
        }]);
        app(CheckInService::class)->checkIn($session, $player);
        $players[] = $player;
        test()->travel(1)->seconds();
    }

    return [$session->fresh(), $players];
}

/** @return list<list<string>> the sorted genders of each team */
function teamGenders(GameMatch $match): array
{
    return $match->matchPlayers()->with('player')->get()
        ->groupBy(fn ($mp) => $mp->team->value)
        ->map(fn ($rows) => $rows->map(fn ($mp) => $mp->player->gender->value)->sort()->values()->all())
        ->values()->all();
}

function genderedPlayerOf(GameMatch $match, string $gender): Player
{
    return Player::findOrFail($match->matchPlayers()->whereHas('player', fn ($q) => $q->where('gender', $gender))->first()->player_id);
}
