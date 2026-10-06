<?php

use App\Domain\Stars\StarRating;
use App\Enums\RatingSource;
use App\Livewire\Inputs\PlayerInput;
use App\Models\Club;
use App\Models\Player;
use App\Services\PlayerService;
use App\Services\RosterImport\RosterImportPreview;
use App\Services\RosterImport\RosterImportPreviewStore;
use App\Services\RosterImport\RosterImportRow;
use App\Services\RosterImportService;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Players')] class extends Component {
    use WithFileUploads;
    use WithPagination;

    #[Locked]
    public Club $club;

    #[Url(except: '')]
    public string $search = '';

    /** active | inactive | all */
    #[Url(except: 'active')]
    public string $status = 'active';

    /** Id of the player being edited; null while adding. */
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';
    public string $nickname = '';
    public string $dupr_id = '';
    public string $dupr_rating = '';
    public string $rating_source = 'manual';
    public string $stars = '';

    /** Roster import: the uploaded CSV (client-supplied; validated before use). */
    public ?TemporaryUploadedFile $importFile = null;

    /**
     * Roster import: id of the server-side stored preview (never the rows, which
     * would bloat the Livewire snapshot). Locked, so the client can't swap it.
     */
    #[Locked]
    public ?string $importPreviewId = null;

    /** True when the stored preview vanished (expired) between steps. */
    #[Locked]
    public bool $importExpired = false;

    /**
     * @var array{created: int, updated: int, skipped: int, errors: int, notes: list<string>}|null
     */
    #[Locked]
    public ?array $importResult = null;

    public bool $importErrorsOnly = false;

    public function mount(Club $club): void
    {
        $this->authorize('viewAny', [Player::class, $club]);

        $this->club = $club;
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDuprId(): void
    {
        $this->dupr_id = strtoupper($this->dupr_id);
    }

    /**
     * Entering a rating on a player without one defaults the stars to
     * "from DUPR rating"; clearing it forces manual stars.
     */
    public function updatingDuprRating(mixed $value): void
    {
        $hadRating = trim($this->dupr_rating) !== '';
        $hasRating = trim((string) $value) !== '';

        if (! $hadRating && $hasRating) {
            $this->rating_source = RatingSource::Dupr->value;
        } elseif (! $hasRating) {
            $this->rating_source = RatingSource::Manual->value;
        }
    }

    /**
     * @return LengthAwarePaginator<int, Player>
     */
    #[Computed]
    public function players(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return $this->club->players()
            ->when($this->status === 'active', fn ($q) => $q->where('active', true))
            ->when($this->status === 'inactive', fn ($q) => $q->where('active', false))
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.addcslashes($term, '\\%_').'%';
                $q->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('dupr_id', 'like', $like));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    /**
     * Stars that a DUPR-sourced rating would produce with the club's bands.
     */
    #[Computed]
    public function previewStars(): ?int
    {
        $rating = trim($this->dupr_rating);

        if ($this->rating_source !== RatingSource::Dupr->value || ! is_numeric($rating)) {
            return null;
        }

        $value = (float) $rating;
        if ($value < (float) config('pickleq.rating_min') || $value > (float) config('pickleq.rating_max')) {
            return null;
        }

        return StarRating::fromRating($value, $this->club->star_bands);
    }

    public function startAdd(): void
    {
        $this->authorize('create', [Player::class, $this->club]);

        $this->resetForm();
        Flux::modal('player-form')->show();
    }

    public function startEdit(int $playerId): void
    {
        $player = $this->club->players()->findOrFail($playerId);
        $this->authorize('update', $player);

        $this->resetForm();
        $this->editingId = $player->id;
        $this->name = $player->name;
        $this->nickname = $player->nickname ?? '';
        $this->dupr_id = $player->dupr_id ?? '';
        $this->dupr_rating = $player->dupr_rating !== null ? rtrim(rtrim($player->dupr_rating, '0'), '.') : '';
        $this->rating_source = $player->rating_source->value;
        $this->stars = (string) $player->stars;

        Flux::modal('player-form')->show();
    }

    public function save(PlayerService $players): void
    {
        $player = null;
        if ($this->editingId !== null) {
            $player = $this->club->players()->findOrFail($this->editingId);
            $this->authorize('update', $player);
        } else {
            $this->authorize('create', [Player::class, $this->club]);
        }

        $data = (new PlayerInput)->validate(
            $this->club,
            $player,
            $this->name,
            $this->dupr_id,
            $this->dupr_rating,
            $this->rating_source,
            $this->stars,
            $this->nickname,
        );

        if ($player !== null) {
            $players->update($player, $data);
            $message = __(':name updated.', ['name' => $data['name']]);
        } else {
            $players->create($this->club, $data);
            $message = __(':name added.', ['name' => $data['name']]);
        }

        Flux::modal('player-form')->close();
        $this->resetForm();
        unset($this->players);
        Flux::toast(variant: 'success', text: $message);
    }

    public function deactivate(int $playerId, PlayerService $players): void
    {
        $player = $this->club->players()->findOrFail($playerId);
        $this->authorize('deactivate', $player);

        $players->deactivate($player);

        unset($this->players);
        Flux::toast(variant: 'success', text: __(':name deactivated.', ['name' => $player->name]));
    }

    public function reactivate(int $playerId, PlayerService $players): void
    {
        $player = $this->club->players()->findOrFail($playerId);
        $this->authorize('reactivate', $player);

        $players->reactivate($player);

        unset($this->players);
        Flux::toast(variant: 'success', text: __(':name reactivated.', ['name' => $player->name]));
    }

    public function startImport(): void
    {
        $this->authorize('create', [Player::class, $this->club]);

        $this->resetImport();
        Flux::modal('roster-import')->show();
    }

    /**
     * Step 1: read the uploaded file and build the preview (nothing is saved).
     */
    public function previewImport(RosterImportService $import, RosterImportPreviewStore $store): void
    {
        $this->authorize('create', [Player::class, $this->club]);

        $maxKb = (int) ceil((int) config('pickleq.import_max_bytes') / 1024);
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:csv,txt', 'max:'.$maxKb],
        ], attributes: ['importFile' => __('file')]);

        $this->importResult = null;
        $this->importErrorsOnly = false;
        $this->importExpired = false;
        $this->forgetPreview($store);

        try {
            $preview = $import->preview($this->club, (string) $this->importFile?->getRealPath());
        } catch (ValidationException $e) {
            $this->addError('importFile', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->importPreviewId = $store->put($this->authUser(), $this->club, $preview);
        unset($this->preview);
    }

    /**
     * Step 2: apply the server-built preview.
     */
    public function commitImport(RosterImportService $import, RosterImportPreviewStore $store): void
    {
        $this->authorize('create', [Player::class, $this->club]);

        $preview = $this->preview;

        if ($preview === null) {
            return;
        }

        if ($preview->clubId !== $this->club->id || ! $preview->hasChanges()) {
            $this->addError('importFile', __('This preview cannot be imported. Upload the file again.'));

            return;
        }

        $result = $import->commit($this->club, $preview);

        $this->importResult = [
            'created' => $result->created,
            'updated' => $result->updated,
            'skipped' => $result->skipped,
            'errors' => $result->errors,
            'notes' => $result->notes,
        ];
        $this->forgetPreview($store);
        $this->importFile = null;
        unset($this->players, $this->preview);
        Flux::toast(variant: 'success', text: __('Import finished: :created added, :updated updated.', ['created' => $result->created, 'updated' => $result->updated]));
    }

    public function resetImport(): void
    {
        $this->forgetPreview(app(RosterImportPreviewStore::class));
        $this->reset('importFile', 'importResult', 'importErrorsOnly', 'importExpired');
        $this->resetErrorBag();
        unset($this->preview);
    }

    private function forgetPreview(RosterImportPreviewStore $store): void
    {
        if ($this->importPreviewId !== null) {
            $store->forget($this->authUser(), $this->club, $this->importPreviewId);
            $this->importPreviewId = null;
        }
    }

    private function authUser(): \App\Models\User
    {
        /** @var \App\Models\User */
        return auth()->user();
    }

    /**
     * The stored preview, or null when none / expired / not ours. An expired
     * preview drops back to the upload step with a notice.
     */
    #[Computed]
    public function preview(): ?RosterImportPreview
    {
        if ($this->importPreviewId === null) {
            return null;
        }

        $preview = app(RosterImportPreviewStore::class)->get($this->authUser(), $this->club, $this->importPreviewId);

        if ($preview === null) {
            $this->importPreviewId = null;
            $this->importExpired = true;
        }

        return $preview;
    }

    public function downloadSample(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('create', [Player::class, $this->club]);

        return response()->streamDownload(function (): void {
            echo "name,dupr_id,dupr_rating\nAna Lopez,8DPLX8,4.25\nBen Cruz,,3.5\nCara Dela Rosa,,\n";
        }, 'pickleq-roster-sample.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @return list<RosterImportRow>
     */
    #[Computed]
    public function importRows(): array
    {
        $preview = $this->preview;

        if ($preview === null) {
            return [];
        }

        $rows = $preview->rows;

        return $this->importErrorsOnly
            ? array_values(array_filter($rows, fn (RosterImportRow $r): bool => $r->action === RosterImportRow::ERROR))
            : $rows;
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'name', 'nickname', 'dupr_id', 'dupr_rating', 'stars');
        $this->rating_source = RatingSource::Manual->value;
        $this->resetErrorBag();
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Players') }}</flux:heading>
            <flux:subheading>{{ __('Your club roster. Players are deactivated, never deleted, so match history is kept.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="arrow-up-tray" wire:click="startImport" data-test="import-csv-button">
                {{ __('Import CSV') }}
            </flux:button>

            <flux:button variant="primary" icon="plus" wire:click="startAdd" data-test="add-player-button">
                {{ __('Add player') }}
            </flux:button>
        </div>
    </div>

    <div class="flex flex-col gap-3 sm:flex-row">
        <div class="flex-1">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                type="search"
                :placeholder="__('Search by name or DUPR ID')"
                :aria-label="__('Search players')"
                clearable
            />
        </div>
        <flux:select wire:model.live="status" class="sm:!w-44" :aria-label="__('Filter players')">
            <option value="active">{{ __('Active') }}</option>
            <option value="inactive">{{ __('Inactive') }}</option>
            <option value="all">{{ __('All players') }}</option>
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full min-w-[40rem] text-sm" data-test="players-table">
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr class="text-start">
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('Name') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('DUPR ID') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('Rating') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('Stars') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('Source') }}</th>
                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('Status') }}</th>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->players as $player)
                    <tr wire:key="player-{{ $player->id }}" @class(['text-zinc-500' => ! $player->active])>
                        <td class="px-4 py-3 font-medium">
                            {{ $player->name }}
                            @if ($player->nickname)
                                <span class="font-normal text-zinc-500" data-test="player-nickname">"{{ $player->nickname }}"</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono">{{ $player->dupr_id ?? '–' }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ $player->dupr_rating !== null ? number_format((float) $player->dupr_rating, 2) : '–' }}</td>
                        <td class="px-4 py-3"><x-star-rating :stars="$player->stars" /></td>
                        <td class="px-4 py-3">
                            <flux:badge size="sm" :color="$player->rating_source === RatingSource::Dupr ? 'blue' : 'zinc'">
                                {{ $player->rating_source === RatingSource::Dupr ? 'DUPR' : __('Manual') }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge size="sm" :color="$player->active ? 'green' : 'zinc'">
                                {{ $player->active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-1">
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="startEdit({{ $player->id }})" :aria-label="__('Edit :name', ['name' => $player->name])" data-test="edit-player-button" />
                                @if ($player->active)
                                    <flux:button size="sm" variant="subtle" wire:click="deactivate({{ $player->id }})" data-test="deactivate-player-button">{{ __('Deactivate') }}</flux:button>
                                @else
                                    <flux:button size="sm" variant="subtle" wire:click="reactivate({{ $player->id }})" data-test="reactivate-player-button">{{ __('Reactivate') }}</flux:button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-10 text-center text-zinc-500" data-test="players-empty">
                            {{ $search !== '' || $status !== 'active' ? __('No players match your filters.') : __('No players yet. Add your first player to get started.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->players->links() }}

    {{-- Roster CSV import --}}
    <flux:modal name="roster-import" class="w-full max-w-3xl" x-on:close="$wire.resetImport()">
        <div class="space-y-6" data-test="roster-import">
            <div>
                <flux:heading size="lg">{{ __('Import players from CSV') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Upload a .csv or .txt file with this header row:') }}
                    <code class="rounded bg-zinc-100 px-1 py-0.5 font-mono text-sm dark:bg-zinc-800">name,dupr_id,dupr_rating</code>
                </flux:text>
                <flux:text size="sm" class="mt-1">
                    {{ __('Only name is required. Players are matched by DUPR ID, then by name. Blank values never erase existing data. Up to :rows rows and :size.', ['rows' => config('pickleq.import_max_rows'), 'size' => number_format(config('pickleq.import_max_bytes') / 1048576, 0).' MB']) }}
                    <button type="button" wire:click="downloadSample" class="font-medium underline" data-test="download-sample">{{ __('Download a sample CSV') }}</button>
                </flux:text>
            </div>

            @php($preview = $importResult === null ? $this->preview : null)

            @if ($this->importExpired && $preview === null && $importResult === null)
                <flux:callout variant="warning" icon="exclamation-triangle" data-test="import-expired">
                    <flux:callout.text>{{ __('This preview expired; upload the file again.') }}</flux:callout.text>
                </flux:callout>
            @endif

            @if ($importResult !== null)
                <div class="space-y-3" data-test="import-result">
                    <flux:callout variant="success" icon="check-circle">
                        <flux:callout.heading>{{ __('Import finished') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __(':created added, :updated updated, :skipped unchanged, :errors left out.', ['created' => $importResult['created'], 'updated' => $importResult['updated'], 'skipped' => $importResult['skipped'], 'errors' => $importResult['errors']]) }}
                        </flux:callout.text>
                    </flux:callout>
                    @if ($importResult['notes'] !== [])
                        <ul class="list-disc space-y-1 ps-5 text-sm" data-test="import-notes">
                            @foreach ($importResult['notes'] as $note)
                                <li>{{ $note }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="flex justify-end gap-2">
                        <flux:button wire:click="resetImport" data-test="import-another">{{ __('Import another file') }}</flux:button>
                        <flux:modal.close><flux:button variant="primary">{{ __('Done') }}</flux:button></flux:modal.close>
                    </div>
                </div>
            @elseif ($preview === null)
                <form wire:submit="previewImport" class="space-y-4" data-test="import-upload-form">
                    <flux:input type="file" wire:model="importFile" accept=".csv,.txt,text/csv,text/plain" :label="__('CSV file')" data-test="import-file" />
                    @error('importFile')
                        <flux:callout variant="danger" icon="exclamation-triangle" data-test="import-error">
                            <flux:callout.text>{{ $message }}</flux:callout.text>
                        </flux:callout>
                    @enderror
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button type="button" variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="importFile,previewImport" data-test="import-preview-button">{{ __('Preview import') }}</flux:button>
                    </div>
                </form>
            @else
                <div class="space-y-4" data-test="import-preview">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @foreach ([
                            'create' => [__('New'), 'green'],
                            'update' => [__('Updates'), 'blue'],
                            'skip' => [__('Unchanged'), 'zinc'],
                            'error' => [__('Errors'), 'red'],
                        ] as $action => [$label, $color])
                            <div class="rounded-lg border border-zinc-200 p-3 text-center dark:border-zinc-700" data-test="count-{{ $action }}">
                                <div class="text-2xl font-semibold tabular-nums">{{ $preview->count($action) }}</div>
                                <flux:badge size="sm" :color="$color">{{ $label }}</flux:badge>
                            </div>
                        @endforeach
                    </div>

                    @if ($preview->hasErrors())
                        <flux:switch wire:model.live="importErrorsOnly" :label="__('Show only rows with errors')" data-test="errors-only" />
                    @endif

                    <div class="max-h-80 overflow-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full min-w-[34rem] text-sm" data-test="import-rows">
                            <thead class="sticky top-0 bg-zinc-50 dark:bg-zinc-900">
                                <tr>
                                    <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Line') }}</th>
                                    <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Action') }}</th>
                                    <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Name') }}</th>
                                    <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('DUPR ID') }}</th>
                                    <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Rating') }}</th>
                                    <th scope="col" class="px-3 py-2 text-start font-medium">{{ __('Notes') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($this->importRows as $row)
                                    <tr wire:key="import-row-{{ $row->line }}" data-action="{{ $row->action }}">
                                        <td class="px-3 py-2 tabular-nums">{{ $row->line }}</td>
                                        <td class="px-3 py-2">
                                            <flux:badge size="sm" :color="match ($row->action) { 'create' => 'green', 'update' => 'blue', 'error' => 'red', default => 'zinc' }">
                                                {{ match ($row->action) { 'create' => __('New'), 'update' => __('Update'), 'error' => __('Error'), default => __('Unchanged') } }}
                                            </flux:badge>
                                        </td>
                                        <td class="px-3 py-2 font-medium">{{ $row->data['name'] }}</td>
                                        <td class="px-3 py-2 font-mono">{{ $row->data['dupr_id'] ?? '–' }}</td>
                                        <td class="px-3 py-2 tabular-nums">{{ $row->data['dupr_rating'] ?? '–' }}</td>
                                        <td class="px-3 py-2 text-zinc-600 dark:text-zinc-400">{{ implode(' ', $row->messages) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @error('importFile')
                        <flux:callout variant="danger" icon="exclamation-triangle" data-test="import-error">
                            <flux:callout.text>{{ $message }}</flux:callout.text>
                        </flux:callout>
                    @enderror

                    @if ($preview->hasErrors())
                        <flux:text size="sm">{{ __('Rows with errors are left out; everything else can still be imported.') }}</flux:text>
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:button wire:click="resetImport" data-test="import-back">{{ __('Choose another file') }}</flux:button>
                        <flux:button variant="primary" wire:click="commitImport" :disabled="! $preview->hasChanges()" wire:loading.attr="disabled" wire:target="commitImport" data-test="import-confirm">
                            {{ __('Import :n players', ['n' => $preview->count('create') + $preview->count('update')]) }}
                        </flux:button>
                    </div>
                </div>
            @endif
        </div>
    </flux:modal>

    {{-- Add / edit modal --}}
    <flux:modal name="player-form" class="w-full max-w-lg" focusable>
        <form wire:submit="save" class="space-y-6" data-test="player-form">
            <flux:heading size="lg">{{ $editingId === null ? __('Add player') : __('Edit player') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required maxlength="120" autocomplete="off" />

            <flux:input
                wire:model="nickname"
                :label="__('Nickname (optional)')"
                :description="__('Shown on the public queue and TV instead of the full name. Unique in the club.')"
                maxlength="20"
                autocomplete="off"
                data-test="nickname-input"
            />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input
                    wire:model.blur="dupr_id"
                    :label="__('DUPR ID (optional)')"
                    maxlength="6"
                    autocomplete="off"
                    class="uppercase"
                    placeholder="8DPLX8"
                />
                <flux:input
                    wire:model.live.debounce.300ms="dupr_rating"
                    :label="__('DUPR rating (optional)')"
                    type="number"
                    step="0.001"
                    min="2"
                    max="8"
                    inputmode="decimal"
                    placeholder="3.750"
                />
            </div>

            @php($hasRating = trim($dupr_rating) !== '')
            <flux:radio.group wire:model.live="rating_source" :label="__('Stars source')" data-test="stars-source">
                <flux:radio value="dupr" :label="__('From DUPR rating')" :disabled="! $hasRating" />
                <flux:radio value="manual" :label="__('Manual')" />
            </flux:radio.group>

            @if ($hasRating && $rating_source === 'dupr')
                <div class="flex items-center gap-2" data-test="stars-preview">
                    <flux:text>{{ __('Stars with this club\'s bands:') }}</flux:text>
                    @if ($this->previewStars !== null)
                        <x-star-rating :stars="$this->previewStars" />
                    @else
                        <flux:text>{{ __('Enter a rating between 2.0 and 8.0.') }}</flux:text>
                    @endif
                </div>
            @else
                <flux:select wire:model="stars" :label="__('Stars')" data-test="manual-stars">
                    <option value="">{{ __('Choose stars') }}</option>
                    @foreach (range(1, 6) as $n)
                        <option value="{{ $n }}">{{ str_repeat('★', $n) }} ({{ $n }})</option>
                    @endforeach
                </flux:select>
                @unless ($hasRating)
                    <flux:text size="sm">{{ __('Players without a DUPR rating need their stars set by hand.') }}</flux:text>
                @endunless
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-player-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
