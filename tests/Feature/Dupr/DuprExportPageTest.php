<?php

use App\Enums\MatchStatus;
use App\Enums\Team;
use App\Livewire\Sessions\DuprExportPage;
use App\Models\Club;
use App\Models\DuprExport;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** @param  list<Player>|null  $players */
function pageMatch(PlaySession $session, ?array $players = null): GameMatch
{
    $match = GameMatch::factory()->for($session)->create([
        'status' => MatchStatus::Done, 'team_a_score' => 11, 'team_b_score' => 7, 'finished_at' => now(), 'court_no' => 2,
    ]);
    $players ??= Player::factory()->count(4)->for($session->club)->sequence(
        fn () => ['dupr_id' => strtoupper(substr(md5((string) random_int(0, 9999999)), 0, 6))],
    )->create()->all();
    foreach ([[Team::A, 1], [Team::A, 2], [Team::B, 1], [Team::B, 2]] as $i => [$team, $slot]) {
        MatchPlayer::factory()->create(['match_id' => $match->id, 'player_id' => $players[$i]->id, 'team' => $team, 'slot' => $slot]);
    }

    return $match;
}

/** @return array{0: User, 1: Club, 2: PlaySession} */
function pageSetup(bool $ended = true): array
{
    [$user, $club] = memberOf();
    $session = PlaySession::factory()->for($club)->create(['name' => 'Night', 'status' => $ended ? 'ended' : 'live']);

    return [$user, $club, $session];
}

test('owner and staff can open the page', function () {
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->withStaff($staff)->create();
    $session = PlaySession::factory()->for($club)->ended()->create();

    foreach ([$owner, $staff] as $user) {
        $this->actingAs($user)->get(route('clubs.sessions.dupr', [$club, $session]))->assertOk()->assertSee('DUPR export');
    }
});

test('non-members and other clubs sessions get 404', function () {
    [, $club, $session] = pageSetup();
    $this->actingAs(User::factory()->create())->get(route('clubs.sessions.dupr', [$club, $session]))->assertNotFound();

    [$user, $mine] = memberOf();
    $this->actingAs($user)->get(route('clubs.sessions.dupr', [$mine, $session]))->assertNotFound();
});

test('counts, skipped reasons and missing players render', function () {
    [$user, $club, $session] = pageSetup();
    $club->update(['dupr_club_id' => '987654']);
    pageMatch($session);
    $ann = Player::factory()->for($club)->create(['name' => 'Ann NoId', 'dupr_id' => null]);
    $others = Player::factory()->count(3)->for($club)->sequence(['dupr_id' => 'Z1'], ['dupr_id' => 'Z2'], ['dupr_id' => 'Z3'])->create()->all();
    pageMatch($session, [$ann, ...$others]);

    Livewire::actingAs($user)->test(DuprExportPage::class, ['club' => $club, 'session' => $session])
        ->assertSeeText('987654')
        ->assertSeeText('Ann NoId')
        ->assertSeeText('blocks 1 match')
        ->assertSeeText('Missing DUPR ID: Ann NoId')
        ->assertSee('data-test="eligible-count"', false)
        ->assertSeeText('1 skipped')
        ->assertSee(route('clubs.players.index', [$club, 'search' => 'Ann NoId']), false);
});

test('a live session shows the end-session notice instead of the button', function () {
    [$user, $club, $session] = pageSetup(false);
    pageMatch($session);

    Livewire::actingAs($user)->test(DuprExportPage::class, ['club' => $club, 'session' => $session])
        ->assertSeeText('End the session to export')
        ->assertDontSee('data-test="export-button"', false);
});

test('exporting redirects to the download and stamps the matches', function () {
    Storage::fake('local');
    [$user, $club, $session] = pageSetup();
    $match = pageMatch($session);

    $component = Livewire::actingAs($user)->test(DuprExportPage::class, ['club' => $club, 'session' => $session])
        ->call('export');

    $export = DuprExport::query()->firstOrFail();
    $url = route('clubs.sessions.dupr.download', [$club, $session, $export->id]);
    $component->assertJs('window.location = '.json_encode($url, JSON_UNESCAPED_SLASHES).';')
        ->assertNoRedirect()
        ->assertSee($url, false)
        ->assertSee('data-test="history-row"', false)
        ->assertSeeHtml('disabled');
    expect($match->refresh()->dupr_exported_at)->not->toBeNull()
        ->and($component->html())->toMatch('/data-test="eligible-count">\s*0\s*</');
});

test('history lists past exports with working download links', function () {
    Storage::fake('local');
    [$user, $club, $session] = pageSetup();
    pageMatch($session);
    Livewire::actingAs($user)->test(DuprExportPage::class, ['club' => $club, 'session' => $session])->call('export');
    $export = DuprExport::query()->firstOrFail();

    $url = route('clubs.sessions.dupr.download', [$club, $session, $export->id]);
    Livewire::actingAs($user)->test(DuprExportPage::class, ['club' => $club, 'session' => $session])
        ->assertSee($url, false)
        ->assertSeeText($user->name)
        ->assertSeeText('1 match');
    $this->actingAs($user)->get($url)->assertOk();
});

test('an error shows when nothing is eligible', function () {
    Storage::fake('local');
    [$user, $club, $session] = pageSetup();

    Livewire::actingAs($user)->test(DuprExportPage::class, ['club' => $club, 'session' => $session])
        ->call('export')
        ->assertHasErrors('session')
        ->assertSee('data-test="export-error"', false)
        ->assertNoRedirect();
});
