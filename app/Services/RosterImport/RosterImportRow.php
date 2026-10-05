<?php

namespace App\Services\RosterImport;

/**
 * One CSV data row after validation and matching. Serializable via
 * toArray()/fromArray() so a preview can be kept between steps (in the cache, via RosterImportPreviewStore).
 */
final readonly class RosterImportRow
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const SKIP = 'skip';

    public const ERROR = 'error';

    /**
     * @param  int  $line  CSV record number; the header is record 1, so the first data row is 2.
     * @param  'create'|'update'|'skip'|'error'  $action
     * @param  list<string>  $messages  Why the row is an error or skip (empty otherwise).
     * @param  array{name: string, dupr_id: string|null, dupr_rating: string|null}  $data  Normalized values; rating is a 3-decimal string.
     * @param  int|null  $playerId  The matched existing player (update and skip rows).
     */
    public function __construct(
        public int $line,
        public string $action,
        public array $messages,
        public array $data,
        public ?int $playerId = null,
    ) {}

    /**
     * @return array{line: int, action: string, messages: list<string>, data: array{name: string, dupr_id: string|null, dupr_rating: string|null}, player_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'action' => $this->action,
            'messages' => $this->messages,
            'data' => $this->data,
            'player_id' => $this->playerId,
        ];
    }

    /**
     * @param  array{line: int, action: 'create'|'update'|'skip'|'error', messages: list<string>, data: array{name: string, dupr_id: string|null, dupr_rating: string|null}, player_id: int|null}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['line'], $row['action'], $row['messages'], $row['data'], $row['player_id']);
    }
}
