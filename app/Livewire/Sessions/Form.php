<?php

namespace App\Livewire\Sessions;

use App\Domain\Rotation\SkillGroups;
use App\Enums\RotationMode;
use App\Models\Club;
use App\Models\PlaySession;
use App\Services\PlaySessionService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Session')]
class Form extends Component
{
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

        if ($this->session === null) {
            $this->authorize('create', [PlaySession::class, $this->club]);
            $session = $sessions->create($this->club, $data);
        } else {
            $this->authorize('update', $this->session);
            $session = $sessions->update($this->session, $data);
        }

        $this->redirectRoute('clubs.sessions.show', [$this->club, $session], navigate: true);
    }

    public function cancelModeChange(): void
    {
        $this->confirmingMode = false;
    }

    public function render(): View
    {
        return view('livewire.sessions.form', ['modes' => RotationMode::selectable()]);
    }
}
