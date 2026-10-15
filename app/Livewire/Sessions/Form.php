<?php

namespace App\Livewire\Sessions;

use App\Domain\Rotation\SkillGroups;
use App\Enums\RotationMode;
use App\Livewire\Sessions\Concerns\QuickAddsPlayer;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionListPlayer;
use App\Models\User;
use App\Services\PlayerListImportService;
use App\Services\PlaySessionService;
use App\Services\SessionFormService;
use App\Services\SessionListService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
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

    /** Optional session password for QR check-in (4 to 32 characters). Blank means none. */
    public string $checkin_password = '';

    /** Player search for the Players section. */
    public string $search = '';

    /**
     * Existing club players to put on the session's list on Save. Locked: only pick() and an import may add ids, and both check the club.
     *
     * @var list<int>
     */
    #[Locked]
    public array $pickedIds = [];

    /**
     * Where each picked player came from (`manual`, `paste` or `reclub`), keyed by player id.
     *
     * @var array<int, string>
     */
    #[Locked]
    public array $pickSources = [];

    /**
     * Players to create and list on Save. Blank stars and no rating mean "skill not set" (3 stars, pending).
     *
     * @var list<array{name: string, stars: ?int, dupr_id: ?string, gender?: string, dupr_rating?: ?string, source?: string}>
     */
    #[Locked]
    public array $newPlayers = [];

    /**
     * What the last import added to the form, shown under the Players search.
     *
     * @var array{picked: int, new: int, already: int, errors: int, duplicates: int}|null
     */
    #[Locked]
    public ?array $importSummary = null;

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
            $this->checkin_password = (string) $session->checkin_password;
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
            'checkin_password' => $this->checkin_password,
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
        $user = auth()->user();
        $picks = array_map(fn (int $id): array => ['id' => $id, 'source' => $this->pickSources[$id] ?? 'manual'], $this->pickedIds);
        $session = app(SessionFormService::class)->save($this->club, $this->session, $data, $picks, $this->newPlayers, $user instanceof User ? $user : null);

        $this->redirectRoute('clubs.sessions.show', [$this->club, $session], navigate: true);
    }

    public function updatedSearch(): void
    {
        $this->quickOpen = false;
    }

    /** Add an existing active club player to the "To add" list. */
    public function pick(int $playerId): void
    {
        $this->authorizePlayers();

        $player = $this->club->players()->where('active', true)->find($playerId);
        if ($player === null || in_array($playerId, $this->listedIds(), true)) {
            throw ValidationException::withMessages(['players' => __('That player is not available to add. Search again.')]);
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
        unset($this->pickSources[$playerId]);
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
            // Blank stars stay null: the player is created at 3 stars with "skill not set".
            'stars' => $input['stars'] === null ? null : (int) $input['stars'],
            'dupr_id' => $input['dupr_id'],
            'gender' => $input['gender'],
        ];
        $this->search = '';
        $this->closeQuickAdd();
    }

    /**
     * The import modal confirmed its rows. They come from the browser, so they are re-validated
     * here: existing players join the picks, new ones wait in the "To add" list until Save.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    #[On('import-rows')]
    public function importRows(array $rows): void
    {
        $this->authorizePlayers();
        $this->authorize('create', [Player::class, $this->club]);

        $selection = app(PlayerListImportService::class)->selection($this->club, $this->session, array_slice($rows, 0, 500), $this->pickedIds);

        $picked = 0;
        foreach ($selection['player_ids'] as $pick) {
            if (! in_array($pick['id'], $this->pickedIds, true)) {
                $this->pickedIds[] = $pick['id'];
                $this->pickSources[$pick['id']] = $pick['source'];
                $picked++;
            }
        }

        $pendingNames = array_map(fn (array $p): string => mb_strtolower($p['name']), $this->newPlayers);
        $new = 0;
        $duplicates = 0;
        foreach ($selection['new_players'] as $row) {
            $key = mb_strtolower($row['name']);
            if (in_array($key, $pendingNames, true)) {
                $duplicates++;

                continue;
            }
            $pendingNames[] = $key;
            $this->newPlayers[] = $row;
            $new++;
        }

        $this->importSummary = ['picked' => $picked, 'new' => $new, 'already' => $selection['already_listed'], 'errors' => $selection['errors'], 'duplicates' => $duplicates];
        $this->resetErrorBag(['new_players', 'players']);
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
    private function listedIds(): array
    {
        return $this->session === null ? [] : app(SessionListService::class)->listedIds($this->session);
    }

    /**
     * Active club players matching the search, leaving out anyone already listed or picked.
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
            ->whereNotIn('id', array_merge($this->listedIds(), $this->pickedIds))
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
     * The session's saved player list, read-only on the edit page.
     *
     * @return Collection<int, SessionListPlayer>
     */
    #[Computed]
    public function listed(): Collection
    {
        if ($this->session === null) {
            return new Collection;
        }

        return $this->session->listPlayers()->with('player')->orderBy('id')->get();
    }

    /**
     * @return array{checked_in: int, listed: int, not_yet_here: int}
     */
    #[Computed]
    public function counts(): array
    {
        return $this->session === null
            ? ['checked_in' => 0, 'listed' => 0, 'not_yet_here' => 0]
            : app(SessionListService::class)->counts($this->session);
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
