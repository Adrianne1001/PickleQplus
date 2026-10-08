<?php

use App\Enums\SessionStatus;
use App\Livewire\Public\Queue;
use App\Livewire\Public\Tv;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Services\CheckInService;
use Livewire\Livewire;

/**
 * A club with a session in the given status. For live sessions, six players
 * are checked in (so one match is staged and one is playing).
 *
 * @return array{0: Club, 1: PlaySession, 2: list<Player>}
 */
function publicSession(SessionStatus $status = SessionStatus::Live, int $courts = 1, int $players = 6): array
{
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->create(['status' => $status, 'courts' => $courts]);
    $list = [];
    if ($status === SessionStatus::Live) {
        for ($i = 1; $i <= $players; $i++) {
            $player = Player::factory()->for($club)->manual(3)->create(['name' => "Player{$i}", 'dupr_id' => 'DQ'.str_repeat((string) $i, 4), 'dupr_rating' => 4.25]);
            app(CheckInService::class)->checkIn($session, $player);
            $list[] = $player;
        }
    }

    return [$club, $session->fresh(), $list];
}

function publicUrl(Club $club, PlaySession $session, string $suffix = ''): string
{
    return '/c/'.$club->slug.'/s/'.$session->public_id.$suffix;
}

function tvUrl(Club $club, PlaySession $session): string
{
    return '/c/'.$club->slug.'/tv/'.$session->tv_id;
}

test('the queue page 404s for a wrong club or an unknown public id', function () {
    [$club, $session] = publicSession();

    $this->get(publicUrl($club, $session))->assertOk();
    $this->get(publicUrl(Club::factory()->create(), $session))->assertNotFound();
    $this->get('/c/'.$club->slug.'/s/nosuchid1234')->assertNotFound();
});

test('the tv uses its own secret link: the old url, a wrong club and a wrong id 404', function () {
    [$club, $session] = publicSession();

    $this->get(tvUrl($club, $session))->assertOk();
    $this->get(publicUrl($club, $session, '/tv'))->assertNotFound();
    $this->get(tvUrl(Club::factory()->create(), $session))->assertNotFound();
    $this->get('/c/'.$club->slug.'/tv/'.$session->public_id)->assertNotFound();
    $this->get('/c/'.$club->slug.'/tv/nosuchtvid')->assertNotFound();
});

test('the queue page never shows or links to the tv url', function () {
    [$club, $session] = publicSession();

    $this->get(publicUrl($club, $session))->assertDontSee($session->tv_id)->assertDontSee('/tv')->assertDontSee($session->checkin_token);
});

test('public pages need no login and show names as entered, never DUPR data or integer ids', function (string $page) {
    [$club, $session, $players] = publicSession();

    $url = $page === 'tv' ? tvUrl($club, $session) : publicUrl($club, $session);
    $response = $this->get($url)->assertOk();
    foreach ($players as $player) {
        $response->assertSee($player->name)->assertDontSee((string) $player->dupr_id)->assertDontSee('4.25');
    }
    expect($response->getContent())->not->toMatch('/data-player-id="\d{1,4}"/');
})->with(['queue', 'tv']);

test('the echo listener key contains the public id', function (string $page) {
    [$club, $session] = publicSession();
    $test = $page === 'tv'
        ? Livewire::test(Tv::class, ['club' => $club, 'tvId' => $session->tv_id])
        : Livewire::test(Queue::class, ['club' => $club, 'publicId' => $session->public_id]);

    expect($test->instance()->getListeners())
        ->toHaveKey('echo:play-session.'.$session->public_id.',.session.updated');
    $test->assertSeeHtml('wire:poll');
})->with(['queue', 'tv']);

test('the queue page shows courts, up next, waiting and the me picker', function () {
    [$club, $session] = publicSession(players: 9);

    Livewire::test(Queue::class, ['club' => $club, 'publicId' => $session->public_id])
        ->assertSee('Court 1')
        ->assertSee('Up next')
        ->assertSee('Waiting')
        ->assertSeeHtml('data-test="this-is-me"')
        ->assertSeeHtml('data-test="not-me"')
        ->assertSeeHtml('queueMe(');
});

test('the queue page sends only ids, never names, in the live state', function () {
    [$club, $session, $players] = publicSession();

    $live = Livewire::test(Queue::class, ['club' => $club, 'publicId' => $session->public_id])->get('live');

    expect($live['status'])->toBe('live')
        ->and($live['players'])->toHaveCount(6)
        ->and(json_encode($live))->not->toContain('Player1');
});

test('draft, live and ended states render', function () {
    [$club, $draft] = publicSession(SessionStatus::Draft);
    $ended = PlaySession::factory()->for($club)->ended()->create();
    [$liveClub, $live] = publicSession();

    $this->get(publicUrl($club, $draft))->assertSee('Not started yet');
    $this->get(tvUrl($club, $draft))->assertSee('Starting soon')->assertSee('Scan to check in')->assertSeeHtml('data-test="tv-qr"');
    $this->get(publicUrl($club, $ended))->assertSee('Session ended');
    $this->get(tvUrl($club, $ended))->assertSee('Session ended')->assertDontSeeHtml('data-test="tv-qr"');
    $this->get(tvUrl($liveClub, $live))->assertSeeHtml('data-test="tv-court"')->assertSee('Scan to check in');
});

test('the tv hides the qr when the session has no token', function () {
    [$club, $session] = publicSession();
    $session->forceFill(['checkin_token' => null])->save();

    $this->get(tvUrl($club, $session))->assertOk()->assertDontSeeHtml('data-test="tv-qr"');
});

test('the tv lists the top 12 waiting players then plus N more', function () {
    [$club, $session] = publicSession(courts: 1, players: 20);

    // 20 players: 4 playing or staged-and-playing, 4 staged, 12 waiting. Make it 14 waiting.
    $this->get(tvUrl($club, $session))->assertOk();

    [$club, $session] = publicSession(courts: 1, players: 22);
    $this->get(tvUrl($club, $session))->assertSeeHtml('data-test="tv-more"')->assertSee('+');
});

test('the tv copes with 50 courts', function () {
    [$club, $session] = publicSession(courts: 50, players: 4);

    $html = $this->get(tvUrl($club, $session))->assertOk()->getContent();

    expect(substr_count($html, 'data-test="tv-court"'))->toBe(50);
});
