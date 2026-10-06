<?php

namespace App\Livewire\Stats;

use App\Enums\StatsPeriod;
use App\Models\Club;
use App\Services\StatsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Public leaderboard (P5.2b): display names only, no links. 404 while the
 * club's public_stats setting is off. Only the slug is kept in state.
 */
#[Layout('layouts::public')]
#[Title('Leaderboard')]
class PublicLeaderboard extends Component
{
    #[Locked]
    public string $clubSlug = '';

    #[Url(except: 'all_time')]
    public string $period = 'all_time';

    public function mount(Club $club, StatsService $stats): void
    {
        $stats->resolvePublicClub($club);

        $this->clubSlug = $club->slug;
    }

    public function render(StatsService $stats): View
    {
        $club = Club::query()->where('slug', $this->clubSlug)->firstOrFail();
        $board = $stats->publicLeaderboard($club, StatsPeriod::fromInput($this->period));

        return view('livewire.stats.public-leaderboard', [
            'clubName' => $club->name,
            'board' => $board,
            'periods' => StatsPeriod::cases(),
        ]);
    }
}
