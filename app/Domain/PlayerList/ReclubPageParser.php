<?php

namespace App\Domain\PlayerList;

/**
 * Pure parser for a Reclub event page (Phase 16). No framework dependencies.
 *
 * There is no official Reclub API. The page embeds its state as a Nuxt "devalue" payload in
 * `<script id="__NUXT_DATA__">`: a flat JSON array in which object values and list items are
 * indices into the array, and wrappers like `["Reactive", n]` point at the real value. We find the
 * object with a `participants` key, keep participants with `status` 1 (confirmed; 4 is the waitlist,
 * -1 cancelled), and look up each participant's `referenceId` among the user objects (`userId`,
 * `name`). This can break whenever Reclub changes their page.
 */
final class ReclubPageParser
{
    private const STATUS_CONFIRMED = 1;

    private const STATUS_WAITLIST = 4;

    private const STATUS_CANCELLED = -1;

    /** Wrapper tags whose second element is the index of the real value. */
    private const WRAPPERS = ['Reactive', 'ShallowReactive', 'Ref', 'ShallowRef', 'EmptyRef', 'EmptyShallowRef', 'Readonly', 'ShallowReadonly'];

    /** @var list<mixed> */
    private array $data = [];

    /**
     * @throws ReclubParseException
     */
    public static function parse(string $html): ReclubEvent
    {
        return (new self)->read($html);
    }

    /**
     * @throws ReclubParseException
     */
    private function read(string $html): ReclubEvent
    {
        // Find the opening tag with a pattern that cannot run away over a large payload, then cut the
        // payload out with plain string searches.
        if (preg_match('/<script\b[^>]*\bid=["\']__NUXT_DATA__["\'][^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE) !== 1) {
            throw new ReclubParseException('No page data found.');
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $end = stripos($html, '</script>', $start);
        if ($end === false) {
            throw new ReclubParseException('No page data found.');
        }

        $decoded = json_decode(trim(substr($html, $start, $end - $start)), true, 512);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new ReclubParseException('The page data is not readable.');
        }
        $this->data = $decoded;

        $participants = $this->findParticipants();
        if ($participants === null) {
            throw new ReclubParseException('No participants found.');
        }

        $users = $this->userNames();

        $names = [];
        $seen = [];
        $waitlisted = 0;
        $cancelled = 0;
        foreach ($participants as $ref) {
            $participant = $this->object($ref);
            if ($participant === null) {
                continue;
            }

            $status = $this->scalar($participant, 'status');
            if ($status === self::STATUS_WAITLIST) {
                $waitlisted++;

                continue;
            }
            if ($status === self::STATUS_CANCELLED) {
                $cancelled++;

                continue;
            }
            if ($status !== self::STATUS_CONFIRMED) {
                continue;
            }

            $userId = $this->scalar($participant, 'referenceId');
            $name = is_int($userId) || is_string($userId) ? ($users[(string) $userId] ?? null) : null;
            if ($name === null || isset($seen[$userId])) {
                continue;
            }
            $seen[$userId] = true;
            $names[] = $name;
        }

        if ($names === []) {
            throw new ReclubParseException('No confirmed players found.');
        }

        return new ReclubEvent($names, $waitlisted, $cancelled);
    }

    /**
     * The list of participant references of the first object that has a `participants` key.
     *
     * @return list<mixed>|null
     */
    private function findParticipants(): ?array
    {
        foreach ($this->data as $node) {
            if (! is_array($node) || array_is_list($node) || ! array_key_exists('participants', $node)) {
                continue;
            }

            $list = $this->deref($node['participants']);
            if (is_array($list) && array_is_list($list) && $list !== []) {
                return $list;
            }
        }

        return null;
    }

    /**
     * Names of every user object (`userId` and `name`) in the payload, by user id.
     *
     * @return array<string, string>
     */
    private function userNames(): array
    {
        $users = [];
        foreach ($this->data as $node) {
            if (! is_array($node) || array_is_list($node) || ! array_key_exists('userId', $node) || ! array_key_exists('name', $node)) {
                continue;
            }

            $id = $this->scalar($node, 'userId');
            $name = $this->scalar($node, 'name');
            if ((! is_int($id) && ! is_string($id)) || ! is_string($name)) {
                continue;
            }

            $name = trim((string) preg_replace('/\s+/u', ' ', $name));
            if ($name !== '') {
                $users[(string) $id] ??= $name;
            }
        }

        return $users;
    }

    /**
     * A raw object node, resolved by reference.
     *
     * @return array<string, mixed>|null
     */
    private function object(mixed $ref): ?array
    {
        $node = $this->deref($ref);

        return is_array($node) && ! array_is_list($node) ? $node : null;
    }

    /**
     * @param  array<array-key, mixed>  $object  a raw object node
     */
    private function scalar(array $object, string $key): mixed
    {
        if (! array_key_exists($key, $object)) {
            return null;
        }

        $value = $this->deref($object[$key]);

        return is_array($value) ? null : $value;
    }

    /**
     * Follow a reference (an index into the payload) to its node, through wrappers. Negative
     * indices are devalue's special values (undefined, NaN, infinities): null here. A node that
     * isn't a reference can't be told apart from one, so callers only pass reference positions.
     */
    private function deref(mixed $ref): mixed
    {
        for ($hops = 0; $hops < 16; $hops++) {
            if (! is_int($ref) || $ref < 0 || $ref >= count($this->data)) {
                return null;
            }

            $node = $this->data[$ref];
            if (is_array($node) && array_is_list($node) && count($node) === 2
                && is_string($node[0]) && in_array($node[0], self::WRAPPERS, true)) {
                $ref = $node[1];

                continue;
            }

            return $node;
        }

        return null;
    }
}
