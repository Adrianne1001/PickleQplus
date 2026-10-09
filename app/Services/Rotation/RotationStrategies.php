<?php

namespace App\Services\Rotation;

use App\Domain\Rotation\BalancedRotationEngine;
use App\Domain\Rotation\MixedRotationEngine;
use App\Domain\Rotation\Weights;
use App\Enums\RotationMode;
use App\Models\PlaySession;

/**
 * Picks the strategy for a session's rotation mode. Each later P7.4 item adds
 * its arm here and its mode to config('pickleq.rotation_modes_enabled').
 */
final class RotationStrategies
{
    /**
     * A strategy bound in the container as rotation.strategy.<mode> wins, so tests can
     * bind a stub. Otherwise the built-in strategy for the mode is used.
     */
    public static function for(PlaySession $session): RotationStrategy
    {
        $key = 'rotation.strategy.'.$session->rotation_mode->value;
        if (app()->bound($key)) {
            $bound = app($key);
            if ($bound instanceof RotationStrategy) {
                return $bound;
            }
        }

        return match ($session->rotation_mode) {
            RotationMode::Balanced => new BalancedStrategy(self::balancedEngine()),
            RotationMode::Mixed => new MixedStrategy(self::mixedEngine(), self::balancedEngine()),
            RotationMode::SkillCourts => new SkillCourtsStrategy(self::balancedEngine()),
            RotationMode::Social => new BalancedStrategy(self::socialEngine()),
            default => throw new \LogicException("Rotation mode {$session->rotation_mode->value} is not implemented yet."),
        };
    }

    private static function mixedEngine(): MixedRotationEngine
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('pickleq.rotation', []);

        return new MixedRotationEngine(Weights::fromConfig($config));
    }

    private static function socialEngine(): BalancedRotationEngine
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('pickleq.rotation', []);

        return new BalancedRotationEngine(Weights::socialFromConfig($config));
    }

    private static function balancedEngine(): BalancedRotationEngine
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('pickleq.rotation', []);

        return new BalancedRotationEngine(Weights::fromConfig($config));
    }
}
