<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvitationAcceptController;
use App\Livewire\Sessions\Form as SessionForm;
use App\Livewire\Sessions\Index as SessionsIndex;
use App\Livewire\Sessions\Show as SessionShow;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Declared before the {club:slug} group; "create" is a reserved slug.
    Route::livewire('clubs/create', 'pages::clubs.create')->name('clubs.create');

    /*
    | Club-scoped routes. Every staff page that belongs to a club goes here.
    |
    | Conventions for later phases:
    | - `club.member` 404s non-members, binds Club::current() and remembers the club.
    | - scopeBindings() makes child bindings ({player}, {session}, ...) resolve
    |   through the club's relationship, so another club's record is a 404.
    | - Query club-owned data via the bound club ($club->players()), and never
    |   accept a club_id from input.
    | - Authorize with policies; non-members are denied as not found.
    */
    Route::prefix('clubs/{club:slug}')
        ->middleware('club.member')
        ->scopeBindings()
        ->group(function () {
            Route::livewire('/', 'pages::clubs.show')->name('clubs.show');
            Route::livewire('settings', 'pages::clubs.settings')->name('clubs.settings');
            Route::livewire('members', 'pages::clubs.members')->name('clubs.members');
            Route::livewire('players', 'pages::clubs.players')->name('clubs.players.index');

            // Sessions ("create" is declared before {session}).
            Route::livewire('sessions', SessionsIndex::class)->name('clubs.sessions.index');
            Route::livewire('sessions/create', SessionForm::class)->name('clubs.sessions.create');
            Route::livewire('sessions/{session}', SessionShow::class)->name('clubs.sessions.show');
            Route::livewire('sessions/{session}/edit', SessionForm::class)->name('clubs.sessions.edit');
        });
});

// --- P1.4 invitations ---
// Public accept flow (outside the club group: the invitee is not a member yet).
// A guest sees login/register links and is sent back here afterwards via url.intended.
Route::get('invitations/{token}', [InvitationAcceptController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('invitations.show');
Route::post('invitations/{token}/accept', [InvitationAcceptController::class, 'accept'])
    ->middleware('auth')
    ->where('token', '[A-Za-z0-9]+')
    ->name('invitations.accept');

// --- end P1.4 ---

require __DIR__.'/settings.php';
