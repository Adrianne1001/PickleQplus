<?php

namespace App\Domain\Rotation;

use InvalidArgumentException;

/**
 * Cost weights and window size for the balanced engine.
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
}
