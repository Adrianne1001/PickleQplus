<?php

namespace App\Services;

use App\Domain\Stars\StarRating;
use App\Enums\Gender;
use App\Enums\RatingSource;
use App\Models\Club;
use App\Models\Player;
use App\Rules\DuprPlayerId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Player writes. Input is expected to be validated already (see
 * App\Concerns\PlayerValidationRules); this service normalizes the DUPR ID
 * and resolves rating source and stars. Players are never hard-deleted.
 */
class PlayerService
{
    /**
     * @param  array{name: string, nickname?: string|null, gender?: Gender|string|null, dupr_id?: string|null, dupr_rating?: float|int|string|null, rating_source?: RatingSource|string|null, stars?: int|null}  $data
     */
    public function create(Club $club, array $data): Player
    {
        $player = new Player(['active' => true]);
        $player->club()->associate($club);

        return $this->persist($player, $club, $data);
    }

    /**
     * Partial update: only keys present in $data change.
     *
     * @param  array{name?: string, nickname?: string|null, gender?: Gender|string|null, dupr_id?: string|null, dupr_rating?: float|int|string|null, rating_source?: RatingSource|string|null, stars?: int|null}  $data
     */
    public function update(Player $player, array $data): Player
    {
        $before = $player->gender;
        $updated = $this->persist($player, $player->club ?? $player->club()->firstOrFail(), $data);

        if ($updated->gender !== $before) {
            // After any surrounding transaction commits (the roster import wraps this), so the session lock
            // is never taken while player rows are locked: the usual order is session, then player.
            DB::afterCommit(static function () use ($updated): void {
                // A failure here must not reach the caller (a roster import) or skip the other players.
                try {
                    app(CheckInService::class)->gendersChanged($updated);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }

        return $updated;
    }

    public function deactivate(Player $player): Player
    {
        $player->forceFill(['active' => false])->save();

        return $player;
    }

    public function reactivate(Player $player): Player
    {
        $player->forceFill(['active' => true])->save();

        return $player;
    }

    /**
     * The DUPR ID is normalized in save(), and a unique-index violation (a race,
     * or a caller that skipped validation) becomes a validation error, not a 500.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function persist(Player $player, Club $club, array $data): Player
    {
        try {
            return DB::transaction(function () use ($player, $club, $data): Player {
                // Lock the club row so stars are never derived from bands that a
                // concurrent band edit is replacing.
                $fresh = Club::query()->whereKey($club->id)->lockForUpdate()->first() ?? $club;

                return $this->save($player, $fresh, $data);
            });
        } catch (UniqueConstraintViolationException $e) {
            $key = preg_match('/players.nickname|nickname_unique/', $e->getMessage()) === 1 ? 'nickname' : 'dupr_id';

            throw ValidationException::withMessages([
                $key => $key === 'nickname'
                    ? 'That nickname is already taken in this club.'
                    : 'Another player in this club already has this DUPR ID.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(Player $player, Club $club, array $data): Player
    {
        $exists = $player->exists;

        $name = array_key_exists('name', $data) ? trim((string) $data['name']) : $player->name;
        $duprId = array_key_exists('dupr_id', $data) ? DuprPlayerId::normalize($data['dupr_id']) : $player->dupr_id;

        $rating = $exists ? $player->dupr_rating : null;
        if (array_key_exists('dupr_rating', $data)) {
            $rating = ($data['dupr_rating'] === null || $data['dupr_rating'] === '')
                ? null
                : number_format((float) $data['dupr_rating'], 3, '.', '');
        }

        // Resolve the rating source.
        if ($rating === null) {
            $source = RatingSource::Manual;
        } elseif (array_key_exists('rating_source', $data) && $data['rating_source'] !== null) {
            $source = $data['rating_source'] instanceof RatingSource
                ? $data['rating_source']
                : RatingSource::from((string) $data['rating_source']);
        } elseif ($exists && $player->dupr_rating !== null) {
            $source = $player->rating_source;
        } else {
            $source = RatingSource::Dupr;
        }

        // Resolve stars.
        if ($source === RatingSource::Dupr && $rating !== null) {
            if (array_key_exists('stars', $data) && $data['stars'] !== null) {
                throw ValidationException::withMessages(['stars' => 'Switch to manual to override stars.']);
            }

            $stars = StarRating::fromRating((float) $rating, $this->bands($club));
        } else {
            $stars = array_key_exists('stars', $data) && $data['stars'] !== null
                ? (int) $data['stars']
                : ($exists ? $player->stars : null);

            if ($stars === null) {
                throw ValidationException::withMessages(['stars' => 'Stars are required for players without a DUPR rating.']);
            }
        }

        $nickname = $player->nickname;
        if (array_key_exists('nickname', $data)) {
            $nickname = Player::normalizeNickname($data['nickname']);
            if ($nickname !== null) {
                if (mb_strlen($nickname) > 20) {
                    throw ValidationException::withMessages(['nickname' => 'The nickname may not be longer than 20 characters.']);
                }
                if (Player::nicknameTaken($club->id, $nickname, $player->exists ? $player->id : null)) {
                    throw ValidationException::withMessages(['nickname' => 'That nickname is already taken in this club.']);
                }
            }
        }

        // A key that is present sets the gender (null or blank clears it); an absent key keeps it.
        $gender = $player->gender;
        if (array_key_exists('gender', $data)) {
            $raw = $data['gender'];
            $gender = Gender::tryParse($raw);
            if ($gender === null && $raw !== null && (! is_string($raw) || trim($raw) !== '')) {
                throw ValidationException::withMessages(['gender' => 'Gender must be man or woman.']);
            }
        }

        $player->forceFill([
            'name' => $name,
            'nickname' => $nickname,
            'gender' => $gender,
            'dupr_id' => $duprId,
            'dupr_rating' => $rating,
            'rating_source' => $source,
            'stars' => $stars,
        ])->save();

        return $player;
    }

    /**
     * @return list<float>
     */
    private function bands(Club $club): array
    {
        return array_map(fn ($b): float => (float) $b, $club->star_bands);
    }
}
