<?php

namespace App\Domain\Rotation;

use InvalidArgumentException;

/**
 * The skill-court groups of a session: contiguous court ranges, each with a
 * star range. Pure value object. Groups are numbered from 1 in court order, so
 * group 1 holds court 1 (the top court).
 *
 * Valid groups: at least 2, every group has at least one court, the court
 * ranges are contiguous and cover exactly 1..courts, and the star ranges do not
 * overlap and cover exactly 1 to 6.
 */
final readonly class SkillGroups
{
    public const MIN_COURTS = 2;

    public const MIN_STARS = 1;

    public const MAX_STARS = 6;

    /**
     * @param  list<array{from_court: int, to_court: int, min_stars: int, max_stars: int}>  $groups
     */
    private function __construct(private array $groups, public int $courts) {}

    /**
     * Parse and validate raw groups for a session with $courts courts. Values may be
     * numeric strings (form input). The groups are put in court order.
     *
     * @param  array<array-key, mixed>  $raw
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $raw, int $courts): self
    {
        if ($courts < self::MIN_COURTS) {
            throw new InvalidArgumentException('Skill courts needs at least 2 courts.');
        }
        if (count($raw) < 2) {
            throw new InvalidArgumentException('Skill courts needs at least 2 groups.');
        }

        $groups = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException('Each group needs from_court, to_court, min_stars and max_stars.');
            }
            $groups[] = [
                'from_court' => self::whole($entry['from_court'] ?? null),
                'to_court' => self::whole($entry['to_court'] ?? null),
                'min_stars' => self::whole($entry['min_stars'] ?? null),
                'max_stars' => self::whole($entry['max_stars'] ?? null),
            ];
        }

        usort($groups, static fn (array $a, array $b): int => $a['from_court'] <=> $b['from_court']);

        $nextCourt = 1;
        foreach ($groups as $group) {
            if ($group['to_court'] < $group['from_court']) {
                throw new InvalidArgumentException("Courts {$group['from_court']}–{$group['to_court']} is not a valid range: every group needs at least one court.");
            }
            if ($group['from_court'] !== $nextCourt) {
                throw new InvalidArgumentException("Court ranges must be contiguous and cover courts 1 to {$courts}.");
            }
            $nextCourt = $group['to_court'] + 1;
        }
        if ($nextCourt - 1 !== $courts) {
            throw new InvalidArgumentException("Court ranges must be contiguous and cover courts 1 to {$courts}.");
        }

        $byStars = $groups;
        usort($byStars, static fn (array $a, array $b): int => $a['min_stars'] <=> $b['min_stars']);
        $nextStar = self::MIN_STARS;
        foreach ($byStars as $group) {
            if ($group['max_stars'] < $group['min_stars'] || $group['min_stars'] !== $nextStar) {
                throw new InvalidArgumentException('Star ranges must not overlap and must cover 1 to 6 stars.');
            }
            $nextStar = $group['max_stars'] + 1;
        }
        if ($nextStar - 1 !== self::MAX_STARS) {
            throw new InvalidArgumentException('Star ranges must not overlap and must cover 1 to 6 stars.');
        }

        return new self($groups, $courts);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function whole(mixed $value): int
    {
        if (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1) {
            $value = (int) $value;
        }

        return is_int($value)
            ? $value
            : throw new InvalidArgumentException('Each group needs from_court, to_court, min_stars and max_stars as whole numbers.');
    }

    /**
     * Like fromArray() but null when the groups are invalid.
     *
     * @param  array<array-key, mixed>  $raw
     */
    public static function tryFromArray(array $raw, int $courts): ?self
    {
        try {
            return self::fromArray($raw, $courts);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Two groups: courts 1..ceil(n/2) for 4 to 6 stars, the rest for 1 to 3 stars.
     *
     * @throws InvalidArgumentException
     */
    public static function defaultFor(int $courts): self
    {
        if ($courts < self::MIN_COURTS) {
            throw new InvalidArgumentException('Skill courts needs at least 2 courts.');
        }
        $top = intdiv($courts + 1, 2);

        return self::fromArray([
            ['from_court' => 1, 'to_court' => $top, 'min_stars' => 4, 'max_stars' => 6],
            ['from_court' => $top + 1, 'to_court' => $courts, 'min_stars' => 1, 'max_stars' => 3],
        ], $courts);
    }

    /**
     * The same groups for a new court count: the last group's range grows or
     * shrinks. Throws when the last group would be left with no courts.
     *
     * @throws InvalidArgumentException
     */
    public function resizedTo(int $courts): self
    {
        if ($courts < self::MIN_COURTS) {
            throw new InvalidArgumentException('Skill courts needs at least 2 courts.');
        }
        $groups = $this->groups;
        $last = count($groups) - 1;
        if ($courts < $groups[$last]['from_court']) {
            throw new InvalidArgumentException('That would leave a skill group with no courts.');
        }
        $groups[$last]['to_court'] = $courts;

        return self::fromArray($groups, $courts);
    }

    /**
     * @return list<array{from_court: int, to_court: int, min_stars: int, max_stars: int}>
     */
    public function toArray(): array
    {
        return $this->groups;
    }

    public function count(): int
    {
        return count($this->groups);
    }

    /**
     * The group (1-based) whose star range holds these stars.
     *
     * @throws InvalidArgumentException when the stars are outside 1 to 6
     */
    public function groupForStars(int $stars): int
    {
        return $this->tryGroupForStars($stars) ?? throw new InvalidArgumentException("Stars must be between 1 and 6, got {$stars}.");
    }

    public function tryGroupForStars(int $stars): ?int
    {
        foreach ($this->groups as $i => $group) {
            if ($stars >= $group['min_stars'] && $stars <= $group['max_stars']) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * The court numbers of a group, lowest first.
     *
     * @return list<int>
     *
     * @throws InvalidArgumentException for an unknown group
     */
    public function courtRange(int $group): array
    {
        $g = $this->groups[$group - 1] ?? throw new InvalidArgumentException("Unknown group {$group}.");

        return range($g['from_court'], $g['to_court']);
    }

    /**
     * @return array{min: int, max: int}
     */
    public function starRange(int $group): array
    {
        $g = $this->groups[$group - 1] ?? throw new InvalidArgumentException("Unknown group {$group}.");

        return ['min' => $g['min_stars'], 'max' => $g['max_stars']];
    }

    /**
     * The group (1-based) that owns a court, or null for a court outside 1..courts.
     */
    public function groupForCourt(int $court): ?int
    {
        foreach ($this->groups as $i => $group) {
            if ($court >= $group['from_court'] && $court <= $group['to_court']) {
                return $i + 1;
            }
        }

        return null;
    }

    /**
     * "Courts 1–2", or "Court 3" for a single court. Never shows stars.
     */
    public function label(int $group): string
    {
        $g = $this->groups[$group - 1] ?? throw new InvalidArgumentException("Unknown group {$group}.");

        return $g['from_court'] === $g['to_court']
            ? "Court {$g['from_court']}"
            : "Courts {$g['from_court']}–{$g['to_court']}";
    }
}
