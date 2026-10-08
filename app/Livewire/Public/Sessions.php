<?php

namespace App\Livewire\Public;

use App\Models\Club;
use App\Services\SessionResultsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Public past-sessions list of a club (P11.4): a banner for the live session, then
 * ended sessions newest first, 20 per page (`?page=`). Only the slug is kept in state.
 */
#[Layout('layouts::public')]
#[Title('Sessions')]
class Sessions extends Component
{
    #[Locked]
    public string $clubSlug = '';

    #[Url(except: 1)]
    public int $page = 1;

    public function mount(Club $club): void
    {
        $this->clubSlug = $club->slug;
    }

    public function render(SessionResultsService $results): View
    {
        $club = Club::query()->where('slug', $this->clubSlug)->firstOrFail();
        $list = $results->publicSessions($club, $this->page);

        return view('livewire.public.sessions', [
            'club' => $club,
            'list' => $list,
        ]);
    }
}
