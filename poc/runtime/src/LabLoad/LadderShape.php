<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabLoad;

/**
 * How one step of the ladder is laid out in time: how long its kept window is, and when each route's stream of arrivals begins.
 *
 * Three schemes, chosen by one argument (the default is the spec as written):
 *   spec  the kept window is 300 s (a step is 360 s, the first 60 s discarded); every route's stream starts at 0.
 *   A     the window is the spec's 300 s; each route's stream starts at its own time, so that the number of requests each route
 *         has in the window is a whole number chosen on purpose (the floor or the ceiling of its share; the routes with the
 *         largest fractions get the ceiling, ties in the order of the list) and the total is exactly the decided rps x the window.
 *   B     the window is the smallest multiple of 40 / rps that is at least 300 s (40 is the least common multiple of the spacings
 *         of every mix in units of 1 / rps), so every route's spacing divides it; the streams are placed as in A.
 * The window rule and the staggering are separate parts (`window`, `stagger`); the schemes only name the two choices.
 *
 * This is arithmetic on the plan. It assumes what the lab's k6 does: a route's requests arrive evenly spaced from the start time
 * of its stream, and a request counts in the window if it arrives from the discarded start to the end of the step.
 */
final class LadderShape
{
    public const SPEC = 'spec';
    public const A = 'A';
    public const B = 'B';

    /** A window must be at least this long (the spec's kept window). */
    public const MIN_WINDOW_SEC = Ladder::DURATION_SEC - Ladder::WARMUP_SEC;

    /** @return list<string> */
    public static function schemes(): array
    {
        return [self::SPEC, self::A, self::B];
    }

    /**
     * The length of the step and of its discarded start for a scheme at a total rps.
     *
     * @return array{duration_sec: int, warmup_sec: int}
     *
     * @throws \InvalidArgumentException an unknown scheme or a non-positive rps
     */
    public static function shape(string $scheme, float $rps): array
    {
        self::assertScheme($scheme);
        if (!is_finite($rps) || $rps <= 0.0) {
            throw new \InvalidArgumentException('rps must be a positive number');
        }
        $window = $scheme === self::B ? self::multipleWindow($rps) : self::MIN_WINDOW_SEC;

        return ['duration_sec' => Ladder::WARMUP_SEC + $window, 'warmup_sec' => Ladder::WARMUP_SEC];
    }

    /**
     * The smallest multiple of 40 / rps that is at least 300 s, in whole seconds. A window of one unit is rounded down, so that
     * the plan (the window times the rps) never comes out above the 40 requests the step is meant to hold; a longer window is
     * rounded to the nearest second, as it always was.
     */
    public static function multipleWindow(float $rps): int
    {
        $unit = 40.0 / $rps;
        $times = (int) ceil(self::MIN_WINDOW_SEC / $unit - 1e-9);
        if ($times <= 1) {
            return (int) floor($unit + 1e-9);
        }

        return (int) round($times * $unit);
    }

    /**
     * Gives each route of a plan the time its stream starts and the number of requests it is to have in the window. A plan made
     * for the `spec` scheme is returned as it is (with the scheme named).
     *
     * @param array{rps: float|int, duration_sec: int, warmup_sec: int, routes: list<array<string, mixed>>} $plan
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException no start time gives a route the number of requests it is to have
     */
    public static function stagger(array $plan, string $scheme): array
    {
        self::assertScheme($scheme);
        $plan['scheme'] = $scheme;
        $plan['window_sec'] = $plan['duration_sec'] - $plan['warmup_sec'];
        if ($scheme === self::SPEC) {
            return $plan;
        }
        $window = $plan['window_sec'];
        $planned = self::plannedCounts(array_map(static fn (array $r): float => (float) $r['rps'], $plan['routes']), $window, (float) $plan['rps']);
        foreach ($plan['routes'] as $i => $r) {
            $spacing = (int) rtrim((string) $r['time_unit'], 's') / (int) $r['rate'];
            $offset = self::place($spacing, $planned[$i], $plan['warmup_sec'], $plan['duration_sec']);
            if ($offset === null) {
                throw new \InvalidArgumentException(sprintf('no start time gives the route %s %d requests in the window', (string) $r['route'], $planned[$i]));
            }
            $plan['routes'][$i]['start_offset_sec'] = $offset;
            $plan['routes'][$i]['planned_window_requests'] = $planned[$i];
        }

        return $plan;
    }

    /**
     * How many requests each route is to have in the window: the floor of its share of the decided total, and the ceiling for the
     * routes with the largest fractions (ties: the earlier route), until the sum is the decided total over the window, rounded.
     *
     * @param list<float> $routeRps
     *
     * @return list<int>
     */
    public static function plannedCounts(array $routeRps, int $window, float $totalRps): array
    {
        $exact = array_map(static fn (float $r): float => $r * $window, $routeRps);
        $counts = array_map(static fn (float $e): int => (int) floor($e + 1e-9), $exact);
        $need = (int) round($totalRps * $window) - array_sum($counts);
        $order = array_keys($exact);
        usort($order, static fn (int $a, int $b): int => [-($exact[$a] - $counts[$a]), $a] <=> [-($exact[$b] - $counts[$b]), $b]);
        foreach (array_slice($order, 0, max(0, $need)) as $i) {
            ++$counts[$i];
        }

        return $counts;
    }

    /**
     * The start time of a stream (0 up to one spacing) that puts exactly $count arrivals in [warmup, duration) and keeps every
     * arrival as far as possible from the two edges of the window. Null if no start time gives that count.
     */
    public static function place(float $spacing, int $count, int $warmup, int $duration): ?float
    {
        $best = null;
        $bestMargin = -1.0;
        $steps = 4000;
        for ($i = 0; $i < $steps; ++$i) {
            $phi = $spacing * $i / $steps;
            if ($phi >= $duration - 1) {
                continue; // a stream that would start at the very end of the step is not a stream
            }
            if (self::countIn($phi, $spacing, $warmup, $duration) !== $count) {
                continue;
            }
            $margin = min(self::distanceToNearest($phi, $spacing, $warmup), self::distanceToNearest($phi, $spacing, $duration));
            if ($margin > $bestMargin + 1e-9) {
                $bestMargin = $margin;
                $best = $phi;
            }
        }

        return $best === null ? null : round($best, 3);
    }

    /** The number of arrivals phi + k * spacing (k >= 0) in [from, to). */
    public static function countIn(float $phi, float $spacing, float $from, float $to): int
    {
        $kMin = max(0, (int) ceil(($from - $phi) / $spacing - 1e-9));
        $kMax = (int) ceil(($to - $phi) / $spacing - 1e-9) - 1;

        return max(0, $kMax - $kMin + 1);
    }

    /** The distance from time $t to the nearest arrival of the stream (phi + k * spacing, k >= 0). */
    public static function distanceToNearest(float $phi, float $spacing, float $t): float
    {
        $k = max(0, (int) round(($t - $phi) / $spacing));

        return abs($phi + $k * $spacing - $t);
    }

    private static function assertScheme(string $scheme): void
    {
        if (!in_array($scheme, self::schemes(), true)) {
            throw new \InvalidArgumentException(sprintf('unknown scheme "%s" (one of %s)', $scheme, implode(', ', self::schemes())));
        }
    }
}
