<?php

namespace App\Services\RosterImport;

/**
 * The result of the preview step: every data row with its planned action.
 * Bound to one club; commit() refuses a preview built for another club.
 */
final readonly class RosterImportPreview
{
    /**
     * @param  list<RosterImportRow>  $rows
     */
    public function __construct(
        public int $clubId,
        public array $rows,
    ) {}

    public function count(string $action): int
    {
        return count(array_filter($this->rows, fn (RosterImportRow $r): bool => $r->action === $action));
    }

    public function hasErrors(): bool
    {
        return $this->count(RosterImportRow::ERROR) > 0;
    }

    /** True when at least one row would create or update a player. */
    public function hasChanges(): bool
    {
        return $this->count(RosterImportRow::CREATE) + $this->count(RosterImportRow::UPDATE) > 0;
    }

    /**
     * @return array{club_id: int, rows: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'club_id' => $this->clubId,
            'rows' => array_map(fn (RosterImportRow $r): array => $r->toArray(), $this->rows),
        ];
    }

    /**
     * @param  array{club_id: int, rows: list<array{line: int, action: 'create'|'update'|'skip'|'error', messages: list<string>, data: array{name: string, dupr_id: string|null, dupr_rating: string|null}, player_id: int|null}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['club_id'], array_map(RosterImportRow::fromArray(...), $data['rows']));
    }
}
