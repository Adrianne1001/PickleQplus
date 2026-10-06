<?php

namespace App\Livewire\Sessions;

use App\Enums\SessionStatus;
use App\Models\Club;
use App\Models\PlaySession;
use App\Services\StatsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Sessions')]
class Index extends Component
{
    use WithPagination;

    #[Locked]
    public Club $club;

    /** Status filter: all, draft, live or ended. */
    #[Url]
    public string $status = 'all';

    public function mount(Club $club): void
    {
        $this->authorize('viewAny', [PlaySession::class, $club]);

        $this->club = $club;
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    /**
     * Live sessions first, then drafts, then ended; newest date first inside
     * each group. 20 per page, optionally filtered by status.
     *
     * @return LengthAwarePaginator<int, PlaySession>
     */
    #[Computed]
    public function sessions(): LengthAwarePaginator
    {
        return app(StatsService::class)->sessionsList($this->club, SessionStatus::tryFrom($this->status));
    }

    public function render(): View
    {
        return view('livewire.sessions.index');
    }
}
