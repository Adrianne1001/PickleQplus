<?php

namespace App\Livewire\Public;

use App\Livewire\Public\Concerns\LoadsPublicSession;
use App\Models\Club;
use App\Models\PlaySession;
use App\Services\PublicSessionView;
use App\Services\StatsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Public, read-only queue page for one session (mobile first). The browser
 * remembers who "me" is; the server never knows who is watching.
 *
 * @phpstan-import-type PublicSnapshot from PublicSessionView
 */
#[Layout('layouts::public')]
#[Title('Queue')]
class Queue extends Component
{
    use LoadsPublicSession {
        getListeners as private listenersForSession;
    }

    /**
     * Compact ids-only state for the browser-side "me" card and alerts. No names.
     *
     * @var array{status: string, up_next: list<string>, courts: list<array{id: string, court: int}>, waiting: list<array{id: string, position: int, estimate: int|null}>, on_break: list<string>, players: list<string>}
     */
    #[Locked]
    public array $live = ['status' => 'draft', 'up_next' => [], 'courts' => [], 'waiting' => [], 'on_break' => [], 'players' => []];

    public function mount(Club $club, string $publicId): void
    {
        $session = PlaySession::findByPublicIdOrFail($club, $publicId);

        $this->clubSlug = $club->slug;
        $this->publicId = $publicId;
        $this->refreshLive($this->loadSnapshotFor($session));
    }

    public function hydrate(): void
    {
        $this->refreshLive($this->loadSnapshot());
    }

    /**
     * An ended session no longer changes, so stop listening on its channel.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        return $this->live['status'] === 'ended' ? [] : $this->listenersForSession();
    }

    public function render(): View
    {
        $snapshot = $this->snapshot ?? $this->loadSnapshot();

        $results = null;
        if ($snapshot['status'] === 'ended') {
            // P5.1b: final standings and match log when the club has public stats on (null otherwise).
            $session = $this->resolveSession();
            $results = app(StatsService::class)->publicEndedSession($session->club ?? abort(404), $session);
        }

        return view('livewire.public.queue', [
            'data' => $snapshot,
            'results' => $results,
        ]);
    }

    /**
     * @param  PublicSnapshot  $data
     */
    private function refreshLive(array $data): void
    {

        $courts = [];
        foreach ($data['courts'] as $court) {
            foreach ($court['match']['player_ids'] ?? [] as $id) {
                $courts[] = ['id' => $id, 'court' => $court['court']];
            }
        }

        $upNext = [];
        foreach ($data['up_next'] as $match) {
            array_push($upNext, ...$match['player_ids']);
        }

        $this->live = [
            'status' => $data['status'],
            'up_next' => $upNext,
            'courts' => $courts,
            'waiting' => array_map(fn (array $w): array => ['id' => $w['id'], 'position' => $w['group_position'] ?? $w['position'], 'estimate' => $w['estimate_minutes']], $data['waiting']),
            'on_break' => array_column($data['on_break'], 'id'),
            'players' => array_column($data['players'], 'id'),
        ];
    }
}
