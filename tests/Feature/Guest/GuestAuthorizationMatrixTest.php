<?php

use App\Livewire\Guest\OrganizerLink;
use App\Livewire\Sessions\CheckInPanel;
use App\Livewire\Sessions\CheckInQr;
use App\Livewire\Sessions\Courts;
use App\Livewire\Sessions\Form;
use App\Livewire\Sessions\ImportPlayers;
use App\Livewire\Sessions\Results;
use App\Livewire\Sessions\ResultsPage;
use App\Livewire\Sessions\Show;
use App\Livewire\Sessions\UpNext;
use App\Livewire\Sessions\WaitingList;
use App\Models\Club;
use App\Models\DuprExport;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Support\GuestOwnerContext;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;

/*
|--------------------------------------------------------------------------
| P14.10 authorization matrix
|--------------------------------------------------------------------------
| Part 1 walks every route under /clubs/{club} (read from the router, so a new route is
| caught automatically and denied for guests until someone whitelists it here on purpose).
| Part 2 walks every public method of the guest board's Livewire components, also
| discovered by reflection, and calls it as each kind of actor.
*/

/** The only club routes a guest organizer may open. Everything else must stay closed. */
const GUEST_ALLOWED_ROUTES = ['clubs.sessions.show', 'clubs.sessions.edit', 'clubs.sessions.results'];

/**
 * @return array{club: Club, session: PlaySession, token: string, player: Player, export: DuprExport, normal: Club, normalSession: PlaySession, normalPlayer: Player, normalExport: DuprExport, otherGuest: Club, otherToken: string, member: User, stranger: User}
 */
function authMatrixWorld(): array
{
    Storage::fake('local');

    [$club, $session, $token] = guestBoard();
    [$otherGuest, , $otherToken] = guestBoard();
    $member = User::factory()->create();
    $normal = Club::factory()->withOwner($member)->create();
    $normalSession = PlaySession::factory()->for($normal)->ended()->create();

    // A real export row and file on both sides, so the download route would answer 200 if the gate let the actor through.
    $export = DuprExport::factory()->create(['play_session_id' => $session->id, 'file_path' => "dupr-exports/{$club->id}/guest.csv"]);
    $normalExport = DuprExport::factory()->create(['play_session_id' => $normalSession->id, 'file_path' => "dupr-exports/{$normal->id}/normal.csv"]);
    Storage::disk('local')->put($export->file_path, 'csv');
    Storage::disk('local')->put($normalExport->file_path, 'csv');

    return [
        'club' => $club, 'session' => $session, 'token' => $token,
        'player' => Player::factory()->for($club)->manual(3)->create(),
        'export' => $export,
        'normal' => $normal, 'normalSession' => $normalSession,
        'normalPlayer' => Player::factory()->for($normal)->manual(3)->create(),
        'normalExport' => $normalExport,
        'otherGuest' => $otherGuest, 'otherToken' => $otherToken,
        'member' => $member, 'stranger' => User::factory()->create(),
    ];
}

/** Every route whose URI starts with clubs/{club...}. @return list<LaravelRoute> */
function clubRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (LaravelRoute $r): bool => str_starts_with($r->uri(), 'clubs/{club'),
    ));
}

/** @param array<string, string|int> $values */
function clubRouteUrl(LaravelRoute $route, array $values): string
{
    return '/'.preg_replace_callback('/\{(\w+)(?::\w+)?\??\}/', function (array $m) use ($values, $route): string {
        if (! array_key_exists($m[1], $values)) {
            throw new RuntimeException("The authorization matrix does not know route parameter {{$m[1]}} of ".($route->getName() ?? $route->uri()).'. Teach it how to fill that parameter.');
        }

        return (string) $values[$m[1]];
    }, $route->uri());
}

/**
 * Request every club route as one actor and return the mismatches ("GET /url: expected X, got Y").
 *
 * @param  array<string, string|int>  $values  route parameter values for the target club
 * @param  callable(LaravelRoute): string  $expect  'ok' | 'login' | '404' for a route
 * @return list<string>
 */
function matrixMismatches(object $test, array $values, callable $expect): array
{
    $bad = [];
    foreach (clubRoutes() as $route) {
        $url = clubRouteUrl($route, $values);
        $method = $route->methods()[0];
        $name = $route->getName() ?? $route->uri();

        // get()/post()/... carry the cookies set with withCookie(); call() does not.
        $response = $test->{$method === 'HEAD' ? 'get' : strtolower($method)}($url);
        $got = match (true) {
            $response->isRedirect() && $response->headers->get('Location') === route('login') => 'login',
            $response->getStatusCode() === 404 => '404',
            $response->getStatusCode() === 200 => 'ok',
            default => 'status '.$response->getStatusCode().($response->isRedirect() ? ' to '.$response->headers->get('Location') : ''),
        };
        $want = $expect($route);
        if ($got !== $want) {
            $bad[] = "{$method} {$url} ({$name}): expected {$want}, got {$got}";
        }
    }

    return $bad;
}

test('the matrix sees the club routes it is meant to guard', function () {
    $names = array_map(fn (LaravelRoute $r) => $r->getName(), clubRoutes());

    // If the router lookup ever broke, the matrix below would pass with no routes. Pin a floor.
    expect(count($names))->toBeGreaterThanOrEqual(13)
        ->and($names)->toContain(...GUEST_ALLOWED_ROUTES)
        ->and($names)->toContain('clubs.show', 'clubs.settings', 'clubs.members', 'clubs.players.index', 'clubs.players.show', 'clubs.stats', 'clubs.sessions.index', 'clubs.sessions.create', 'clubs.sessions.dupr', 'clubs.sessions.dupr.download');
});

// --- part 1: HTTP routes of a guest club ---

dataset('guest club route actors', [
    'guest cookie for this club' => 'guest-this',
    'guest cookie for another guest club' => 'guest-other',
    'another guest club\'s token replayed under this club\'s cookie' => 'guest-replay',
    'signed-in member of a normal club' => 'member',
    'signed-in non-member' => 'stranger',
    'signed-in member who also holds this club\'s cookie' => 'member-with-cookie',
]);

test('every /clubs/{club} route of a guest club answers each actor correctly', function (string $actor) {
    $w = authMatrixWorld();
    $values = ['club' => $w['club']->slug, 'session' => $w['session']->id, 'player' => $w['player']->id, 'export' => $w['export']->id];

    match ($actor) {
        'guest-this' => $this->withCookie(guestCookie($w['club']), $w['token']),
        'guest-other' => $this->withCookie(guestCookie($w['otherGuest']), $w['otherToken']),
        'guest-replay' => $this->withCookie(guestCookie($w['club']), $w['otherToken']),
        'member' => $this->actingAs($w['member']),
        'stranger' => $this->actingAs($w['stranger']),
        'member-with-cookie' => $this->actingAs($w['member'])->withCookie(guestCookie($w['club']), $w['token']),
    };

    $bad = matrixMismatches($this, $values, function (LaravelRoute $route) use ($actor): string {
        $guestPage = in_array($route->getName(), GUEST_ALLOWED_ROUTES, true);

        return match ($actor) {
            // The organizer reaches exactly the whitelisted pages; the member-only group sends a visitor to log in.
            'guest-this' => $guestPage ? 'ok' : 'login',
            // Another guest's cookie is useless: member-only routes still say "log in", the board says 404.
            'guest-other', 'guest-replay' => $guestPage ? '404' : 'login',
            // Signed in, but a member of nothing here: 404 everywhere, unless the guest cookie unlocks the board.
            'member', 'stranger' => '404',
            'member-with-cookie' => $guestPage ? 'ok' : '404',
        };
    });

    expect($bad)->toBe([]);
})->with('guest club route actors');

// --- the same matrix on a normal club, so the guest rules do not leak and the 404s above are not a setup accident ---

dataset('normal club route actors', [
    'owner' => 'owner',
    'signed-in non-member' => 'stranger',
    'no user' => 'nobody',
    'guest cookie of a guest club' => 'guest-cookie',
]);

test('every /clubs/{club} route of a normal club still follows the member rules', function (string $actor) {
    $w = authMatrixWorld();
    $values = ['club' => $w['normal']->slug, 'session' => $w['normalSession']->id, 'player' => $w['normalPlayer']->id, 'export' => $w['normalExport']->id];

    match ($actor) {
        'owner' => $this->actingAs($w['member']),
        'stranger' => $this->actingAs($w['stranger']),
        'nobody' => null,
        'guest-cookie' => $this->withCookie(guestCookie($w['club']), $w['token']),
    };

    $bad = matrixMismatches($this, $values, fn (LaravelRoute $route): string => match ($actor) {
        'owner' => 'ok',
        'stranger' => '404',
        'nobody', 'guest-cookie' => 'login',
    });

    expect($bad)->toBe([]);
})->with('normal club route actors');

test('a guest cookie never opens another club\'s board, and a cookie for club A under club B\'s name fails', function () {
    $w = authMatrixWorld();
    $this->withCookie(guestCookie($w['club']), $w['token']);

    foreach (GUEST_ALLOWED_ROUTES as $name) {
        // Club B in the URL with club A's valid cookie: the cookie is named for A, so B sees nothing.
        $other = PlaySession::query()->where('club_id', $w['otherGuest']->id)->firstOrFail();
        $this->get(route($name, [$w['otherGuest'], $other]))->assertNotFound();
        // Club A in the URL with club B's session id: scoped binding, 404.
        $this->get(route($name, [$w['club'], $other]))->assertNotFound();
    }
});

// --- part 2: Livewire actions of the guest board components ---

/**
 * Every component of the guest board, with how it is mounted.
 *
 * @return array<string, array{class: class-string, params: list<string>}>
 */
function boardComponents(): array
{
    return [
        'Show' => ['class' => Show::class, 'params' => ['club', 'session']],
        'Form (edit)' => ['class' => Form::class, 'params' => ['club', 'session']],
        'ResultsPage' => ['class' => ResultsPage::class, 'params' => ['club', 'session']],
        'CheckInPanel' => ['class' => CheckInPanel::class, 'params' => ['session']],
        'CheckInQr' => ['class' => CheckInQr::class, 'params' => ['session']],
        'ImportPlayers' => ['class' => ImportPlayers::class, 'params' => ['club', 'session']],
        'Courts' => ['class' => Courts::class, 'params' => ['session']],
        'UpNext' => ['class' => UpNext::class, 'params' => ['session']],
        'WaitingList' => ['class' => WaitingList::class, 'params' => ['session']],
        'Results' => ['class' => Results::class, 'params' => ['session']],
        'OrganizerLink' => ['class' => OrganizerLink::class, 'params' => ['club']],
    ];
}

/**
 * Public methods a browser can call on the component: everything declared in app/Livewire
 * except lifecycle hooks, listeners getters and computed properties.
 *
 * @param  class-string  $class
 * @return list<ReflectionMethod>
 */
function boardActions(string $class): array
{
    // Livewire itself refuses to call lifecycle hooks (updated*, mount, ...) from the browser.
    $lifecycle = ['mount', 'render', 'hydrate', 'dehydrate', 'boot', 'booted', 'getListeners', 'placeholder'];
    $appDir = str_replace('\\', '/', app_path('Livewire'));

    return array_values(array_filter(
        (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $m): bool => ! $m->isStatic()
            && ! str_starts_with($m->getName(), '__')
            && ! preg_match('/^(updated|updating)[A-Z]/', $m->getName())
            && ! in_array($m->getName(), $lifecycle, true)
            && $m->getAttributes(Computed::class) === []
            && str_starts_with(str_replace('\\', '/', (string) $m->getFileName()), $appDir),
    ));
}

/**
 * Actions that need no authorisation because they only change the component's own screen state
 * (collapse a panel, reload from the DB, edit a not-yet-saved form row) and never write data or
 * reveal anything the mounted page did not already show. Adding a method here is a deliberate review point.
 */
const LOCAL_STATE_ACTIONS = [
    'refreshBoard', 'refreshPanel', 'sessionChanged', 'syncFromSession', 'syncFromBroadcast',
    'cancelEdit', 'cancelVoid', 'showMore', 'cancelModeChange', 'closePanel', 'closeQuickAdd',
    'addGroup', 'removeGroup', 'resetGroups', 'unpick', 'removeNew',
    'dismissResults', 'back', 'resetImport',
];

/** What a guest organizer must NOT be able to do even on their own club: "Class::method". */
const GUEST_FORBIDDEN_ACTIONS = ['Show::delete'];

/** Positional scalar arguments for the method; class-typed parameters are injected by Livewire. */
function actionArguments(ReflectionMethod $method): array
{
    $args = [];
    foreach ($method->getParameters() as $p) {
        $type = $p->getType();
        if (! $type instanceof ReflectionNamedType || ! $type->isBuiltin()) {
            continue;
        }
        $args[] = match ($type->getName()) {
            'int' => 987654,
            'string' => 'x',
            'bool' => false,
            'array' => [],
            default => null,
        };
    }

    return $args;
}

function becomeActor(string $actor, array $w): void
{
    Auth::forgetGuards();
    $context = app(GuestOwnerContext::class);
    $context->clear();

    match ($actor) {
        'guest-this' => $context->set($w['club']),
        'guest-other' => $context->set($w['otherGuest']),
        'member' => test()->actingAs($w['member']),
        'stranger' => test()->actingAs($w['stranger']),
    };
}

/** Denied the way this app denies: 403 for a visitor, 404 for a signed-in non-member (the policies hide the club). */
function isForbidden(mixed $component): bool
{
    foreach (['assertForbidden', 'assertNotFound'] as $assertion) {
        try {
            $component->{$assertion}();

            return true;
        } catch (AssertionFailedError) {
            // try the next status
        }
    }

    return false;
}

dataset('board components', array_map(fn (array $c): array => [$c], boardComponents()));

test('the matrix covers every Livewire component the guest board renders', function () {
    $w = authMatrixWorld();
    $html = guestBoardHtml($this, $w);

    // Each component embedded in the board page is in the matrix (the page renders them as wire:snapshot roots).
    preg_match_all('/wire:snapshot="([^"]*)"/', $html, $m);
    $rendered = [];
    foreach ($m[1] as $snapshot) {
        $data = json_decode(html_entity_decode($snapshot, ENT_QUOTES), true);
        $rendered[] = $data['memo']['name'] ?? '';
    }

    $known = array_map(fn (array $c): string => Livewire::new($c['class'])->getName(), boardComponents());
    expect(array_values(array_diff(array_filter($rendered), $known)))->toBe([]);
});

function guestBoardHtml(object $test, array $w): string
{
    return $test->withCookie(guestCookie($w['club']), $w['token'])
        ->get(route('clubs.sessions.show', [$w['club'], $w['session']]))->assertOk()->getContent();
}

test('every action of a guest board component is authorised: forbidden to everyone but this club\'s organizer', function (array $spec) {
    $w = authMatrixWorld();
    $short = (new ReflectionClass($spec['class']))->getShortName();
    $values = ['club' => $w['club'], 'session' => $w['session']];
    $mountParams = array_intersect_key($values, array_flip($spec['params']));

    $actions = boardActions($spec['class']);
    $problems = [];
    $called = 0;

    foreach ($actions as $method) {
        $name = $method->getName();
        $args = actionArguments($method);
        $key = "{$short}::{$name}";
        $localOnly = in_array($name, LOCAL_STATE_ACTIONS, true);

        foreach (['guest-this', 'guest-other', 'member', 'stranger'] as $actor) {
            // Mount as the real organizer (the mount authorisation is covered elsewhere), then switch actor and call.
            becomeActor('guest-this', $w);
            $component = Livewire::test($spec['class'], $mountParams);
            becomeActor($actor, $w);

            $component->call($name, ...$args);
            $called++;
            $forbidden = isForbidden($component);

            $expectForbidden = match (true) {
                $localOnly => false,
                $actor === 'guest-this' => in_array($key, GUEST_FORBIDDEN_ACTIONS, true),
                default => true,
            };

            if ($forbidden !== $expectForbidden) {
                $problems[] = "{$key} as {$actor}: expected ".($expectForbidden ? '403' : 'not 403').', got '.($forbidden ? '403' : 'not 403');
            }
        }
    }

    expect($problems)->toBe([])
        ->and($called)->toBe(count($actions) * 4);
})->with('board components');

test('every name on the local-state exemption list is a real board action', function () {
    $existing = [];
    foreach (boardComponents() as $spec) {
        foreach (boardActions($spec['class']) as $method) {
            $existing[$method->getName()] = true;
        }
    }

    // A stale name here would silently exempt a future method with the same name.
    expect(array_values(array_diff(LOCAL_STATE_ACTIONS, array_keys($existing))))->toBe([]);
});

test('the unauthorised branch is real: a member may delete a session of their own normal club', function () {
    // Positive control for the matrix: the same Show::delete that is forbidden to a guest works for an owner,
    // so the 403s above come from the policy, not from a broken harness.
    $w = authMatrixWorld();
    $session = PlaySession::factory()->for($w['normal'])->create();
    $this->actingAs($w['member']);

    $c = Livewire::test(Show::class, ['club' => $w['normal'], 'session' => $session])->call('delete');

    expect(isForbidden($c))->toBeFalse()
        ->and(PlaySession::query()->whereKey($session->id)->exists())->toBeFalse();
});

test('a guest component cannot be mounted by another club\'s organizer, a member or a non-member', function (array $spec) {
    $w = authMatrixWorld();
    $values = ['club' => $w['club'], 'session' => $w['session']];
    $mountParams = array_intersect_key($values, array_flip($spec['params']));

    foreach (['guest-other', 'member', 'stranger'] as $actor) {
        becomeActor($actor, $w);
        // OrganizerLink only renders a link the cookie holder already has; it does not gate on identity.
        if ($spec['class'] === OrganizerLink::class) {
            Livewire::test($spec['class'], $mountParams)->assertOk();

            continue;
        }
        expect(isForbidden(Livewire::test($spec['class'], $mountParams)))->toBeTrue("{$spec['class']} mounted for {$actor}");
    }

    becomeActor('guest-this', $w);
    Livewire::test($spec['class'], $mountParams)->assertOk();
})->with('board components');
