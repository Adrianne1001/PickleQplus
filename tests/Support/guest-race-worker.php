<?php

/*
|--------------------------------------------------------------------------
| Child process for the MySQL lock tests (tests/Feature/Guest/GuestConcurrencyTest.php)
|--------------------------------------------------------------------------
| Boots the app on its own database connection, prints READY, waits for a GO line on stdin
| (so every worker starts together), runs one task from the JSON payload in argv[1] and prints
| one `RESULT {json}` line. Never run by hand, and never against a database you care about:
| it refuses to start unless the connection is MySQL and the database matches the payload.
*/

use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\GuestClubService;
use App\Services\GuestPurgeService;
use App\Services\PlaySessionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$payload = json_decode(base64_decode((string) ($argv[1] ?? ''), true) ?: '', true, 512, JSON_THROW_ON_ERROR);

if (DB::connection()->getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== $payload['database']) {
    fwrite(STDERR, 'Refusing to run: connected to '.DB::connection()->getDriverName().'/'.DB::connection()->getDatabaseName().", expected mysql/{$payload['database']}\n");
    exit(2);
}

// The purge deletes podium and export files by id. Point the local disk at a throwaway folder, so a
// session id in this test database can never match (and delete) a real file of the dev database.
config(['filesystems.disks.local.root' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'pickleq-race-'.getmypid()]);

// Warm the connection and the container, so the timed part below is only the contended work.
DB::select('select 1');

echo "READY\n";
fflush(STDOUT);

$go = json_decode((string) fgets(STDIN), true);
$boundary = (float) ($go['boundary'] ?? 0.0);

/** Sleep (in short slices) until the given epoch second. */
$waitUntil = function (float $at): void {
    while (($left = $at - microtime(true)) > 0) {
        usleep((int) min($left * 1_000_000, 500));
    }
};

$result = ['ok' => 0, 'rejected' => 0, 'errors' => [], 'items' => []];

$record = function (Throwable $e) use (&$result): string {
    if ($e instanceof ValidationException) {
        $result['rejected']++;

        return 'rejected: '.implode(' ', array_map(fn ($m) => implode(' ', (array) $m), $e->errors()));
    }
    $result['errors'][] = get_class($e).': '.mb_substr($e->getMessage(), 0, 300);

    return 'error: '.get_class($e);
};

switch ($payload['task']) {
    case 'checkin':
        $session = PlaySession::query()->findOrFail($payload['session_id']);
        foreach ($payload['player_ids'] as $playerId) {
            try {
                app(CheckInService::class)->checkIn($session->fresh(), Player::query()->findOrFail($playerId));
                $result['ok']++;
            } catch (Throwable $e) {
                $record($e);
            }
        }
        break;

    case 'create-session':
        $club = Club::query()->findOrFail($payload['club_id']);
        try {
            app(PlaySessionService::class)->create($club, []);
            $result['ok']++;
        } catch (Throwable $e) {
            $record($e);
        }
        break;

    case 'claim':
        // Each item waits for its own moment around the expiry boundary, then claims.
        foreach ($payload['items'] as $item) {
            $waitUntil($boundary + (float) $item['offset']);
            try {
                $club = Club::query()->find($item['club_id']);
                if ($club === null) {
                    // Purged before this claim started: over HTTP the route binding answers 404.
                    $result['rejected']++;
                    $result['items'][$item['club_id']] = 'rejected: club already purged';

                    continue;
                }
                app(GuestClubService::class)->claim($club, User::query()->findOrFail($item['user_id']), $item['token']);
                $result['ok']++;
                $result['items'][$item['club_id']] = 'claimed';
            } catch (Throwable $e) {
                $result['items'][$item['club_id']] = $record($e);
            }
        }
        break;

    case 'purge':
        // Poll the purge for every club from just before the boundary until the window closes.
        $waitUntil($boundary + (float) $payload['from']);
        $pending = $payload['club_ids'];
        while ($pending !== [] && microtime(true) < $boundary + (float) $payload['until']) {
            foreach ($pending as $i => $clubId) {
                try {
                    if (app(GuestPurgeService::class)->purge((int) $clubId)) {
                        $result['ok']++;
                        $result['items'][$clubId] = 'purged';
                        unset($pending[$i]);
                    }
                } catch (Throwable $e) {
                    $record($e);
                    unset($pending[$i]);
                }
            }
            usleep(1000);
        }
        break;
}

echo 'RESULT '.json_encode($result)."\n";
