<?php

use App\Enums\SessionStatus;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\CheckInQrService;
use App\Services\ClubService;
use App\Services\PlaySessionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

test('token is issued on create with 40 chars', function () {
    $session = app(PlaySessionService::class)->create(Club::factory()->create());

    expect($session->checkin_token)->toBeString()->toHaveLength(40);
});

test('ending the session clears the token', function () {
    $session = PlaySession::factory()->live()->create();

    app(PlaySessionService::class)->end($session);

    expect($session->fresh()->checkin_token)->toBeNull();
});

test('regenerate replaces the token and fires the change event', function () {
    $club = Club::factory()->create();
    $user = User::factory()->create();
    app(ClubService::class)->create($user, ['name' => 'X']);
    $club = $user->clubs()->firstOrFail();
    $session = PlaySession::factory()->live()->for($club)->create();
    $old = $session->checkin_token;
    Event::fake([PlaySessionChanged::class]);

    app(PlaySessionService::class)->regenerateCheckinToken($session, $user);

    expect($session->fresh()->checkin_token)->toHaveLength(40)->not->toBe($old)
        ->and(PlaySession::findByCheckinToken((string) $old))->toBeNull()
        ->and(PlaySession::findByCheckinToken((string) $session->fresh()->checkin_token)?->is($session))->toBeTrue();
    Event::assertDispatched(PlaySessionChanged::class);
});

test('regenerate is denied to non-members and blocked on ended sessions', function () {
    $session = PlaySession::factory()->live()->create();
    app(PlaySessionService::class)->regenerateCheckinToken($session, User::factory()->create());
})->throws(AuthorizationException::class);

test('regenerate on an ended session is rejected', function () {
    $user = User::factory()->create();
    app(ClubService::class)->create($user, ['name' => 'X']);
    $session = PlaySession::factory()->ended()->for($user->clubs()->firstOrFail())->create();

    app(PlaySessionService::class)->regenerateCheckinToken($session, $user);
})->throws(ValidationException::class);

test('lookup only returns draft or live sessions for known tokens', function () {
    $draft = PlaySession::factory()->create();
    $live = PlaySession::factory()->live()->create();
    $ended = PlaySession::factory()->ended()->create(['checkin_token' => 'stale-token']);

    expect(PlaySession::findByCheckinToken((string) $draft->checkin_token)?->is($draft))->toBeTrue()
        ->and(PlaySession::findByCheckinToken((string) $live->checkin_token)?->is($live))->toBeTrue()
        ->and($ended->status)->toBe(SessionStatus::Ended)
        ->and(PlaySession::findByCheckinToken('stale-token'))->toBeNull()
        ->and(PlaySession::findByCheckinToken('unknown'))->toBeNull()
        ->and(PlaySession::findByCheckinToken(''))->toBeNull();
});

test('qr service renders svg for the check-in url', function () {
    $session = PlaySession::factory()->live()->create();
    $qr = app(CheckInQrService::class);

    expect($qr->url($session))->toBe(url('/checkin/'.$session->checkin_token))
        ->and($qr->svg($session))->toContain('<svg');

    $session->checkin_token = null;
    expect($qr->svg($session))->toBeNull();
});
