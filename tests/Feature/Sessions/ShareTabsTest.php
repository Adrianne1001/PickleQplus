<?php

use App\Enums\SessionStatus;
use App\Livewire\Public\Queue;
use App\Livewire\Sessions\CheckInQr;
use App\Models\Club;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\CheckInQrService;
use Livewire\Livewire;

function shareSetup(SessionStatus $status = SessionStatus::Live): array
{
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();
    $session = PlaySession::factory()->for($club)->live()->create();
    if ($status !== SessionStatus::Live) {
        // Created live so it keeps its check-in token, then moved to the status under test.
        $session->forceFill(['status' => $status])->save();
    }

    return [$owner, $club, $session];
}

it('renders three share tabs with their QR codes, links and own actions', function (): void {
    [$owner, $club, $session] = shareSetup();

    Livewire::actingAs($owner)->test(CheckInQr::class, ['session' => $session])
        ->assertSee('Share: QR codes and links')
        ->assertSeeHtml('data-test="share-tab-checkin"')
        ->assertSeeHtml('data-test="share-tab-queue"')
        ->assertSeeHtml('data-test="share-tab-tv"')
        ->assertSeeHtml('data-test="checkin-qr-svg"')
        ->assertSeeHtml('data-test="queue-qr-svg"')
        ->assertSeeHtml('data-test="tv-qr-svg"')
        ->assertSeeHtml('data-test="checkin-url"')
        ->assertSeeHtml('data-test="queue-url"')
        ->assertSeeHtml('data-test="tv-url"')
        ->assertSeeHtml('data-test="regenerate-qr-button"')
        ->assertSeeHtml('data-test="reset-tv-link-button"')
        ->assertSeeHtml('data-test="fullscreen-queue"')
        ->assertSeeHtml('data-test="fullscreen-checkin"')
        ->assertDontSeeHtml('data-test="fullscreen-tv"')
        ->assertSeeHtml('rel="noopener"')
        ->assertSee('Players scan this to check themselves in.')
        ->assertSee('Keep this link private.');
});

it('keeps the queue QR on an ended session and closes check-in', function (): void {
    [$owner, $club, $session] = shareSetup(SessionStatus::Ended);

    Livewire::actingAs($owner)->test(CheckInQr::class, ['session' => $session])
        ->assertSeeHtml('data-test="checkin-qr-ended"')
        ->assertDontSeeHtml('data-test="checkin-qr-svg"')
        ->assertSeeHtml('data-test="queue-qr-svg"')
        ->assertSee(url('/c/'.$club->slug.'/s/'.$session->public_id))
        ->assertDontSeeHtml('data-test="regenerate-qr-button"')
        ->assertSeeHtml('data-test="tv-qr-ended"')
        ->assertDontSeeHtml('data-test="tv-qr-svg"')
        ->assertDontSeeHtml('data-test="reset-tv-link-button"')
        ->assertDontSee($session->tv_id);
});

it('shows a share button and the page QR on the public queue in every status', function (SessionStatus $status): void {
    [, $club, $session] = shareSetup($status);

    $component = Livewire::test(Queue::class, ['club' => $club, 'publicId' => $session->public_id]);
    $html = $component->html();
    $queueUrl = url('/c/'.$club->slug.'/s/'.$session->public_id);

    // The QR must encode only the queue URL (not visible in the HTML text).
    $component->assertViewHas('shareSvg', app(CheckInQrService::class)->svgForUrl($queueUrl, 320));

    expect($html)->toContain('data-test="share-queue-button"')
        ->toContain('data-test="public-queue-qr"')
        ->toContain('<svg xmlns')
        ->toContain('Scan to follow this queue')
        ->toContain(url('/c/'.$club->slug.'/s/'.$session->public_id))
        ->not->toContain((string) $session->tv_id)
        ->not->toContain('/checkin/');
    expect($session->checkin_token)->not->toBeNull()
        ->and($html)->not->toContain($session->checkin_token)
        ->and($html)->not->toContain($session->tv_id);
})->with([SessionStatus::Draft, SessionStatus::Live, SessionStatus::Ended]);
