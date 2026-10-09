<?php

namespace App\Domain\Rotation;

use InvalidArgumentException;

/**
 * Cost weights and window size for the balanced engine. `partnersFirst` (Social mix only)
 * makes the engine pick, within each 4-player group, the split with the fewest repeat partners
 * before looking at weighted cost.
 */
final readonly class Weights
{
    public const MIN_WINDOW = 4;

    public function __construct(
        public float $starBalance,
        public float $repeatPartner,
        public float $repeatOpponent,
        public float $skippedPriority,
        public int $window = 8,
        public bool $partnersFirst = false,
    ) {
        if ($window < self::MIN_WINDOW) {
            throw new InvalidArgumentException('Rotation window must be at least '.self::MIN_WINDOW.'.');
        }
    }

    /**
     * Build from the plain array at config('pickleq.rotation'). Missing keys fall back to defaults.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $num = static fn (string $key, float $default): float => isset($config[$key]) && is_numeric($config[$key]) ? (float) $config[$key] : $default;

        return new self(
            starBalance: $num('star_balance', 3),
            repeatPartner: $num('repeat_partner', 4),
            repeatOpponent: $num('repeat_opponent', 1.5),
            skippedPriority: $num('skipped_priority', 2),
            window: max(self::MIN_WINDOW, (int) $num('window', 8)),
        );
    }

    /**
     * Social mix weights from the same config array: stars are ignored (weight 0), the
     * `social` sub-array sets the rest, and the window is the shared top-level `window`.
     *
     * @param  array<string, mixed>  $config
     */
    public static function socialFromConfig(array $config): self
    {
        /** @var array<string, mixed> $social */
        $social = is_array($config['social'] ?? null) ? $config['social'] : [];
        $num = static fn (string $key, float $default): float => isset($social[$key]) && is_numeric($social[$key]) ? (float) $social[$key] : $default;

        return new self(
            starBalance: 0,
            repeatPartner: $num('repeat_partner', 6),
            repeatOpponent: $num('repeat_opponent', 2),
            skippedPriority: $num('skipped_priority', 2),
            window: self::fromConfig($config)->window,
            partnersFirst: true,
        );
    }
}
