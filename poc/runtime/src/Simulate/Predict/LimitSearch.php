<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Predict;

/**
 * The limit multiplier (simulator-spec.md section 6.1): the first multiplier, in steps of 0.1 up to 10, at which the
 * utilisation is over the threshold.
 *
 * The multiplier is an integer step k (k = 1..100, multiplier = k / 10). It is never built by adding 0.1 again and again,
 * so it does not drift. "Over" is strictly greater than the threshold, with a relative margin of 1e-12 so that the noise
 * of the floating-point product does not make a utilisation that is exactly at the threshold look over it.
 */
final class LimitSearch
{
    public const STEPS = 100;

    public const PER_UNIT = 10;

    public const MARGIN = 1e-12;

    public const FOUND = 'found';

    /** Over the threshold already at the current traffic (multiplier 1.0 or less). */
    public const ALREADY_OVER = 'already_over';

    public const NOT_WITHIN_CAP = 'not_within_10x';

    /**
     * @param callable(int): float $utilisationAtStep the utilisation when the rps of every route is k / 10 times its value
     *
     * @return array{status: string, k: int|null, multiplier: float|null}
     */
    public static function find(callable $utilisationAtStep, float $threshold): array
    {
        for ($k = 1; $k <= self::STEPS; $k++) {
            if (self::exceeds($utilisationAtStep($k), $threshold)) {
                return [
                    'status' => $k <= self::PER_UNIT ? self::ALREADY_OVER : self::FOUND,
                    'k' => $k,
                    'multiplier' => (float) ($k / self::PER_UNIT),
                ];
            }
        }

        return ['status' => self::NOT_WITHIN_CAP, 'k' => null, 'multiplier' => null];
    }

    /** The search of a load that grows in proportion to the multiplier: utilisation(k) = utilisationNow * k / 10. */
    public static function findProportional(float $utilisationNow, float $threshold): array
    {
        return self::find(static fn (int $k): float => $utilisationNow * $k / self::PER_UNIT, $threshold);
    }

    public static function exceeds(float $utilisation, float $threshold): bool
    {
        return $utilisation > $threshold * (1.0 + self::MARGIN);
    }
}
