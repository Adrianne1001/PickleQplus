<?php

namespace App\Services;

use App\Enums\Gender;
use App\Models\Club;
use App\Models\Player;
use App\Rules\DuprPlayerId;
use App\Services\RosterImport\RosterImportPreview;
use App\Services\RosterImport\RosterImportResult;
use App\Services\RosterImport\RosterImportRow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Roster CSV import in two steps: preview() reads and plans, commit() applies.
 *
 * File format: UTF-8 (BOM allowed), header row with `name`, `dupr_id`,
 * `dupr_rating` and an optional `gender` (man, woman, m, w, male, female, f, any
 * case) matched case-insensitively in any order; only `name` is required; extra columns are ignored. Blank lines are ignored.
 *
 * Matching is always inside the given club: by DUPR ID first, then by
 * case-insensitive trimmed name (names are unique per club).
 * A blank dupr_id, dupr_rating or gender in the file never clears existing values.
 * An invalid gender makes the row an error.
 *
 * Stars: a row with a rating is `dupr` sourced (stars from the club bands,
 * unless an existing player is on a manual override). A new player without a
 * rating is `manual` with config('pickleq.import_default_stars') stars; an
 * existing player without a rating in the file keeps their stars.
 */
class RosterImportService
{
    public const INACTIVE_MATCH = 'Matches an inactive player; they will stay inactive.';

    /** Longest text kept on an error row, so a pathological file cannot bloat the preview. */
    private const ERROR_TEXT_MAX = 120;

    public function __construct(private readonly PlayerService $players) {}

    /**
     * @throws ValidationException (key "file") when the file as a whole is unusable.
     */
    public function preview(Club $club, UploadedFile|string $path): RosterImportPreview
    {
        $records = $this->readRecords($path);

        $header = array_shift($records);
        $columns = $this->mapHeader($header['cells']);

        /** @var list<Player> $existing */
        $existing = $club->players()->get()->all();
        $byDuprId = [];
        $byName = [];
        foreach ($existing as $player) {
            if ($player->dupr_id !== null) {
                $byDuprId[$player->dupr_id] = $player;
            }
            $byName[$this->nameKey($player->name)][] = $player;
        }

        $rows = [];
        $seenDuprIds = [];
        $seenNames = [];
        $targeted = [];

        foreach ($records as $record) {
            $line = $record['line'];
            $raw = [
                'name' => $this->cell($record['cells'], $columns['name'] ?? null),
                'dupr_id' => $this->cell($record['cells'], $columns['dupr_id'] ?? null),
                'dupr_rating' => $this->cell($record['cells'], $columns['dupr_rating'] ?? null),
            ];

            $rawGender = $this->cell($record['cells'], $columns['gender'] ?? null);
            $gender = Gender::tryParse($rawGender);

            $duprId = DuprPlayerId::normalize($raw['dupr_id']);
            $rating = $raw['dupr_rating'] === '' ? null : $raw['dupr_rating'];
            $data = [
                'name' => $raw['name'],
                'dupr_id' => $duprId,
                'dupr_rating' => is_numeric($rating) ? number_format((float) $rating, 3, '.', '') : $rating,
            ];

            $errors = $this->validateRow($raw['name'], $duprId, $rating);
            if ($rawGender !== '' && $gender === null) {
                $errors[] = 'Gender "'.$this->limit($rawGender).'" is not valid. Use man or woman (m, w, male, female, f).';
            }
            $genderValue = $gender?->value;

            // In-file duplicates.
            if ($errors === [] && $duprId !== null && isset($seenDuprIds[$duprId])) {
                $errors[] = "Duplicate DUPR ID (also on line {$seenDuprIds[$duprId]}).";
            }
            $nameKey = $this->nameKey($raw['name']);
            if ($errors === [] && isset($seenNames[$nameKey])) {
                // Names are unique per club, so a repeated name is always a duplicate.
                $errors[] = "Duplicate name (also on line {$seenNames[$nameKey]}).";
            }

            if ($raw['name'] !== '' && ! isset($seenNames[$nameKey])) {
                $seenNames[$nameKey] = $line;
            }
            if ($duprId !== null && $errors === []) {
                $seenDuprIds[$duprId] = $line;
            }

            if ($errors !== []) {
                $rows[] = new RosterImportRow($line, RosterImportRow::ERROR, $errors, $this->truncated($data), null, $genderValue);

                continue;
            }

            /** @var array{name: string, dupr_id: string|null, dupr_rating: string|null} $data */
            $match = null;
            if ($duprId !== null && isset($byDuprId[$duprId])) {
                $match = $byDuprId[$duprId];
            } else {
                $named = $byName[$nameKey] ?? [];
                $match = $named[0] ?? null;

                // A name match must never overwrite a different DUPR ID: it could be another person.
                if ($match !== null && $duprId !== null && $match->dupr_id !== null) {
                    $rows[] = new RosterImportRow($line, RosterImportRow::ERROR, ['Name matches existing player with a different DUPR ID.'], $data, $match->id, $genderValue);

                    continue;
                }
            }

            if ($match === null) {
                $rows[] = new RosterImportRow($line, RosterImportRow::CREATE, [], $data, null, $genderValue);

                continue;
            }

            // Names are unique per club: renaming a DUPR-matched player onto another player's name is a conflict.
            foreach ($byName[$nameKey] ?? [] as $other) {
                if ($other->id !== $match->id) {
                    $rows[] = new RosterImportRow($line, RosterImportRow::ERROR, ['Another player in this club already has this name.'], $data, $match->id, $genderValue);

                    continue 2;
                }
            }

            if (isset($targeted[$match->id])) {
                $rows[] = new RosterImportRow($line, RosterImportRow::ERROR, ["Matches the same player as line {$targeted[$match->id]}."], $data, $match->id, $genderValue);

                continue;
            }
            $targeted[$match->id] = $line;

            $notes = $match->active ? [] : [self::INACTIVE_MATCH];
            $rows[] = $this->changes($match, $data, $genderValue) === []
                ? new RosterImportRow($line, RosterImportRow::SKIP, ['No changes.', ...$notes], $data, $match->id, $genderValue)
                : new RosterImportRow($line, RosterImportRow::UPDATE, $notes, $data, $match->id, $genderValue);
        }

        return new RosterImportPreview($club->id, $rows);
    }

    /**
     * Apply the valid rows of a preview in one transaction. The club row is
     * locked first, so concurrent imports and edits serialize, and the state
     * the preview relied on is re-read in one prefetch: a row whose DUPR ID or
     * name was taken since the preview (or that lost a race) is counted as an
     * error with a note instead of failing the import.
     */
    public function commit(Club $club, RosterImportPreview $preview): RosterImportResult
    {
        if ($preview->clubId !== $club->id) {
            throw new InvalidArgumentException('This preview belongs to a different club.');
        }

        return DB::transaction(function () use ($club, $preview): RosterImportResult {
            // Take the club lock before the prefetch, so the prefetch reads
            // everything committed by whoever held the lock before us.
            Club::query()->whereKey($club->id)->lockForUpdate()->first();

            [$byId, $byDuprId, $names] = $this->prefetch($club, $preview);

            $created = $updated = $skipped = $errors = 0;
            $notes = [];

            foreach ($preview->rows as $row) {
                $outcome = match ($row->action) {
                    RosterImportRow::SKIP => 'skipped',
                    RosterImportRow::CREATE => $this->commitCreate($club, $row, $byDuprId, $names),
                    RosterImportRow::UPDATE => $this->commitUpdate($row, $byId, $byDuprId),
                    default => null,
                };

                if ($outcome === 'created') {
                    $created++;
                } elseif ($outcome === 'updated') {
                    $updated++;
                } elseif ($outcome === 'skipped') {
                    $skipped++;
                } else {
                    $errors++;
                    if ($outcome !== null) {
                        $notes[] = "Line {$row->line}: {$outcome}";
                    }
                }
            }

            return new RosterImportResult($created, $updated, $skipped, $errors, $notes);
        });
    }

    /**
     * The club's players that the preview refers to, in one query.
     *
     * @return array{0: array<int, Player>, 1: array<string, Player>, 2: array<string, true>} by id, by DUPR ID, and the taken name keys of the CREATE rows
     */
    private function prefetch(Club $club, RosterImportPreview $preview): array
    {
        $ids = [];
        $duprIds = [];
        $createNames = [];
        foreach ($preview->rows as $row) {
            if ($row->action !== RosterImportRow::CREATE && $row->action !== RosterImportRow::UPDATE) {
                continue;
            }
            if ($row->playerId !== null) {
                $ids[] = $row->playerId;
            }
            if ($row->data['dupr_id'] !== null) {
                $duprIds[] = $row->data['dupr_id'];
            }
            if ($row->action === RosterImportRow::CREATE) {
                $createNames[] = $this->nameKey($row->data['name']);
            }
        }

        $byId = [];
        $byDuprId = [];
        $names = [];

        if ($createNames !== []) {
            $createNames = array_values(array_unique($createNames));
            $placeholders = implode(',', array_fill(0, count($createNames), '?'));

            foreach ($club->players()->whereRaw("lower(name) in ({$placeholders})", $createNames)->pluck('name') as $name) {
                $names[$this->nameKey($name)] = true;
            }
        }

        if ($ids === [] && $duprIds === []) {
            return [$byId, $byDuprId, $names];
        }

        $players = $club->players()
            ->where(function ($query) use ($ids, $duprIds): void {
                $query->whereIn('id', array_values(array_unique($ids)))
                    ->orWhereIn('dupr_id', array_values(array_unique($duprIds)));
            })
            ->get();

        foreach ($players as $player) {
            $player->setRelation('club', $club);
            $byId[$player->id] = $player;
            if ($player->dupr_id !== null) {
                $byDuprId[$player->dupr_id] = $player;
            }
        }

        return [$byId, $byDuprId, $names];
    }

    /**
     * @param  array<string, Player>  $byDuprId
     * @param  array<string, true>  $names  name keys of existing players, for the CREATE rows
     * @return string 'created', or a note saying why the row was not applied
     */
    private function commitCreate(Club $club, RosterImportRow $row, array &$byDuprId, array $names): string
    {
        if (isset($names[$this->nameKey($row->data['name'])])) {
            return 'a player with this name was added since the preview.';
        }

        $duprId = $row->data['dupr_id'];
        if ($duprId !== null && isset($byDuprId[$duprId])) {
            return 'DUPR ID is now used by another player.';
        }

        $new = [
            'name' => $row->data['name'],
            'dupr_id' => $duprId,
            'dupr_rating' => $row->data['dupr_rating'],
        ];
        if ($row->gender !== null) {
            $new['gender'] = $row->gender;
        }
        // Stars are derived from the rating; only unrated players get the default.
        if ($row->data['dupr_rating'] === null) {
            $new['stars'] = (int) config('pickleq.import_default_stars');
        }

        // PlayerService wraps each save in its own transaction (a savepoint here),
        // so a failure rolls back just this row.
        try {
            $player = $this->players->create($club, $new);
        } catch (ValidationException|UniqueConstraintViolationException $e) {
            return $this->failureNote($e);
        }

        if ($player->dupr_id !== null) {
            $byDuprId[$player->dupr_id] = $player;
        }

        return 'created';
    }

    /**
     * @param  array<int, Player>  $byId
     * @param  array<string, Player>  $byDuprId
     * @return string 'updated' or 'skipped', or a note saying why the row was not applied
     */
    private function commitUpdate(RosterImportRow $row, array $byId, array &$byDuprId): string
    {
        $player = $row->playerId === null ? null : ($byId[$row->playerId] ?? null);
        if (! $player instanceof Player) {
            return 'the matched player no longer exists.';
        }

        $changes = $this->changes($player, $row->data, $row->gender);
        if ($changes === []) {
            return 'skipped';
        }

        if (isset($changes['dupr_id'])) {
            if ($player->dupr_id !== null) {
                return 'name matches existing player with a different DUPR ID.';
            }
            if (isset($byDuprId[$changes['dupr_id']]) && $byDuprId[$changes['dupr_id']]->id !== $player->id) {
                return 'DUPR ID is now used by another player.';
            }
        }

        try {
            $this->players->update($player, $changes);
        } catch (ValidationException|UniqueConstraintViolationException $e) {
            return $this->failureNote($e);
        }

        if ($player->dupr_id !== null) {
            $byDuprId[$player->dupr_id] = $player;
        }

        return 'updated';
    }

    private function failureNote(ValidationException|UniqueConstraintViolationException $e): string
    {
        if ($e instanceof ValidationException) {
            $first = collect($e->errors())->flatten()->first();

            return is_string($first) ? $first : 'the row could not be saved.';
        }

        return 'the row conflicts with an existing player.';
    }

    /**
     * Cap the text kept on an error row (the original may be up to 1 MB).
     *
     * @param  array{name: string, dupr_id: string|null, dupr_rating: string|null}  $data
     * @return array{name: string, dupr_id: string|null, dupr_rating: string|null}
     */
    private function truncated(array $data): array
    {
        return [
            'name' => $this->limit($data['name']),
            'dupr_id' => $data['dupr_id'] === null ? null : $this->limit($data['dupr_id']),
            'dupr_rating' => $data['dupr_rating'] === null ? null : $this->limit($data['dupr_rating']),
        ];
    }

    private function limit(string $value): string
    {
        return mb_strlen($value) > self::ERROR_TEXT_MAX
            ? mb_substr($value, 0, self::ERROR_TEXT_MAX - 1).'…'
            : $value;
    }

    /**
     * The attributes that would change on an existing player. Blank file
     * values never clear anything; a name that differs only by case or
     * surrounding whitespace is not a change.
     *
     * @param  array{name: string, dupr_id: string|null, dupr_rating: string|null}  $data
     * @return array{name?: string, dupr_id?: string, dupr_rating?: string, gender?: string}
     */
    private function changes(Player $player, array $data, ?string $gender = null): array
    {
        $changes = [];

        if ($this->nameKey($player->name) !== $this->nameKey($data['name'])) {
            $changes['name'] = $data['name'];
        }
        if ($data['dupr_id'] !== null && $data['dupr_id'] !== $player->dupr_id) {
            $changes['dupr_id'] = $data['dupr_id'];
        }
        if ($data['dupr_rating'] !== null && (
            $player->dupr_rating === null
            || number_format((float) $player->dupr_rating, 3, '.', '') !== $data['dupr_rating']
        )) {
            $changes['dupr_rating'] = $data['dupr_rating'];
        }
        if ($gender !== null && $gender !== $player->gender?->value) {
            $changes['gender'] = $gender;
        }

        return $changes;
    }

    /**
     * @return list<string>
     */
    private function validateRow(string $name, ?string $duprId, ?string $rating): array
    {
        $min = (float) config('pickleq.rating_min');
        $max = (float) config('pickleq.rating_max');

        $validator = Validator::make(
            ['name' => $name, 'dupr_id' => $duprId, 'dupr_rating' => $rating],
            [
                'name' => ['required', 'string', 'max:120'],
                'dupr_id' => ['nullable', 'string', new DuprPlayerId],
                'dupr_rating' => ['nullable', 'numeric', "between:{$min},{$max}", 'decimal:0,3'],
            ],
            [
                'name.required' => 'Name is required.',
                'name.max' => 'Name is too long (120 characters max).',
                'dupr_id' => 'DUPR ID must be 6 letters or digits.',
                'dupr_rating.numeric' => 'DUPR rating must be a number.',
                'dupr_rating.between' => "DUPR rating must be between {$min} and {$max}.",
                'dupr_rating.decimal' => 'DUPR rating can have at most 3 decimals.',
            ],
        );

        return array_values($validator->errors()->all());
    }

    /**
     * Read the file into records with their record numbers (header is 1).
     * Blank lines are dropped.
     *
     * @return non-empty-list<array{line: int, cells: list<string|null>}>
     */
    private function readRecords(UploadedFile|string $path): array
    {
        $file = $path instanceof UploadedFile ? $path->getRealPath() : $path;

        if (! is_string($file) || ! is_file($file) || ! is_readable($file)) {
            throw $this->fileError('The file could not be read.');
        }

        $size = filesize($file);
        if ($size === false || $size > (int) config('pickleq.import_max_bytes')) {
            throw $this->fileError('The file is larger than 1 MB.');
        }

        $contents = (string) file_get_contents($file);

        if (str_starts_with($contents, "\xFF\xFE") || str_starts_with($contents, "\xFE\xFF")) {
            throw $this->fileError('The file must be saved as UTF-8 (it looks like UTF-16).');
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            $contents = substr($contents, 3);
        }
        if (! mb_check_encoding($contents, 'UTF-8')) {
            throw $this->fileError('The file must be saved as UTF-8.');
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw $this->fileError('The file could not be read.');
        }
        fwrite($stream, $contents);
        rewind($stream);

        // Stream the records and stop as soon as the row limit is exceeded, so a
        // file of many tiny lines never builds a huge array.
        $maxRows = (int) config('pickleq.import_max_rows');
        $records = [];
        $line = 0;
        while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $line++;
            if ($cells === [null] || array_filter($cells, fn ($c): bool => $c !== null && trim($c) !== '') === []) {
                continue;
            }
            $records[] = ['line' => $line, 'cells' => $cells];

            // The header is the first record, so data rows = records - 1.
            if (count($records) - 1 > $maxRows) {
                fclose($stream);

                throw $this->fileError('The file has more than '.$maxRows.' rows.');
            }
        }
        fclose($stream);

        if ($records === []) {
            throw $this->fileError('The file is empty.');
        }

        return $records;
    }

    /**
     * @param  list<string|null>  $cells
     * @return array<string, int> column name => index
     */
    private function mapHeader(array $cells): array
    {
        $columns = [];
        foreach ($cells as $i => $cell) {
            $key = mb_strtolower(trim((string) $cell));
            if (! in_array($key, ['name', 'dupr_id', 'dupr_rating', 'gender'], true)) {
                continue;
            }
            if (isset($columns[$key])) {
                throw $this->fileError("The header has the column \"{$key}\" more than once.");
            }
            $columns[$key] = $i;
        }

        if (! isset($columns['name'])) {
            throw $this->fileError('The header row must include a "name" column (name,dupr_id,dupr_rating).');
        }

        return $columns;
    }

    /**
     * @param  list<string|null>  $cells
     */
    private function cell(array $cells, ?int $index): string
    {
        return $index === null ? '' : trim((string) ($cells[$index] ?? ''));
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    private function fileError(string $message): ValidationException
    {
        return ValidationException::withMessages(['file' => $message]);
    }
}
