<?php

use App\Enums\ClubRole;
use App\Models\Club;
use App\Models\User;
use App\Notifications\ClubInvitationNotification;
use App\Services\InvitationService;
use App\Services\RosterImport\RosterImportPreview;
use App\Services\RosterImport\RosterImportRow;
use App\Services\RosterImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full Laravel application and get a fresh database
| per test. Unit tests stay framework-free (important for the pure-PHP
| rotation engine).
|
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/**
 * A verified user who owns a fresh club.
 *
 * @return array{0: User, 1: Club}
 */
function memberOf(): array
{
    $user = User::factory()->create();
    $club = Club::factory()->withOwner($user)->create();

    return [$user, $club];
}

function inviteSetup(): array
{
    $owner = User::factory()->create();
    $club = Club::factory()->withOwner($owner)->create();

    return [$owner, $club];
}

/** Send an invite through the service and return [invitation, plainToken]. */
function sendInvite(Club $club, User $owner, string $email, ClubRole $role = ClubRole::Staff): array
{
    Notification::fake();
    $invitation = app(InvitationService::class)->invite($club, $owner, $email, $role);

    $token = null;
    Notification::assertSentOnDemand(ClubInvitationNotification::class, function ($n) use (&$token) {
        $token = $n->plainToken;

        return true;
    });

    return [$invitation, (string) $token];
}

function csvFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'roster');
    file_put_contents($path, $contents);

    return $path;
}

function importPreview(Club $club, string $contents): RosterImportPreview
{
    return app(RosterImportService::class)->preview($club, csvFile($contents));
}

/** @return array<int, RosterImportRow> keyed by line */
function rowsByLine(RosterImportPreview $preview): array
{
    $out = [];
    foreach ($preview->rows as $row) {
        $out[$row->line] = $row;
    }

    return $out;
}
