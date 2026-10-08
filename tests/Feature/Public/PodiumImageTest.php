<?php

use App\Enums\MatchStatus;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Services\Share\PodiumData;
use App\Services\Share\PodiumImageRenderer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

/** A renderer that counts how often it draws. */
class CountingPodiumRenderer extends PodiumImageRenderer
{
    public int $draws = 0;

    public function gif(PodiumData $data, int $size = self::GIF_SIZE): string
    {
        $this->draws++;

        return parent::gif($data, $size);
    }

    public function png(PodiumData $data, int $size = self::PNG_SIZE): string
    {
        $this->draws++;

        return parent::png($data, $size);
    }
}

/** @return array{0: Club, 1: PlaySession, 2: list<Player>} an ended session with one counted match */
function podiumSession(int $scoreA = 11, int $scoreB = 5): array
{
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->ended()->create(['date' => '2026-10-09']);
    $players = [];
    foreach (['Jane Doe', 'Bob Smith', 'Carl Jones', 'Dina Lee'] as $name) {
        $players[] = Player::factory()->for($club)->create(['name' => $name]);
    }
    podiumMatch($session, $players, $scoreA, $scoreB);

    return [$club, $session, $players];
}

/** @param list<Player> $p */
function podiumMatch(PlaySession $session, array $p, int $sa, int $sb, MatchStatus $status = MatchStatus::Done): GameMatch
{
    $m = GameMatch::factory()->create([
        'play_session_id' => $session->id, 'status' => $status,
        'team_a_score' => $sa, 'team_b_score' => $sb, 'court_no' => 1,
        'started_at' => now()->subMinutes(15), 'finished_at' => now(),
    ]);
    foreach ([[$p[0], 'A', 1], [$p[1], 'A', 2], [$p[2], 'B', 1], [$p[3], 'B', 2]] as [$pl, $team, $slot]) {
        MatchPlayer::create(['match_id' => $m->id, 'player_id' => $pl->id, 'team' => $team, 'slot' => $slot]);
    }

    return $m;
}

function podiumUrl(Club $club, PlaySession $session, string $type = 'gif', string $query = ''): string
{
    return route("public.session.podium-{$type}", [$club, $session->public_id]).$query;
}

beforeEach(function () {
    Cache::flush();
    RateLimiter::clear('podium-render:127.0.0.1');
    Storage::fake('local');
});

test('an ended session serves a GIF and a PNG with the right headers', function () {
    [$club, $session] = podiumSession();

    $gif = $this->get(podiumUrl($club, $session, 'gif'))->assertOk()
        ->assertHeader('Content-Type', 'image/gif')
        ->assertHeader('Content-Disposition', 'inline; filename="'.$club->slug.'-2026-10-09-podium.gif"');
    expect($gif->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300')
        ->and($gif->headers->get('ETag'))->not->toBeEmpty()
        ->and(substr((string) $gif->getContent(), 0, 6))->toBe('GIF89a');

    $png = $this->get(podiumUrl($club, $session, 'png'))->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Content-Disposition', 'inline; filename="'.$club->slug.'-2026-10-09-podium.png"');
    expect(getimagesizefromstring((string) $png->getContent())[0])->toBe(1080);
});

test('download=1 sends an attachment', function () {
    [$club, $session] = podiumSession();

    $this->get(podiumUrl($club, $session, 'png', '?download=1'))->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="'.$club->slug.'-2026-10-09-podium.png"');
});

test('draft and live sessions are 404', function () {
    $club = Club::factory()->create();
    foreach ([PlaySession::factory()->for($club)->create(), PlaySession::factory()->for($club)->live()->create()] as $session) {
        $players = Player::factory()->for($club)->count(4)->create()->all();
        podiumMatch($session, $players, 11, 3);
        $this->get(podiumUrl($club, $session))->assertNotFound();
        $this->get(podiumUrl($club, $session, 'png'))->assertNotFound();
    }
});

test('an ended session with no counted matches is 404', function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->ended()->create();
    $this->get(podiumUrl($club, $session))->assertNotFound();

    $players = Player::factory()->for($club)->count(4)->create()->all();
    podiumMatch($session, $players, 11, 3, MatchStatus::Void);
    $this->get(podiumUrl($club, $session))->assertNotFound();
});

test('a wrong club slug and an unknown public id are 404', function () {
    [$club, $session] = podiumSession();
    $other = Club::factory()->create();

    $this->get(route('public.session.podium-gif', [$other, $session->public_id]))->assertNotFound();
    $this->get(route('public.session.podium-gif', [$club, 'zzzzzzzz']))->assertNotFound();
});

test('a matching ETag gets a 304 without a body', function () {
    [$club, $session] = podiumSession();
    $etag = (string) $this->get(podiumUrl($club, $session))->headers->get('ETag');

    $this->withHeaders(['If-None-Match' => $etag])->get(podiumUrl($club, $session))
        ->assertStatus(304)->assertHeader('ETag', $etag);
    $this->withHeaders(['If-None-Match' => 'W/'.$etag])->get(podiumUrl($club, $session))->assertStatus(304);
    $this->withHeaders(['If-None-Match' => '"other"'])->get(podiumUrl($club, $session))->assertOk();
});

test('the image is drawn once and then served from the cache', function () {
    $renderer = new CountingPodiumRenderer;
    app()->instance(PodiumImageRenderer::class, $renderer);
    [$club, $session] = podiumSession();

    $first = $this->get(podiumUrl($club, $session))->assertOk()->getContent();
    $second = $this->get(podiumUrl($club, $session))->assertOk()->getContent();

    expect($renderer->draws)->toBe(1)->and($second)->toBe($first);

    $this->get(podiumUrl($club, $session, 'png'))->assertOk();
    expect($renderer->draws)->toBe(2);
});

test('a changed score gives a new ETag and a fresh image', function () {
    $renderer = new CountingPodiumRenderer;
    app()->instance(PodiumImageRenderer::class, $renderer);
    [$club, $session, $players] = podiumSession();
    $old = $this->get(podiumUrl($club, $session, 'png'))->headers->get('ETag');

    podiumMatch($session, array_reverse($players), 11, 2);
    Cache::flush(); // the public results are cached for 60 s
    $new = $this->get(podiumUrl($club, $session, 'png'))->headers->get('ETag');

    expect($new)->not->toBe($old)->and($renderer->draws)->toBe(2);
});

test('responses are stateless: no cookies on 200 or 304', function () {
    [$club, $session] = podiumSession();

    $ok = $this->get(podiumUrl($club, $session))->assertOk();
    expect($ok->headers->getCookies())->toBe([])->and($ok->headers->has('Set-Cookie'))->toBeFalse();

    $etag = (string) $ok->headers->get('ETag');
    $notModified = $this->withHeaders(['If-None-Match' => $etag])->get(podiumUrl($club, $session))->assertStatus(304);
    expect($notModified->headers->has('Set-Cookie'))->toBeFalse();
});

test('drawing is limited to 30 a minute per IP, but cache hits and 304s are not', function () {
    [$club, $session] = podiumSession();
    $etag = (string) $this->get(podiumUrl($club, $session))->headers->get('ETag');
    [$club2, $session2] = podiumSession();

    for ($i = 0; $i < 30; $i++) {
        RateLimiter::hit('podium-render:127.0.0.1', 60);
    }

    // Cached image and a 304 still work; an image that needs drawing is refused.
    $this->get(podiumUrl($club, $session))->assertOk();
    $this->withHeaders(['If-None-Match' => $etag])->get(podiumUrl($club, $session))->assertStatus(304);
    $this->get(podiumUrl($club2, $session2))->assertStatus(429);
    $this->get(podiumUrl($club2, $session2, 'png'))->assertStatus(429);
});

test('cache hits do not use up the render allowance', function () {
    [$club, $session] = podiumSession();
    $this->get(podiumUrl($club, $session))->assertOk();

    for ($i = 0; $i < 40; $i++) {
        $this->get(podiumUrl($club, $session))->assertOk();
    }
    expect(RateLimiter::attempts('podium-render:127.0.0.1'))->toBe(1);
});

test('the podium comes from the cached public results (no standings query per request)', function () {
    [$club, $session] = podiumSession();
    $this->get(podiumUrl($club, $session, 'png'))->assertOk();

    DB::enableQueryLog();
    $this->get(podiumUrl($club, $session, 'png'))->assertOk();
    $matchQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'match_players'))->count();

    expect($matchQueries)->toBe(0);
});
