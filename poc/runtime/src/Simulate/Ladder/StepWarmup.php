<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Ladder;

use PeakSim\Runtime\Simulate\Predict\AppLimitRisk;
use PeakSim\Runtime\Simulate\Predict\Coefficients;

/**
 * The length of the discarded start of a step (its warm-up) for the sixth backtest of B (simulator-spec.md section 7.4.5). Pure: arrays in,
 * arrays out; no clock, no files, no database, and no measurement is ever read.
 *
 * The rule: the warm-up of a stage is the largest, over the routes in the stage's mix (the routes that stay after the allocation of section
 * 7.4.4, each carrying an rps above 0), of the upper bound of the time one request takes that the CPU-time mark gives (`php_cpu_bound_s` of
 * {@see AppLimitRisk}: php_cpu_over_db x the larger of the point and the safe reading of the database time of one request), rounded up to a whole
 * second and never under {@see self::MINIMUM_SEC}. A route the mark could not judge (no cost) has no bound and is not counted. When no route in the
 * mix has a bound the warm-up is the minimum and the basis names no route.
 *
 * The bound of a request does not depend on the rps of a stage, so every stage of a scenario gets the same figure; it is written per stage all the
 * same, for the measuring tool reads it per stage. On a tie the route that comes first in the scenario's mix is the basis.
 */
final class StepWarmup
{
    /** The warm-up is never shorter than the 60 seconds every step has always had. */
    public const MINIMUM_SEC = 60;

    public const RULE = 'the warm-up (the discarded start) of a stage is the largest, over the routes in the stage\'s mix after the allocation, of the upper bound of one request\'s time that the CPU-time mark gives (php_cpu_over_db x the larger of the point and the safe reading of the database time of one request), rounded up to a whole second, and at least 60 s; it is decided from the prediction alone';

    /**
     * The bound of the CPU-time mark of each route that has a cost, in the order of the routes.
     *
     * @param list<array<string, mixed>> $routes the routes of one scenario's mix after the allocation, as the loaded input gives them
     *
     * @return array<string, float> route => seconds
     */
    public static function bounds(array $routes, Coefficients $c): array
    {
        $bounds = [];
        // the limit given to the mark only decides whether a route is marked, which is not read here; any positive number does
        foreach (AppLimitRisk::evaluate($routes, $c, 1.0)['routes'] as $row) {
            if ($row['status'] !== AppLimitRisk::NOT_EVALUATED && $row['php_cpu_bound_s'] !== null) {
                $bounds[(string) $row['route']] = (float) $row['php_cpu_bound_s'];
            }
        }

        return $bounds;
    }

    /**
     * The warm-up of one stage and what it rests on.
     *
     * @param array<string, float> $bounds     {@see self::bounds}
     * @param array<string, float> $rpsOfStage route => rps of the stage (routes out of the mix are not in it; a route at 0 does not count)
     *
     * @return array{warmup_sec: int, warmup_basis: array{route: string|null, upper_bound_sec: float|null}}
     */
    public static function forStage(array $bounds, array $rpsOfStage): array
    {
        $route = null;
        $largest = null;
        foreach ($rpsOfStage as $name => $rps) {
            if (!($rps > 0.0) || !array_key_exists((string) $name, $bounds)) {
                continue;
            }
            if ($largest === null || $bounds[(string) $name] > $largest) {
                $route = (string) $name;
                $largest = $bounds[(string) $name];
            }
        }

        return [
            'warmup_sec' => $largest === null ? self::MINIMUM_SEC : max(self::MINIMUM_SEC, (int) ceil($largest)),
            'warmup_basis' => ['route' => $route, 'upper_bound_sec' => $largest],
        ];
    }
}
