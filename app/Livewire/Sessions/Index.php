<?php

namespace App\Livewire\Sessions;

use App\Enums\SessionPlayerStatus;
use App\Enums\SessionStatus;
use App\Models\Club;
use App\Models\PlaySession;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Sessions')]
class Index extends Component
{
    #[Locked]
    public Club $club;

    public function mount(Club $club): void
    {
        $this->authorize('viewAny', [PlaySession::class, $club]);

        $this->club = $club;
    }

    /**
     * Live sessions first, then drafts, then ended; newest date first inside each group.
     *
     * @return Collection<int, PlaySession>
     */
    #[Computed]
    public function sessions(): Collection
    {
        return $this->club->playSessions()
            ->withCount(['sessionPlayers as checked_in_count' => fn ($q) => $q->where('status', '!=', SessionPlayerStatus::Left->value)])
            ->orderByRaw('case status when ? then 0 when ? then 1 else 2 end', [SessionStatus::Live->value, SessionStatus::Draft->value])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.sessions.index');
    }
}
