<?php

namespace Database\Seeders;

use App\Enums\ClubRole;
use App\Enums\MatchStatus;
use App\Enums\SessionPlayerStatus;
use App\Enums\SessionStatus;
use App\Enums\Team;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Models\User;
use App\Services\CheckInService;
use App\Services\ClubService;
use App\Services\MatchService;
use App\Services\PlaySessionService;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demo data for local development: one club owned by test@example.com with a
 * roster, two past sessions and one live session. Safe to run repeatedly.
 */
class DemoSeeder extends Seeder
{
    public const CLUB_NAME = 'Demo Pickleball Club';

    public const OWNER_EMAIL = 'test@example.com';

    public const STAFF_EMAIL = 'staff@example.com';

    /**
     * Name, DUPR rating (null = unrated), manual stars for unrated players.
     *
     * @var list<array{0: string, 1: float|null, 2?: int}>
     */
    private const PLAYERS = [
        ['Maria Santos', 4.62], ['James Carter', 4.21], ['Priya Patel', 3.95], ['Liam O\'Connor', 3.74],
        ['Sofia Ramirez', 3.52], ['Daniel Kim', 3.31], ['Aisha Khan', 3.18], ['Tomas Novak', 3.05],
        ['Grace Lee', 2.88], ['Marcus Johnson', 2.71], ['Elena Petrova', 2.56], ['Noah Williams', 5.34],
        ['Hannah Müller', 4.88], ['Carlos Mendoza', 4.05], ['Yuki Tanaka', 3.62], ['Olivia Brown', 3.40],
        ['Ethan Davis', null, 3], ['Isabella Rossi', null, 2], ['Raj Sharma', null, 4], ['Chloe Martin', null, 2],
        ['Victor Alvarez', null, 3], ['Mei Chen', null, 1], ['Samuel Adeyemi', 3.22], ['Fiona Gallagher', null, 5],
    ];

    /** Roster indexes of inactive players. */
    private const INACTIVE = [21, 7];

    /** Roster indexes of players who self-registered via QR. */
    private const SELF_REGISTERED = [18, 19];

    public function run(): void
    {
        $owner = User::query()->firstOrCreate(
            ['email' => self::OWNER_EMAIL],
            ['name' => 'Test User', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );

        $exists = Club::query()
            ->where('name', self::CLUB_NAME)
            ->whereHas('users', fn ($q) => $q->where('users.id', $owner->id)->where('club_user.role', ClubRole::Owner->value))
            ->exists();
        if ($exists) {
            $this->command->info('Demo club already exists, skipping.');

            return;
        }

        mt_srand(2024);

        $club = app(ClubService::class)->create($owner, [
            'name' => self::CLUB_NAME,
            'dupr_club_id' => '4829105736',
            'default_courts' => 4,
        ]);

        $staff = User::query()->firstOrCreate(
            ['email' => self::STAFF_EMAIL],
            ['name' => 'Staff Member', 'password' => Hash::make('password'), 'email_verified_at' => now()],
        );
        $club->users()->syncWithoutDetaching([$staff->id => ['role' => ClubRole::Staff->value]]);
        User::flushRoleCache();

        $players = $this->players($club);
        $active = $players->filter(fn (Player $p): bool => $p->active)->values();

        $this->pastSession($club, 'Saturday Open Play', now()->subDays(17), $active->slice(0, 16)->values()->all(), 22, false);
        $this->pastSession($club, 'Tuesday Night Open Play', now()->subDays(6), $active->slice(4, 16)->values()->all(), 18, true);
        $this->liveSession($club, $active->take(13)->all());

        $this->command->info("Seeded {$club->name} (slug: {$club->slug}).");
    }

    /**
     * @return Collection<int, Player>
     */
    private function players(Club $club): Collection
    {
        $used = [];
        $players = collect();

        foreach (self::PLAYERS as $i => $row) {
            $rating = $row[1];
            $factory = Player::factory()->for($club);

            if ($rating === null) {
                $factory = $factory->manual($row[2] ?? 3);
            } elseif ($i % 6 === 5) {
                // Rated but no DUPR ID on file: stars come from the bands, source stays manual.
                $factory = $factory->rated($rating)->state(['dupr_id' => null, 'rating_source' => 'manual']);
            } else {
                do {
                    $id = Str::upper(Str::random(6));
                } while (in_array($id, $used, true));
                $used[] = $id;
                $factory = $factory->rated($rating, $id);
            }

            $state = ['name' => $row[0]];
            if (in_array($i, self::SELF_REGISTERED, true)) {
                $state['self_registered_at'] = now()->subDays(20);
            }
            if (in_array($i, self::INACTIVE, true)) {
                $state['active'] = false;
            }

            $players->push($factory->create($state));
        }

        return $players;
    }

    /**
     * An ended session with completed matches, built directly so dates are in the past.
     *
     * @param  array<int, Player>  $roster
     */
    private function pastSession(Club $club, string $name, CarbonInterface $date, array $roster, int $matchCount, bool $withVoid): void
    {
        $start = $date->copy()->setTime(18, 0);

        $session = PlaySession::factory()->for($club)->create([
            'name' => $name,
            'date' => $date->toDateString(),
            'courts' => 4,
            'status' => SessionStatus::Ended,
            'started_at' => $start,
            'ended_at' => $start->copy()->addHours(3),
        ]);

        $played = [];
        $lastAt = [];
        foreach ($roster as $player) {
            $played[$player->id] = 0;
        }

        for ($n = 0; $n < $matchCount; $n++) {
            // The players with the fewest games go first, like the balanced rotation.
            $pool = $roster;
            usort($pool, fn (Player $a, Player $b): int => [$played[$a->id], mt_rand()] <=> [$played[$b->id], mt_rand()]);
            $four = array_slice($pool, 0, 4);
            shuffle($four);

            $matchStart = $start->copy()->addMinutes(intdiv($n, 4) * 14);
            $void = $withVoid && $n === 5;
            $loserPoints = mt_rand(3, 9);
            $scores = mt_rand(0, 1) === 0 ? [11, $loserPoints] : [$loserPoints, 11];

            $match = GameMatch::factory()->create([
                'play_session_id' => $session->id,
                'court_no' => ($n % 4) + 1,
                'status' => $void ? MatchStatus::Void : MatchStatus::Done,
                'team_a_score' => $void ? null : $scores[0],
                'team_b_score' => $void ? null : $scores[1],
                'started_at' => $void ? null : $matchStart,
                'finished_at' => $void ? null : $matchStart->copy()->addMinutes(12),
                'dupr_eligible' => ! $void && collect($four)->every(fn (Player $p): bool => $p->dupr_id !== null),
            ]);

            foreach ($four as $slot => $player) {
                MatchPlayer::query()->create([
                    'match_id' => $match->id,
                    'player_id' => $player->id,
                    'team' => $slot < 2 ? Team::A : Team::B,
                    'slot' => ($slot % 2) + 1,
                ]);
                if (! $void) {
                    $played[$player->id]++;
                    $lastAt[$player->id] = $matchStart->copy()->addMinutes(12);
                }
            }
        }

        foreach ($roster as $player) {
            SessionPlayer::factory()->create([
                'play_session_id' => $session->id,
                'player_id' => $player->id,
                'status' => SessionPlayerStatus::Left,
                'checked_in_at' => $start->copy()->subMinutes(mt_rand(0, 20)),
                'games_played' => $played[$player->id],
                'queued_at' => $lastAt[$player->id] ?? $start,
                'last_finished_at' => $lastAt[$player->id] ?? null,
            ]);
        }
    }

    /**
     * Today's live session, driven through the real services.
     *
     * @param  array<int, Player>  $roster
     */
    private function liveSession(Club $club, array $roster): void
    {
        $sessions = app(PlaySessionService::class);
        $checkIn = app(CheckInService::class);
        $matches = app(MatchService::class);

        $session = $sessions->create($club, ['name' => 'Open Play', 'date' => now()->toDateString(), 'courts' => 4]);
        foreach ($roster as $player) {
            $checkIn->checkIn($session, $player);
        }
        $sessions->start($session);

        $staged = fn (): ?GameMatch => GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Staged->value)
            ->oldest('id')
            ->first();

        // Three matches on court; one is then finished, so one court is free and one game is scored.
        foreach ([1, 2, 3] as $court) {
            $match = $staged();
            if ($match === null) {
                break;
            }
            $matches->startMatch($session, $match, $court);
        }

        $first = GameMatch::query()
            ->where('play_session_id', $session->id)
            ->where('status', MatchStatus::Playing->value)
            ->oldest('id')
            ->first();
        if ($first !== null) {
            $matches->finish($session, $first, 11, 7);
        }
    }
}
