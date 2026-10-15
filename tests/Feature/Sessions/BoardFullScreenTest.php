<?php

use App\Enums\SessionStatus;
use App\Livewire\Public\Tv;
use App\Livewire\Sessions\Show;
use App\Models\Club;
use App\Models\PlaySession;
use App\Models\User;
use Livewire\Livewire;

/**
 * @return array{0: User, 1: Club, 2: PlaySession}
 */
function fullScreenBoard(SessionStatus $status): array
{
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();
    $session = PlaySession::factory()->for($club)->live()->create();
    if ($status !== SessionStatus::Live) {
        $session->forceFill(['status' => $status])->save();
    }

    return [$user, $club, $session];
}

test('members see the full screen button and the exit control on draft and live boards', function (SessionStatus $status) {
    [$user, $club, $session] = fullScreenBoard($status);

    Livewire::actingAs($user)->test(Show::class, ['club' => $club, 'session' => $session])
        ->assertSeeHtml('data-test="board-fullscreen-button"')
        ->assertSeeHtml('data-test="board-fullscreen-exit"')
        ->assertSee('Exit full screen')
        ->assertSeeHtml('x-data="boardFullscreen()"');
})->with([SessionStatus::Draft, SessionStatus::Live]);

test('the full screen button is not shown on an ended board', function () {
    [$user, $club, $session] = fullScreenBoard(SessionStatus::Ended);

    Livewire::actingAs($user)->test(Show::class, ['club' => $club, 'session' => $session])
        ->assertDontSeeHtml('data-test="board-fullscreen-button"');
});

test('guests get the full screen button on draft and live boards but not on an ended one', function () {
    foreach ([SessionStatus::Draft, SessionStatus::Live, SessionStatus::Ended] as $status) {
        [$club, $session, $token] = guestBoard();
        if ($status !== SessionStatus::Draft) {
            $session->forceFill(['status' => $status])->save();
        }

        $response = $this->withCookie(guestCookie($club), $token)
            ->get(route('clubs.sessions.show', [$club, $session]))
            ->assertOk();

        if ($status === SessionStatus::Ended) {
            $response->assertDontSee('data-test="board-fullscreen-button"', false);
        } else {
            $response->assertSee('data-test="board-fullscreen-button"', false)
                ->assertSee('data-test="board-fullscreen-exit"', false);
        }
    }
});

test('the member layout marks the sidebar, mobile header, back link and description as chrome', function () {
    [$user, $club, $session] = fullScreenBoard(SessionStatus::Live);

    $html = $this->actingAs($user)->get(route('clubs.sessions.show', [$club, $session]))->assertOk()->getContent();

    expect($html)->toMatch('/<ui-sidebar[^>]*class="[^"]*board-chrome/')
        ->toMatch('/<header[^>]*class="[^"]*board-chrome[^>]*data-flux-header/s')
        ->toMatch('/<a href="[^"]*"[^>]*class="board-chrome[^"]*"[^>]*>\s*<svg[^>]*>.*?All sessions/s')
        ->toMatch('/<p class="board-chrome [^"]*">[^<]*courts?/')
        ->toContain('board-fs-wide');
});

test('the guest layout marks the top bar as chrome and keeps the countdown banner', function () {
    [$club, $session, $token] = guestBoard();

    $html = $this->withCookie(guestCookie($club), $token)
        ->get(route('clubs.sessions.show', [$club, $session]))
        ->assertOk()
        ->assertSee('data-test="guest-banner"', false)
        ->getContent();

    expect($html)->toMatch('/<header class="board-chrome [^"]*"[^>]*data-test="guest-topbar"/')
        ->toMatch('/<main id="main" class="board-fs-wide /');
});

test('the tv page still renders its own full screen button', function () {
    $club = Club::factory()->create();
    $session = PlaySession::factory()->for($club)->live()->create();

    Livewire::test(Tv::class, ['club' => $club, 'tvId' => $session->tv_id])
        ->assertSeeHtml('data-test="fullscreen-button"')
        ->assertSeeHtml('x-data="tvScreen()"')
        ->assertDontSeeHtml('board-fullscreen');
});
