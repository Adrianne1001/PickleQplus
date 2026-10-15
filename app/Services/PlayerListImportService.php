<?php

namespace App\Services;

use App\Domain\PlayerList\PastedPlayer;
use App\Domain\PlayerList\PasteListParser;
use App\Enums\Gender;
use App\Enums\ListSource;
use App\Events\PlaySessionChanged;
use App\Models\Club;
use App\Models\Player;
use App\Models\PlaySession;
use App\Models\SessionListPlayer;
use App\Models\User;
use App\Rules\DuprPlayerId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Import player list (Phase 16): paste or Reclub link -> review rows -> confirm.
 *
 * A review row is a plain array, so it can live in Livewire state. Only the editable fields
 * (`name`, `rating`, `dupr_id`, `gender`) and `source` are ever trusted: every review and the confirm re-run
 * the matching from them, so the other keys (status, player_id, message) can't be forged.
 *
 * Matching is inside the club: by DUPR ID first, then by case-insensitive name. The import never
 * changes an existing player's details, with one exception (P16.8): a row's gender is set on an
 * existing player who has none. A gender is never overwritten; a differing one is a row warning.
 *
 * A row's `gender` is '' (none), `man` or `woman`. Any other text is kept as typed so the row can
 * show the error "Gender must be man or woman." (the value is never written).
 *
 * Error keys: `paste`, `reclub` (parse / fetch problems), `players` (guest caps at confirm),
 * `session` (ended or expired).
 *
 * @phpstan-type InputRow array{line?: int, name?: mixed, rating?: mixed, dupr_id?: mixed, gender?: mixed, source?: mixed, warnings?: mixed}
 * @phpstan-type ReviewRow array{line: int, name: string, rating: string, dupr_id: string, gender: string, source: string, warnings: list<string>, status: 'existing'|'new'|'listed'|'error', player_id: int|null, player_name: string|null, message: string|null}
 * @phpstan-type ConfirmResult array{listed: int, created: int, already_listed: int, errors: int, notes: list<string>}
 * @phpstan-type Selection array{player_ids: list<array{id: int, source: string, gender: string}>, new_players: list<array{name: string, stars: null, dupr_id: string|null, dupr_rating: string|null, gender: string, source: string}>, already_listed: int, errors: int}
 */
class PlayerListImportService
{
    use LocksPlaySession;

    public const STATUS_EXISTING = 'existing';

    public const STATUS_NEW = 'new';

    public const STATUS_LISTED = 'listed';

    public const STATUS_ERROR = 'error';

    public const GENDER_ERROR = 'Gender must be man or woman.';

    /** Start of the computed warning for a gender that differs from the player's. Stripped from incoming rows, so it never piles up on re-reviews. */
    public const GENDER_WARNING = 'Gender differs: ';

    public function __construct(
        private readonly PlayerService $players,
        private readonly SessionListService $list,
        private readonly RosterImportService $roster,
        private readonly ReclubFetcher $reclub,
    ) {}

    /**
     * Parse pasted text and review it.
     *
     * @param  list<int>  $alsoListed  Players picked in a form but not on the session's list yet, counted as listed.
     * @return list<ReviewRow>
     *
     * @throws ValidationException key `paste` when the text as a whole is unusable
     */
    public function reviewPaste(Club $club, ?PlaySession $session, string $text, array $alsoListed = []): array
    {
        $parsed = PasteListParser::parse($text);
        if ($parsed->error !== null) {
            throw ValidationException::withMessages(['paste' => $parsed->error]);
        }

        return $this->review($club, $session, array_map(
            fn (PastedPlayer $p): array => [
                'line' => $p->line,
                'name' => $p->name,
                'rating' => $p->rating,
                'dupr_id' => $p->duprId,
                'gender' => $p->gender,
                'source' => ListSource::Paste->value,
                'warnings' => $p->warnings,
            ],
            $parsed->players,
        ), $alsoListed);
    }

    /**
     * Fetch a Reclub event link and review its confirmed players.
     *
     * @param  list<int>  $alsoListed
     * @return list<ReviewRow>
     *
     * @throws ValidationException key `reclub`
     */
    public function reviewReclub(Club $club, ?PlaySession $session, string $url, ?User $actor = null, array $alsoListed = []): array
    {
        $event = $this->reclub->fetch($url, $actor !== null ? "user:{$actor->id}" : "club:{$club->id}");

        $rows = [];
        foreach ($event->players as $i => $name) {
            $rows[] = ['line' => $i + 1, 'name' => $name, 'rating' => '', 'dupr_id' => '', 'gender' => '', 'source' => ListSource::Reclub->value, 'warnings' => []];
        }

        return $this->review($club, $session, $rows, $alsoListed);
    }

    /**
     * Match and validate every row (also the way to re-run it after edits or drops).
     *
     * @param  list<InputRow>  $rows
     * @param  list<int>  $alsoListed
     * @return list<ReviewRow>
     *
     * @throws ValidationException key `players` when there are more rows than a paste may hold (the rows
     *                             come from component state, which a client can forge)
     */
    public function review(Club $club, ?PlaySession $session, array $rows, array $alsoListed = []): array
    {
        if (count($rows) > PasteListParser::MAX_LINES) {
            throw ValidationException::withMessages(['players' => 'The list has more than '.PasteListParser::MAX_LINES.' players.']);
        }

        /** @var array<string, Player> $byName */
        $byName = [];
        /** @var array<string, Player> $byDuprId */
        $byDuprId = [];
        foreach (Player::query()->where('club_id', $club->id)->get(['id', 'club_id', 'name', 'dupr_id', 'gender', 'active']) as $player) {
            $byName[$this->nameKey($player->name)] ??= $player;
            if ($player->dupr_id !== null) {
                $byDuprId[$player->dupr_id] = $player;
            }
        }

        $listed = array_fill_keys($alsoListed, true);
        if ($session !== null && $session->club_id === $club->id) {
            foreach (SessionListPlayer::query()->where('play_session_id', $session->id)->pluck('player_id') as $id) {
                $listed[(int) $id] = true;
            }
        }

        $out = [];
        $seenNames = [];
        $seenDuprIds = [];
        foreach ($rows as $input) {
            $row = $this->normalised($input);
            $nameKey = $this->nameKey($row['name']);
            $duprId = DuprPlayerId::normalize($row['dupr_id']);

            $errors = $this->roster->validateRow($row['name'], $duprId, $row['rating'] === '' ? null : $row['rating']);
            if ($row['gender'] !== '' && Gender::tryParse($row['gender']) === null) {
                $errors[] = self::GENDER_ERROR;
            }
            if ($errors === [] && isset($seenNames[$nameKey])) {
                $errors[] = 'This name is repeated in the list.';
            }
            if ($errors === [] && $duprId !== null && isset($seenDuprIds[$duprId])) {
                $errors[] = 'This DUPR ID is used more than once in the list.';
            }
            if ($nameKey !== '') {
                $seenNames[$nameKey] = true;
            }
            if ($duprId !== null) {
                $seenDuprIds[$duprId] = true;
            }

            if ($errors !== []) {
                $out[] = $this->result($row, self::STATUS_ERROR, null, $errors[0]);

                continue;
            }

            $match = null;
            if ($duprId !== null && isset($byDuprId[$duprId])) {
                $match = $byDuprId[$duprId];
                if ($this->nameKey($match->name) !== $nameKey) {
                    $out[] = $this->result($row, self::STATUS_ERROR, $match, "That DUPR ID belongs to {$match->name}.");

                    continue;
                }
            } elseif (isset($byName[$nameKey])) {
                $match = $byName[$nameKey];
                if ($duprId !== null && $match->dupr_id !== null && $match->dupr_id !== $duprId) {
                    $out[] = $this->result($row, self::STATUS_ERROR, $match, 'A player with this name already has a different DUPR ID.');

                    continue;
                }
            }

            if ($match === null) {
                $out[] = $this->result($row, self::STATUS_NEW, null, null);

                continue;
            }

            if (! $match->active) {
                $out[] = $this->result($row, self::STATUS_ERROR, $match, 'This player is deactivated. Reactivate them on the Players page first.');

                continue;
            }

            // The player keeps their gender: a different one in the row is only a heads-up.
            if ($row['gender'] !== '' && $match->gender !== null && $match->gender->value !== $row['gender']) {
                $row['warnings'][] = self::GENDER_WARNING.'this player is already '.$match->gender->label().', so the gender in this row is ignored.';
            }

            $out[] = isset($listed[$match->id])
                ? $this->result($row, self::STATUS_LISTED, $match, 'Already on the list.')
                : $this->result($row, self::STATUS_EXISTING, $match, null);
        }

        return $out;
    }

    /**
     * Re-run the matching for one edited row, in the context of the other rows (a name repeated
     * among them is judged as if this row came last).
     *
     * @param  InputRow  $row
     * @param  list<InputRow>  $others
     * @param  list<int>  $alsoListed
     * @return ReviewRow
     */
    public function reviewRow(Club $club, ?PlaySession $session, array $row, array $others = [], array $alsoListed = []): array
    {
        $last = null;
        foreach ($this->review($club, $session, [...$others, $row], $alsoListed) as $reviewed) {
            $last = $reviewed;
        }

        return $last ?? throw new LogicException('A review always returns the row it was given.');
    }

    /**
     * Add the reviewed players to a session's list: re-validates every row on the server, then in
     * one transaction under the session lock creates the new players (3 stars and "skill not set"
     * without a rating, DUPR bands with one) and lists everyone. Error rows are skipped, not fatal;
     * a guest cap or an ended session fails the whole import.
     *
     * Callers authorize session `manage` and player `create`.
     *
     * @param  list<InputRow>  $rows
     * @return ConfirmResult
     *
     * @throws ValidationException
     */
    public function confirm(PlaySession $session, array $rows, ?User $actor = null): array
    {
        $this->guardGuestActive($session);

        return DB::transaction(function () use ($session, $rows, $actor): array {
            // Lock order club -> session, as PlaySessionService::start().
            $club = Club::query()->whereKey($session->club_id)->lockForUpdate()->firstOrFail();
            $this->guardClubNotExpired($club);
            $this->lockSession($session);
            if ($session->isEnded()) {
                throw ValidationException::withMessages(['session' => 'This session has ended.']);
            }

            $reviewed = $this->review($club, $session, $rows);

            $new = count(array_filter($reviewed, fn (array $r): bool => $r['status'] === self::STATUS_NEW));
            $toList = $new + count(array_filter($reviewed, fn (array $r): bool => $r['status'] === self::STATUS_EXISTING));
            if ($club->is_guest) {
                $maxRoster = (int) config('pickleq.guest.max_roster');
                if ($new > 0 && Player::query()->where('club_id', $club->id)->count() + $new > $maxRoster) {
                    throw ValidationException::withMessages(['players' => "A guest session can have at most {$maxRoster} players. Sign up for more."]);
                }
                $maxListed = (int) config('pickleq.guest.max_listed');
                if ($toList > 0 && SessionListPlayer::query()->where('play_session_id', $session->id)->count() + $toList > $maxListed) {
                    throw ValidationException::withMessages(['players' => "A guest session can list at most {$maxListed} players. Sign up for more."]);
                }
            }

            $picks = [];
            /** @var array<int, string> $genderFills player id => gender to set when the player has none */
            $genderFills = [];
            $created = 0;
            $alreadyListed = 0;
            $errors = 0;
            $notes = [];
            foreach ($reviewed as $row) {
                if ($row['status'] === self::STATUS_LISTED) {
                    $alreadyListed++;
                } elseif ($row['status'] === self::STATUS_ERROR) {
                    $errors++;
                } elseif ($row['status'] === self::STATUS_EXISTING && $row['player_id'] !== null) {
                    $picks[] = ['id' => $row['player_id'], 'source' => $row['source']];
                    if ($row['gender'] !== '') {
                        $genderFills[$row['player_id']] = $row['gender'];
                    }
                } elseif ($row['status'] === self::STATUS_NEW) {
                    try {
                        $player = $this->players->create($club, $this->newPlayerData($row));
                    } catch (ValidationException|UniqueConstraintViolationException $e) {
                        $errors++;
                        $notes[] = $row['name'].': '.$this->failureNote($e);

                        continue;
                    }
                    $created++;
                    $picks[] = ['id' => $player->id, 'source' => $row['source']];
                }
            }

            // A player deactivated since the review is skipped with a note, like any other bad row. The rows
            // are locked here, so the list write below sees the same state.
            if ($picks !== []) {
                $locked = Player::query()
                    ->where('club_id', $club->id)
                    ->whereIn('id', array_column($picks, 'id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $usable = [];
                foreach ($picks as $pick) {
                    $player = $locked->get($pick['id']);
                    if ($player === null || ! $player->active) {
                        $errors++;
                        $notes[] = ($player->name ?? 'A player').': is deactivated. Reactivate them on the Players page first.';

                        continue;
                    }
                    // Set, never overwrite: judged on the locked row, so a gender set since the review stays.
                    if ($player->gender === null && isset($genderFills[$player->id])) {
                        $this->players->update($player, ['gender' => $genderFills[$player->id]]);
                    }
                    $usable[] = $pick;
                }
                $picks = $usable;
            }

            $listed = $this->list->addLocked($session, $picks, $actor?->id);
            if ($listed > 0) {
                PlaySessionChanged::dispatch($session->id);
            }

            return ['listed' => $listed, 'created' => $created, 'already_listed' => $alreadyListed, 'errors' => $errors, 'notes' => $notes];
        });
    }

    /**
     * For the session create/edit forms, which keep the list in form state until Save: re-validate
     * the rows and split them into existing players to pick and new players to create on Save
     * (the shapes SessionFormService::save takes). Error and already-listed rows are left out.
     *
     * @param  list<InputRow>  $rows
     * @param  list<int>  $alsoListed
     * @return Selection
     */
    public function selection(Club $club, ?PlaySession $session, array $rows, array $alsoListed = []): array
    {
        $playerIds = [];
        $newPlayers = [];
        $listed = 0;
        $errors = 0;
        foreach ($this->review($club, $session, $rows, $alsoListed) as $row) {
            if ($row['status'] === self::STATUS_EXISTING && $row['player_id'] !== null) {
                // The gender is only a request: SessionFormService::save sets it when the player has none.
                $playerIds[] = ['id' => $row['player_id'], 'source' => $row['source'], 'gender' => $row['gender']];
            } elseif ($row['status'] === self::STATUS_NEW) {
                $data = $this->newPlayerData($row);
                $newPlayers[] = [
                    'name' => $row['name'],
                    'stars' => null,
                    'dupr_id' => $data['dupr_id'],
                    'dupr_rating' => isset($data['dupr_rating']) ? (string) $data['dupr_rating'] : null,
                    'gender' => $row['gender'],
                    'source' => $row['source'],
                ];
            } elseif ($row['status'] === self::STATUS_LISTED) {
                $listed++;
            } else {
                $errors++;
            }
        }

        return ['player_ids' => $playerIds, 'new_players' => $newPlayers, 'already_listed' => $listed, 'errors' => $errors];
    }

    /**
     * What PlayerService::create takes for a new row.
     *
     * @param  ReviewRow  $row
     * @return array{name: string, dupr_id: string|null, gender: string|null, dupr_rating?: string, stars?: int, stars_pending?: bool, rating_source?: string}
     */
    private function newPlayerData(array $row): array
    {
        $data = [
            'name' => $row['name'],
            'dupr_id' => DuprPlayerId::normalize($row['dupr_id']),
            'gender' => $row['gender'] === '' ? null : $row['gender'],
        ];

        if ($row['rating'] !== '') {
            // A pasted rating sets the DUPR rating; the stars come from the club's bands.
            $data['dupr_rating'] = number_format((float) $row['rating'], 3, '.', '');
        } else {
            $data['rating_source'] = 'manual';
            $data['stars'] = CheckInService::PENDING_STARS;
            $data['stars_pending'] = true;
        }

        return $data;
    }

    /**
     * The row as the client sent it, reduced to safe strings (it may have been tampered with).
     *
     * @return array{line: int, name: string, rating: string, dupr_id: string, gender: string, source: string, warnings: list<string>}
     */
    private function normalised(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $warnings = [];
        if (is_array($input['warnings'] ?? null)) {
            foreach ($input['warnings'] as $warning) {
                if (is_string($warning) && ! str_starts_with($warning, self::GENDER_WARNING)) {
                    $warnings[] = mb_substr($warning, 0, 200);
                }
            }
        }

        return [
            'line' => is_int($input['line'] ?? null) ? $input['line'] : 0,
            'name' => $this->text($input['name'] ?? null),
            'rating' => $this->text($input['rating'] ?? null),
            'dupr_id' => $this->text($input['dupr_id'] ?? null),
            'gender' => $this->gender($input['gender'] ?? null),
            'source' => ListSource::fromInput($input['source'] ?? null) === ListSource::Reclub ? ListSource::Reclub->value : ListSource::Paste->value,
            'warnings' => array_slice($warnings, 0, 10),
        ];
    }

    /**
     * '' for none, `man` or `woman` for a recognised value (any alias or case), else the text as typed
     * (capped), which the review turns into an error.
     */
    private function gender(mixed $value): string
    {
        $text = mb_substr($this->text($value), 0, 30);

        $parsed = Gender::tryParse($text);

        return $parsed === null ? $text : $parsed->value;
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * @param  array{line: int, name: string, rating: string, dupr_id: string, gender: string, source: string, warnings: list<string>}  $row
     * @param  'existing'|'new'|'listed'|'error'  $status
     * @return ReviewRow
     */
    private function result(array $row, string $status, ?Player $match, ?string $message): array
    {
        return [
            ...$row,
            'status' => $status,
            'player_id' => $match?->id,
            'player_name' => $match?->name,
            'message' => $message,
        ];
    }

    private function failureNote(ValidationException|UniqueConstraintViolationException $e): string
    {
        if ($e instanceof ValidationException) {
            $first = collect($e->errors())->flatten()->first();

            return is_string($first) ? $first : 'could not be saved.';
        }

        return 'conflicts with an existing player.';
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
