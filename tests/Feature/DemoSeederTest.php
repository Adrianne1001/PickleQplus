<?php

use App\Enums\MatchStatus;
use App\Enums\SessionStatus;
use App\Models\Club;
use App\Models\GameMatch;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use Database\Seeders\DemoSeeder;

it('seeds the demo club once and is idempotent', function () {
    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $club = Club::where('name', 'Demo Pickleball Club')->sole();
    $sessionIds = PlaySession::where('club_id', $club->id)->select('id');

    expect(User::where('email', 'test@example.com')->count())->toBe(1)
        ->and($club->users()->count())->toBe(2)
        ->and(Player::where('club_id', $club->id)->count())->toBe(24)
        ->and(Player::where('club_id', $club->id)->where('active', false)->count())->toBe(2)
        ->and(PlaySession::where('club_id', $club->id)->count())->toBe(3)
        ->and(PlaySession::where('club_id', $club->id)->where('status', SessionStatus::Live)->count())->toBe(1)
        ->and(GameMatch::whereIn('play_session_id', $sessionIds)->where('status', MatchStatus::Void)->exists())->toBeTrue()
        ->and(GameMatch::whereIn('play_session_id', $sessionIds)->where('status', MatchStatus::Playing)->exists())->toBeTrue();
});
