<?php

namespace App\Livewire\Sessions;

use App\Domain\Rotation\SkillGroups;
use App\Enums\RotationMode;
use App\Enums\SessionPlayerStatus;
use App\Livewire\Sessions\Concerns\QuickAddsPlayer;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionPlayer;
use App\Services\PlaySessionService;
use App\Services\SessionFormService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Session')]
class Form extends Component
{
    use QuickAddsPlayer;

    #[Locked]
    public Club $club;

    /** Null while creating. */
    #[Locked]
    public ?PlaySession $session = null;

    public string $name = '';

    public string $date = '';

    public string $courts = '';

    public string $up_next_count = '1';

    public bool $auto_fill = false;

    public string $to = '11';

    public string $win_by = '2';

    public string $rotation_mode = 'balanced';

    /**
     * Skill court groups being edited (only used while the mode is skill courts).
     *
     * @var list<array{from_court: string, to_court: string, min_stars: string, max_stars: string}>
     */
    public array $skill_groups = [];

    /** True while waiting for the organizer to confirm a mode change on a live session. */
    public bool $confirmingMode = false;

    /** Player search for the Players section. */
    public string $search = '';

    /**
     * Existing club players to check in on Save. Locked: only pick() may add ids, and it checks the club.
     *
     * @var list<int>
     */
    #[Locked]
    public array $pickedIds = [];

    /**
     * Players to create and check in on Save.
     *
     * @var list<array{name: string, stars: int, dupr_id: ?string, gender: string}>
     */
    #[Locked]
    public array $newPlayers = [];

    public function mount(Club $club, ?PlaySession $session = null): void
    {
        $this->club = $club;

        if ($session === null) {
            $this->authorize('create', [PlaySession::class, $club]);

            $this->name = 'Open Play';
            $this->date = now()->toDateString();
            $this->courts = (string) $club->default_courts;
            $scoring = PlaySessionService::DEFAULT_SCORING;
        } else {
            abort_unless($session->club_id === $club->id, 404);
            $this->authorize('update', $session);

            $this->session = $session;
            $this->name = $session->name;
            $this->date = $session->date->format('Y-m-d');
            $this->courts = (string) $session->courts;
            $this->up_next_count = (string) $session->up_next_count;
            $this->auto_fill = $session->auto_fill;
            $this->rotation_mode = $session->rotation_mode->value;
            if ($session->rotation_mode === RotationMode::SkillCourts) {
                $stored = SkillGroups::tryFromArray($session->mode_settings['skill_groups'] ?? [], $session->courts);
                if ($stored === null) {
                    $this->prefillGroups();
                } else {
                    $this->skill_groups = $this->stringRows($stored->toArray());
                }
            }
            $scoring = $session->scoring;
        }

        $this->to = (string) $scoring['to'];
        $this->win_by = (string) $scoring['win_by'];
    }

    public function updatedRotationMode(): void
    {
        if ($this->rotation_mode === RotationMode::SkillCourts->value && $this->skill_groups === []) {
            $this->prefillGroups();
        }
    }

    /** Keeps the last group's range in step with the court count. */
    public function updatedCourts(): void
    {
        if ($this->rotation_mode !== RotationMode::SkillCourts->value || $this->skill_groups === []) {
            return;
        }
        if (ctype_digit($this->courts) && (int) $this->courts >= 2) {
            $last = array_key_last($this->skill_groups);
            $this->skill_groups[$last]['to_court'] = $this->courts;
        }
    }

    public function addGroup(): void
    {
        $last = $this->skill_groups === [] ? null : $this->skill_groups[array_key_last($this->skill_groups)];
        $from = $last !== null && ctype_digit($last['to_court']) ? (string) ((int) $last['to_court'] + 1) : '1';
        $this->skill_groups[] = [
            'from_court' => $from,
            'to_court' => ctype_digit($this->courts) ? $this->courts : '',
            'min_stars' => '',
            'max_stars' => '',
        ];
    }

    public function removeGroup(int $index): void
    {
        $groups = $this->skill_groups;
        unset($groups[$index]);
        $this->skill_groups = array_values($groups);
    }

    public function resetGroups(): void
    {
        $this->prefillGroups();
    }

    private function prefillGroups(): void
    {
        $courts = ctype_digit($this->courts) ? (int) $this->courts : 0;
        try {
            $this->skill_groups = $this->stringRows(SkillGroups::defaultFor($courts)->toArray());
        } catch (\InvalidArgumentException) {
            $this->skill_groups = [];
        }
    }

    /**
     * @param  list<array<string, int>>  $groups
     * @return list<array{from_court: string, to_court: string, min_stars: string, max_stars: string}>
     */
    private function stringRows(array $groups): array
    {
        return array_map(fn (array $g): array => [
            'from_court' => (string) $g['from_court'],
            'to_court' => (string) $g['to_court'],
            'min_stars' => (string) $g['min_stars'],
            'max_stars' => (string) $g['max_stars'],
        ], $groups);
    }

    public function save(PlaySessionService $sessions): void
    {
        $this->persist($sessions, false);
    }

    public function confirmModeChange(PlaySessionService $sessions): void
    {
        $this->persist($sessions, true);
    }

    /**
     * @param  bool  $confirmed  Only the confirm action passes true; it is never a client-supplied value.
     */
    private function persist(PlaySessionService $sessions, bool $confirmed): void
    {
        // Type-level input check only; ranges and rules live in PlaySessionService.
        $this->validate([
            'courts' => ['required', 'integer'],
            'up_next_count' => ['required', 'integer'],
            'to' => ['required', 'integer'],
            'win_by' => ['required', 'integer'],
        ]);

        $data = [
            'name' => $this->name,
            'date' => $this->date,
            'courts' => (int) $this->courts,
            'up_next_count' => (int) $this->up_next_count,
            'auto_fill' => $this->auto_fill,
            'rotation_mode' => $this->rotation_mode,
            'scoring' => array_replace(PlaySessionService::DEFAULT_SCORING, [
                'to' => (int) $this->to,
                'win_by' => (int) $this->win_by,
            ]),
        ];

        if ($this->rotation_mode === RotationMode::SkillCourts->value) {
            $data['mode_settings'] = ['skill_groups' => $this->skill_groups];
        }

        // A change that voids Up Next on a live session needs an explicit confirmation.
        if (! $confirmed && $this->session !== null && $sessions->changeVoidsUpNext($this->session, $data)) {
            $this->confirmingMode = true;

            return;
        }
        $this->confirmingMode = false;

        if ($this->newPlayers !== []) {
            $this->authorize('create', [Player::class, $this->club]);
        }
        $this->authorize($this->session === null ? 'create' : 'update', $this->session ?? [PlaySession::class, $this->club]);
        $session = app(SessionFormService::class)->save($this->club, $this->session, $data, $this->pickedIds, $this->newPlayers);

        $this->redirectRoute('clubs.sessions.show', [$this->club, $session], navigate: true);
    }

    public function updatedSearch(): void
    {
        $this->quickOpen = false;
    }

    /** Add an existing active club player to the "To check in" list. */
    public function pick(int $playerId): void
    {
        $this->authorizePlayers();

        $player = $this->club->players()->where('active', true)->find($playerId);
        if ($player === null || in_array($playerId, $this->checkedInIds(), true)) {
            throw ValidationException::withMessages(['players' => __('That player is not available to check in. Search again.')]);
        }

        if (! in_array($playerId, $this->pickedIds, true)) {
            $this->pickedIds[] = $playerId;
        }
        $this->search = '';
        $this->resetErrorBag('players');
    }

    public function unpick(int $playerId): void
    {
        $this->pickedIds = array_values(array_diff($this->pickedIds, [$playerId]));
    }

    /** Add the inline form's player to the pending list. Nothing is created until Save. */
    public function addQuick(): void
    {
        $this->authorizePlayers();
        $this->authorize('create', [Player::class, $this->club]);

        $input = $this->validatedQuick();

        foreach ($this->newPlayers as $pending) {
            if (mb_strtolower($pending['name']) === mb_strtolower($input['name'])) {
                throw ValidationException::withMessages(['quick.name' => __('You already added a new player with that name.')]);
            }
        }

        $this->newPlayers[] = [
            'name' => $input['name'],
            'stars' => (int) $input['stars'],
            'dupr_id' => $input['dupr_id'],
            'gender' => $input['gender'],
        ];
        $this->search = '';
        $this->closeQuickAdd();
    }

    public function removeNew(int $index): void
    {
        $pending = $this->newPlayers;
        unset($pending[$index]);
        $this->newPlayers = array_values($pending);
        $this->resetErrorBag(['new_players', 'players']);
    }

    protected function quickClub(): Club
    {
        return $this->club;
    }

    private function authorizePlayers(): void
    {
        abort_if($this->session?->isEnded() ?? false, 403);
        $this->authorize($this->session === null ? 'create' : 'update', $this->session ?? [PlaySession::class, $this->club]);
    }

    /** @return list<int> */
    private function checkedInIds(): array
    {
        if ($this->session === null) {
            return [];
        }

        return array_values($this->session->sessionPlayers()
            ->where('status', '!=', SessionPlayerStatus::Left->value)
            ->pluck('player_id')
            ->map(fn ($id): int => (int) $id)
            ->all());
    }

    /**
     * Active club players matching the search, leaving out anyone checked in or picked.
     *
     * @return Collection<int, Player>
     */
    #[Computed]
    public function results(): Collection
    {
        $term = trim($this->search);
        if ($term === '') {
            return new Collection;
        }

        return $this->club->players()
            ->where('active', true)
            ->where('name', 'like', '%'.addcslashes($term, '\%_').'%')
            ->whereNotIn('id', array_merge($this->checkedInIds(), $this->pickedIds))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Player>
     */
    #[Computed]
    public function picked(): Collection
    {
        if ($this->pickedIds === []) {
            return new Collection;
        }

        return $this->club->players()->whereIn('id', $this->pickedIds)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, SessionPlayer>
     */
    #[Computed]
    public function checkedIn(): Collection
    {
        if ($this->session === null) {
            return new Collection;
        }

        return $this->session->sessionPlayers()
            ->where('status', '!=', SessionPlayerStatus::Left->value)
            ->with('player')
            ->orderBy('checked_in_at')
            ->orderBy('id')
            ->get();
    }

    public function cancelModeChange(): void
    {
        $this->confirmingMode = false;
    }

    public function render(): View
    {
        $ended = $this->session?->isEnded() ?? false;

        $view = view('livewire.sessions.form', [
            'modes' => RotationMode::selectable(),
            'quickAdd' => $ended ? ['show' => false, 'deactivated' => null] : $this->quickAddState(),
        ]);

        return $this->club->is_guest ? $view->layout('layouts::guest-board', ['title' => __('Session'), 'club' => $this->club]) : $view;
    }
}
