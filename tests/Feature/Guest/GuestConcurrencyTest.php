<?php

use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| P14.10 MySQL lock tests (real parallelism)
|--------------------------------------------------------------------------
| Guest limits and the claim/purge hand-off rely on row locks that SQLite cannot show (it has
| no row locks, and an in-memory database cannot be shared). So, like DatabaseDriverTest, these
| run only when the suite runs on MySQL (REQUIRE_MYSQL=1, see `composer test:mysql`) and are
| skipped otherwise.
|
| The suite has no earlier MySQL concurrency tests, and RefreshDatabase wraps each test in one
| uncommitted transaction that another connection cannot see. So each test commits its fixtures,
| starts several real PHP processes (tests/Support/guest-race-worker.php, each with its own MySQL
| connection), releases them together on a GO line, then checks the outcome from the database and
| deletes its fixtures in a finally block. Windows has no pcntl, and a child process needs none.
*/

/** @return array<string, mixed> */
function raceEnvironment(): array
{
    $db = config('database.connections.'.config('database.default'));

    return array_merge(getenv(), [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => (string) $db['host'],
        'DB_PORT' => (string) $db['port'],
        'DB_DATABASE' => (string) $db['database'],
        'DB_USERNAME' => (string) $db['username'],
        'DB_PASSWORD' => (string) $db['password'],
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'BROADCAST_CONNECTION' => 'null',
        'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array',
        'MAIL_MAILER' => 'array',
        'LOG_CHANNEL' => 'null',
        'PULSE_ENABLED' => 'false',
        'TELESCOPE_ENABLED' => 'false',
        'NIGHTWATCH_ENABLED' => 'false',
    ]);
}

/** Make the fixtures visible to other connections: end the RefreshDatabase transaction. */
function commitFixtures(): void
{
    expect(DB::connection()->getDriverName())->toBe('mysql');
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }
}

/** @param list<int> $clubIds @param list<int> $userIds */
function deleteFixtures(array $clubIds, array $userIds = []): void
{
    // Everything under a club cascades; leftovers here would break the other tests' counts.
    Club::query()->whereIn('id', $clubIds)->delete();
    User::query()->whereIn('id', $userIds)->delete();
}

/**
 * Start one process per payload, wait until every one is booted (READY), run $beforeGo (it may
 * return the boundary timestamp the workers should aim at), release them all, and collect results.
 *
 * @param  list<array<string, mixed>>  $payloads
 * @param  (callable(): float)|null  $beforeGo
 * @return list<array{ok: int, rejected: int, errors: list<string>, items: array<int|string, string>}>
 */
function runRaceWorkers(array $payloads, ?callable $beforeGo = null): array
{
    $worker = base_path('tests/Support/guest-race-worker.php');
    $database = (string) config('database.connections.'.config('database.default').'.database');
    $procs = [];

    foreach ($payloads as $i => $payload) {
        $errFile = tempnam(sys_get_temp_dir(), 'race-err');
        $arg = base64_encode((string) json_encode($payload + ['database' => $database]));
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', $worker, $arg],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']],
            $pipes,
            base_path(),
            raceEnvironment(),
        );
        expect($process)->not->toBeFalse();
        $procs[$i] = ['process' => $process, 'pipes' => $pipes, 'err' => $errFile];
    }

    $fail = function (string $why) use (&$procs): never {
        foreach ($procs as $p) {
            @proc_terminate($p['process']);
        }
        $stderr = implode("\n", array_map(fn ($p) => (string) @file_get_contents($p['err']), $procs));
        foreach ($procs as $p) {
            @unlink($p['err']);
        }

        throw new RuntimeException($why.($stderr !== '' ? "\n".$stderr : ''));
    };

    foreach ($procs as $i => $p) {
        $line = fgets($p['pipes'][1]);
        if ($line === false || trim($line) !== 'READY') {
            $fail("Race worker {$i} did not become ready (got ".var_export($line, true).').');
        }
    }

    $boundary = $beforeGo === null ? 0.0 : $beforeGo();
    foreach ($procs as $p) {
        fwrite($p['pipes'][0], json_encode(['boundary' => $boundary])."\n");
        fflush($p['pipes'][0]);
    }

    $results = [];
    foreach ($procs as $i => $p) {
        $out = (string) stream_get_contents($p['pipes'][1]);
        foreach ($p['pipes'] as $pipe) {
            fclose($pipe);
        }
        $code = proc_close($p['process']);
        $stderr = (string) @file_get_contents($p['err']);
        @unlink($p['err']);

        if (! preg_match('/^RESULT (.+)$/m', $out, $m)) {
            throw new RuntimeException("Race worker {$i} exited {$code} with no result.\n{$out}\n{$stderr}");
        }
        $results[] = json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    return $results;
}

// Same switch as DatabaseDriverTest: only the MySQL run sets REQUIRE_MYSQL, and that test proves it is really MySQL.
$mysqlOnly = fn (): bool => getenv('REQUIRE_MYSQL') !== '1';

test('concurrent check-ins into a guest session stop at exactly 40', function () {
    $club = Club::factory()->guest()->create();
    $session = PlaySession::factory()->for($club)->live()->create(['courts' => 6]);
    $players = Player::factory()->for($club)->manual(3)->count(48)->create();
    commitFixtures();

    try {
        // 8 processes x 6 players = 48 attempts racing the 40 cap.
        $payloads = $players->pluck('id')->chunk(6)
            ->map(fn ($ids) => ['task' => 'checkin', 'session_id' => $session->id, 'player_ids' => $ids->values()->all()])
            ->values()->all();
        $results = runRaceWorkers($payloads);

        $errors = array_merge(...array_column($results, 'errors'));
        $stored = SessionPlayer::query()->where('play_session_id', $session->id)->where('status', '!=', 'left');

        expect($errors)->toBe([])
            ->and(array_sum(array_column($results, 'ok')))->toBe(40)
            ->and(array_sum(array_column($results, 'rejected')))->toBe(8)
            ->and($stored->count())->toBe(40)
            ->and(SessionPlayer::query()->where('play_session_id', $session->id)->distinct()->count('player_id'))->toBe(40);
    } finally {
        deleteFixtures([$club->id]);
    }
})->skip($mysqlOnly, 'Needs MySQL (REQUIRE_MYSQL=1, composer test:mysql).');

test('concurrent session creation in a guest club lets exactly one through', function () {
    $club = Club::factory()->guest()->create();
    commitFixtures();

    try {
        $results = runRaceWorkers(array_fill(0, 8, ['task' => 'create-session', 'club_id' => $club->id]));

        $errors = array_merge(...array_column($results, 'errors'));
        expect($errors)->toBe([])
            ->and(array_sum(array_column($results, 'ok')))->toBe(1)
            ->and(array_sum(array_column($results, 'rejected')))->toBe(7)
            ->and(PlaySession::query()->where('club_id', $club->id)->count())->toBe(1);
    } finally {
        deleteFixtures([$club->id]);
    }
})->skip($mysqlOnly, 'Needs MySQL (REQUIRE_MYSQL=1, composer test:mysql).');

test('claim versus purge on clubs expiring at the same instant: one wins cleanly, nothing is orphaned, no deadlock', function () {
    $count = 12;
    $clubs = [];
    $users = [];
    $tokens = [];
    for ($i = 0; $i < $count; $i++) {
        $tokens[$i] = Str::random(40);
        $clubs[$i] = Club::factory()->guest($tokens[$i])->create();
        $users[$i] = User::factory()->create();
        PlaySession::factory()->for($clubs[$i])->create();
        Player::factory()->for($clubs[$i])->manual(3)->count(2)->create();
    }
    commitFixtures();
    $clubIds = array_map(fn (Club $c) => $c->id, $clubs);
    $userIds = array_map(fn (User $u) => $u->id, $users);

    try {
        // 3 claim processes (4 clubs each) and 2 purge processes that poll every club. Every club
        // expires at the same whole second ("the boundary"). The purge processes poll from 0.2 s
        // before it (a poll before expiry takes and releases the club row lock, so claims contend
        // with it), and each claim process starts claiming 60 ms before it and keeps going. A claim
        // is about 5-30 ms, so the early ones win and the later ones straddle the boundary: the
        // outcome mix varies run to run (checked over several runs: some claims win, some purges
        // win), and the assertions hold for any mix.
        $payloads = [];
        for ($worker = 0; $worker < 3; $worker++) {
            $items = [];
            for ($j = 0; $j < 4; $j++) {
                $i = $worker * 4 + $j;
                $items[] = ['club_id' => $clubs[$i]->id, 'user_id' => $users[$i]->id, 'token' => $tokens[$i], 'offset' => -0.06];
            }
            $payloads[] = ['task' => 'claim', 'items' => $items];
        }
        for ($p = 0; $p < 2; $p++) {
            $payloads[] = ['task' => 'purge', 'club_ids' => $clubIds, 'from' => -0.2, 'until' => 2.0];
        }

        $results = runRaceWorkers($payloads, function () use ($clubIds): float {
            $boundary = ceil(microtime(true) + 1.0);
            Club::query()->whereIn('id', $clubIds)->update(['guest_expires_at' => Carbon::createFromTimestamp((int) $boundary, config('app.timezone'))]);

            return $boundary;
        });

        $claimResults = array_slice($results, 0, 3);
        $purgeResults = array_slice($results, 3);
        $errors = array_merge(...array_column($results, 'errors'));
        // Any deadlock, lock wait timeout or other database error would show up here.
        expect($errors)->toBe([]);

        $claimed = 0;
        $purged = 0;
        foreach ($clubs as $i => $club) {
            $id = $club->id;
            $claimItem = null;
            foreach ($claimResults as $r) {
                $claimItem ??= $r['items'][$id] ?? null;
            }
            $purgeWins = 0;
            foreach ($purgeResults as $r) {
                $purgeWins += ($r['items'][$id] ?? null) === 'purged' ? 1 : 0;
            }

            $row = Club::query()->whereKey($id)->first();
            if ($row !== null) {
                // The claim won: a normal club with exactly this owner, its data intact, never purged.
                $claimed++;
                expect($claimItem)->toBe('claimed')
                    ->and($purgeWins)->toBe(0)
                    ->and($row->is_guest)->toBeFalse()
                    ->and($row->guest_token_hash)->toBeNull()
                    ->and($row->guest_expires_at)->toBeNull()
                    ->and($row->guest_claimed_at)->not->toBeNull()
                    ->and(DB::table('club_user')->where('club_id', $id)->pluck('user_id')->all())->toBe([$users[$i]->id])
                    ->and(PlaySession::query()->where('club_id', $id)->count())->toBe(1)
                    ->and(Player::query()->where('club_id', $id)->count())->toBe(2);
            } else {
                // The purge won, exactly once: no orphan rows, and the claim was refused (not crashed).
                $purged++;
                expect($purgeWins)->toBe(1)
                    ->and($claimItem)->toStartWith('rejected')
                    ->and(PlaySession::query()->where('club_id', $id)->count())->toBe(0)
                    ->and(Player::query()->where('club_id', $id)->count())->toBe(0)
                    ->and(DB::table('club_user')->where('club_id', $id)->count())->toBe(0);
            }
        }

        // Every club ended in exactly one of the two states, and no player or entry is left behind.
        expect($claimed + $purged)->toBe($count)
            ->and(Player::query()->whereNotIn('club_id', Club::query()->select('id'))->count())->toBe(0)
            ->and(PlaySession::query()->whereNotIn('club_id', Club::query()->select('id'))->count())->toBe(0);
    } finally {
        deleteFixtures($clubIds, $userIds);
    }
})->skip($mysqlOnly, 'Needs MySQL (REQUIRE_MYSQL=1, composer test:mysql).');
