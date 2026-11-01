<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Judge;

/**
 * How a measured series grows (D26, docs/spec.md). This is a port of the rule the fixture app's expectations were
 * decided with (fixtures/laravel-app/tests/Support/GrowthRules.php); tests/vectors/growth.json is read by a test
 * that runs both on the same series, so the two cannot drift apart.
 *
 * The growth is the log-log slope between the two largest scales, so a fixed part (framework boot, the first page
 * of results) does not hide the trend.
 *  - slope <= CONSTANT_MAX_SLOPE : O(1)
 *  - slope >= LINEAR_MIN_SLOPE   : O(N)
 *  - in between                  : inconclusive. It is reported as it is and never rounded to either side.
 * A verdict needs at least three points. A linear claim about a query count also needs every segment slope to stay
 * within MAX_SLOPE_SPREAD of the first segment's slope. O(N*M) needs the sweep over N and the sweep over M to be
 * linear on their own.
 */
final class GrowthRules
{
    public const CONSTANT_MAX_SLOPE = 0.2;

    public const LINEAR_MIN_SLOPE = 0.5;

    public const MAX_SLOPE_SPREAD = 0.10;

    public const MIN_POINTS = 3;

    public const O1 = 'measured_O1';

    public const ON = 'measured_ON';

    public const ONM = 'measured_ONM';

    public const INCONCLUSIVE = 'inconclusive';

    public const INSUFFICIENT = 'insufficient_points';

    /**
     * Log-log slope between the two largest scales. A value below 1 counts as 1, so a series of zeros is flat.
     *
     * @param list<int> $scales ascending
     * @param list<int|float> $values
     */
    public static function slope(array $scales, array $values): float
    {
        [$scales, $values] = [array_values($scales), array_values($values)];
        $n = count($scales);
        if ($n < 2 || count($values) !== $n) {
            throw new \InvalidArgumentException('a growth series needs at least two scales and one value per scale');
        }

        return log(max(1, $values[$n - 1]) / max(1, $values[$n - 2])) / log($scales[$n - 1] / $scales[$n - 2]);
    }

    /**
     * @param list<int> $scales
     * @param list<int|float> $values
     *
     * @return 'O(1)'|'O(N)'|'inconclusive'
     */
    public static function classify(array $scales, array $values): string
    {
        $slope = self::slope($scales, $values);

        return match (true) {
            $slope <= self::CONSTANT_MAX_SLOPE => 'O(1)',
            $slope >= self::LINEAR_MIN_SLOPE => 'O(N)',
            default => 'inconclusive',
        };
    }

    /**
     * Added amount per added row on each segment.
     *
     * @param list<int> $scales
     * @param list<int|float> $values
     *
     * @return list<float>
     */
    public static function segmentSlopes(array $scales, array $values): array
    {
        [$scales, $values] = [array_values($scales), array_values($values)];
        $slopes = [];
        for ($i = 1, $n = count($scales); $i < $n; ++$i) {
            $slopes[] = ($values[$i] - $values[$i - 1]) / ($scales[$i] - $scales[$i - 1]);
        }

        return $slopes;
    }

    /**
     * @param list<int> $scales
     * @param list<int|float> $values
     */
    public static function spreadWithinLimit(array $scales, array $values): bool
    {
        $slopes = self::segmentSlopes($scales, $values);
        if ($slopes === []) {
            return true;
        }
        $first = $slopes[0];

        foreach ($slopes as $slope) {
            if (abs($slope - $first) > self::MAX_SLOPE_SPREAD * abs($first) + 1e-12) {
                return false;
            }
        }

        return true;
    }

    /**
     * The verdict of one sweep.
     *
     * @param list<int> $scales ascending
     * @param list<int|float> $values
     * @param bool $spread also hold the segment slopes to MAX_SLOPE_SPREAD when a linear claim is made (query counts)
     *
     * @return 'measured_O1'|'measured_ON'|'inconclusive'|'insufficient_points'
     */
    public static function verdict(array $scales, array $values, bool $spread = true): string
    {
        if (count($scales) < self::MIN_POINTS || count($values) !== count($scales)) {
            return self::INSUFFICIENT;
        }

        return match (self::classify($scales, $values)) {
            'O(1)' => self::O1,
            'O(N)' => ! $spread || self::spreadWithinLimit($scales, $values) ? self::ON : self::INCONCLUSIVE,
            default => self::INCONCLUSIVE,
        };
    }

    /**
     * O(N*M) is said only when both sweeps are linear. One linear and one flat sweep is O(N), both flat is O(1).
     * Anything that is not decided on either sweep is not decided here either; nothing is rounded.
     *
     * @param list<int> $nScales
     * @param list<int|float> $nValues
     * @param list<int> $mScales
     * @param list<int|float> $mValues
     *
     * @return 'measured_O1'|'measured_ON'|'measured_ONM'|'inconclusive'|'insufficient_points'
     */
    public static function productVerdict(array $nScales, array $nValues, array $mScales, array $mValues, bool $spread = true): string
    {
        $n = self::verdict($nScales, $nValues, $spread);
        $m = self::verdict($mScales, $mValues, $spread);

        if ($n === self::INSUFFICIENT || $m === self::INSUFFICIENT) {
            return self::INSUFFICIENT;
        }
        if ($n === self::INCONCLUSIVE || $m === self::INCONCLUSIVE) {
            return self::INCONCLUSIVE;
        }
        if ($n === self::ON && $m === self::ON) {
            return self::ONM;
        }

        return $n === self::ON || $m === self::ON ? self::ON : self::O1;
    }
}
