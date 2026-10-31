<?php

namespace Tests\Support;

/**
 * The one place where "how a measured series grows" is decided (D26, docs/spec.md).
 * The ok, ng-basic, ng-deep and hidden tests all call this class; no group has thresholds of its own.
 *
 * The series is measured at two or more scales (row counts). Its growth is the log-log slope between the
 * two largest scales, so a fixed part (framework boot, the first page of results) does not hide the trend.
 *  - slope <= CONSTANT_MAX_SLOPE : O(1)
 *  - slope >= LINEAR_MIN_SLOPE   : O(N)
 *  - in between                  : inconclusive. It is reported as it is and never rounded to either side.
 * O(N*M) needs both the sweep over N and the sweep over M to be O(N).
 * For a query count that is claimed to be linear, every segment slope (added queries per added row)
 * must also stay within MAX_SLOPE_SPREAD of the first segment's slope.
 */
final class GrowthRules
{
    public const CONSTANT_MAX_SLOPE = 0.2;

    public const LINEAR_MIN_SLOPE = 0.5;

    public const MAX_SLOPE_SPREAD = 0.10;

    /**
     * Log-log slope between the two largest scales. A value below 1 counts as 1, so a series of zeros is flat.
     *
     * @param  list<int>  $scales  ascending
     * @param  list<int|float>  $values
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
     * @param  list<int>  $scales
     * @param  list<int|float>  $values
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
     * @param  list<int>  $scales
     * @param  list<int|float>  $values
     * @return list<float>
     */
    public static function segmentSlopes(array $scales, array $values): array
    {
        [$scales, $values] = [array_values($scales), array_values($values)];
        $slopes = [];
        for ($i = 1, $n = count($scales); $i < $n; $i++) {
            $slopes[] = ($values[$i] - $values[$i - 1]) / ($scales[$i] - $scales[$i - 1]);
        }

        return $slopes;
    }

    /**
     * @param  list<int>  $scales
     * @param  list<int|float>  $values
     */
    public static function spreadWithinLimit(array $scales, array $values): bool
    {
        $slopes = self::segmentSlopes($scales, $values);
        $first = $slopes[0];

        foreach ($slopes as $slope) {
            if (abs($slope - $first) > self::MAX_SLOPE_SPREAD * abs($first) + 1e-12) {
                return false;
            }
        }

        return true;
    }

    /**
     * Null when the series grows as $expected says; otherwise a message that says what was seen.
     * Nothing here is adjusted to make a route pass: a miss is a finding to report.
     *
     * @param  'O(1)'|'O(N)'  $expected
     * @param  list<int>  $scales
     * @param  list<int|float>  $values
     * @param  bool  $spread  also hold the segment slopes to MAX_SLOPE_SPREAD (query counts claimed linear)
     */
    public static function check(string $expected, array $scales, array $values, bool $spread = false): ?string
    {
        $slope = self::slope($scales, $values);
        $seen = self::classify($scales, $values);
        $detail = sprintf('log-log slope %.3f between %d and %d rows', $slope, $scales[count($scales) - 2], $scales[count($scales) - 1]);

        if ($seen === 'inconclusive') {
            return sprintf(
                'expected %s but the growth is inconclusive (%s; O(1) needs <= %s, O(N) needs >= %s); not fitted to the expectation',
                $expected, $detail, self::CONSTANT_MAX_SLOPE, self::LINEAR_MIN_SLOPE,
            );
        }
        if ($seen !== $expected) {
            return sprintf('expected %s but measured %s (%s)', $expected, $seen, $detail);
        }
        if ($spread && $expected === 'O(N)' && ! self::spreadWithinLimit($scales, $values)) {
            return sprintf(
                'expected O(N) and the slope is %.3f, but the slope spread across segments exceeds %d%% (%s)',
                $slope, (int) round(self::MAX_SLOPE_SPREAD * 100),
                implode(', ', array_map(fn (float $s) => sprintf('%.4g', $s), self::segmentSlopes($scales, $values))),
            );
        }

        return null;
    }

    /**
     * O(N*M): the sweep over N and the sweep over M must both be O(N).
     *
     * @param  array{list<int>, list<int|float>}  $n  scales and values of the sweep over N
     * @param  array{list<int>, list<int|float>}  $m  scales and values of the sweep over M
     */
    public static function checkProduct(array $n, array $m, bool $spread = false): ?string
    {
        foreach (['N' => $n, 'M' => $m] as $axis => [$scales, $values]) {
            $problem = self::check('O(N)', $scales, $values, $spread);
            if ($problem !== null) {
                return "{$axis} sweep: {$problem}";
            }
        }

        return null;
    }
}
