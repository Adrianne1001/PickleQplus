<?php

namespace App\Livewire\Sessions;

use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\User;
use App\Services\PlayerListImportService;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Reactive;
use Livewire\Component;

/**
 * "Import player list" modal (Phase 16): paste or Reclub link -> review rows -> add.
 *
 * Shared by the board panel and the session form. On the board (`mode` board) the confirm
 * writes the list through PlayerListImportService and tells the panel with `players-imported`.
 * On the form (`mode` form) nothing is written: the reviewed rows are handed to the form with
 * `import-rows`, and the form re-validates them with PlayerListImportService::selection().
 *
 * The rows live in component state and are untrusted: the service re-derives every outcome
 * from the editable fields on each review and on confirm.
 *
 * @phpstan-import-type ReviewRow from PlayerListImportService
 */
class ImportPlayers extends Component
{
    public const MODAL = 'import-players';

    /** Most rows a review keeps, however many the client sends. */
    private const MAX_ROWS = 500;

    #[Locked]
    public Club $club;

    /** Null while the form is creating a session. */
    #[Locked]
    public ?PlaySession $session = null;

    /** `board` writes the list, `form` hands the rows to the session form. */
    #[Locked]
    public string $mode = 'board';

    /**
     * Players the form has picked but not saved yet: counted as already on the list.
     *
     * Nullable on purpose: a parent that doesn't pass the prop makes Livewire sync it as null.
     *
     * @var array<int, mixed>|null
     */
    #[Reactive]
    public ?array $alsoListed = [];

    public string $tab = 'paste';

    public string $text = '';

    public string $url = '';

    /** `input` (paste or link) or `review`. */
    #[Locked]
    public string $step = 'input';

    /** @var array<int, array<string, mixed>> */
    public array $rows = [];

    /**
     * The rows as the service last reviewed them, restored when a re-review of edited rows is refused.
     *
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $reviewedRows = [];

    /**
     * @param  array<int, mixed>  $alsoListed
     */
    public function mount(Club $club, ?PlaySession $session = null, string $mode = 'board', array $alsoListed = []): void
    {
        if ($session !== null) {
            abort_unless($session->club_id === $club->id, 404);
        }

        $this->club = $club;
        $this->session = $session;
        $this->mode = $mode === 'form' ? 'form' : 'board';
        $this->alsoListed = $alsoListed;

        $this->authorizeImport();
    }

    /** Parse the pasted text and show the review. */
    public function reviewPaste(PlayerListImportService $import): void
    {
        $this->authorizeImport();
        $this->resetErrorBag();

        $this->setRows($import->reviewPaste($this->club, $this->session, $this->text, $this->alsoListedIds()));
        $this->step = 'review';
    }

    /** Fetch the Reclub event and show its confirmed players for review. */
    public function loadReclub(PlayerListImportService $import): void
    {
        $this->authorizeImport();
        $this->resetErrorBag();

        $user = auth()->user();
        $this->setRows($import->reviewReclub($this->club, $this->session, trim($this->url), $user instanceof User ? $user : null, $this->alsoListedIds()));
        $this->step = 'review';
    }

    /** A row's name, rating or DUPR ID was edited: re-match every row (a repeated name can move to another row). */
    public function updatedRows(): void
    {
        $this->authorizeImport();
        $this->rematch();
    }

    public function dropRow(int $index): void
    {
        $this->authorizeImport();

        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
        $this->reviewedRows = $this->rows;

        if ($this->rows === []) {
            $this->back();

            return;
        }
        $this->rematch();
    }

    /** Back to the paste or link step. The typed text and link are kept. */
    public function back(): void
    {
        $this->resetErrorBag();
        $this->rows = [];
        $this->reviewedRows = [];
        $this->step = 'input';
    }

    /** Close and forget everything (Cancel, Esc, or after a successful add). */
    public function resetImport(): void
    {
        $this->resetErrorBag();
        $this->rows = [];
        $this->reviewedRows = [];
        $this->step = 'input';
        $this->text = '';
        $this->url = '';
        $this->tab = 'paste';
    }

    public function confirm(PlayerListImportService $import): void
    {
        $this->authorizeImport();
        $this->authorize('create', [Player::class, $this->club]);
        $this->resetErrorBag();

        if ($this->rows === []) {
            throw ValidationException::withMessages(['rows' => __('There are no players to add.')]);
        }

        if ($this->mode === 'form') {
            // The form re-validates these rows itself; nothing is written until the session is saved.
            $this->dispatch('import-rows', rows: $this->cleanRows());
        } else {
            $session = $this->session ?? abort(404);
            $user = auth()->user();
            $result = $import->confirm($session, $this->cleanRows(), $user instanceof User ? $user : null);

            // The result stays on the server. The event carries only an unguessable token, so a
            // forged event can't put made-up counts or notes on the panel.
            $token = Str::random(40);
            Cache::put(self::resultKey($session, $token), $result, now()->addMinutes(5));
            $this->dispatch('players-imported', token: $token);
        }

        $this->resetImport();
        Flux::modal(self::MODAL)->close();
    }

    /** Cache key of a confirm result waiting for the panel to pick it up. */
    public static function resultKey(PlaySession $session, string $token): string
    {
        return "import-result:{$session->id}:{$token}";
    }

    /**
     * Rows per outcome, and how many players "Add" would put on the list.
     *
     * @return array{existing: int, new: int, listed: int, error: int, add: int}
     */
    #[Computed]
    public function summary(): array
    {
        $existing = $new = $listed = $error = 0;
        foreach ($this->rows as $row) {
            match ($row['status'] ?? null) {
                'existing' => $existing++,
                'new' => $new++,
                'listed' => $listed++,
                default => $error++,
            };
        }

        return ['existing' => $existing, 'new' => $new, 'listed' => $listed, 'error' => $error, 'add' => $existing + $new];
    }

    public function render(): View
    {
        return view('livewire.sessions.import-players');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  Reviewed by the service
     */
    private function setRows(array $rows): void
    {
        $this->rows = $rows;
        $this->reviewedRows = $rows;
    }

    private function rematch(): void
    {
        try {
            $this->setRows(app(PlayerListImportService::class)->review(
                $this->club,
                $this->session,
                $this->cleanRows(),
                $this->alsoListedIds(),
            ));
        } catch (ValidationException $e) {
            // E.g. too many rows: put back what was last reviewed, so the screen never shows unreviewed rows.
            $this->rows = $this->reviewedRows;

            throw $e;
        }
    }

    /**
     * The rows as a plain list, capped. They come from the browser and are only ever used as input to the service.
     *
     * @return list<array<string, mixed>>
     */
    private function cleanRows(): array
    {
        return array_slice($this->rows, 0, self::MAX_ROWS);
    }

    /**
     * @return list<int>
     */
    private function alsoListedIds(): array
    {
        $ids = [];
        foreach ($this->alsoListed ?? [] as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    private function authorizeImport(): void
    {
        abort_if($this->session?->isEnded() ?? false, 403);

        if ($this->session === null) {
            $this->authorize('create', [PlaySession::class, $this->club]);
        } else {
            $this->authorize($this->mode === 'form' ? 'update' : 'manage', $this->session);
        }
    }
}
