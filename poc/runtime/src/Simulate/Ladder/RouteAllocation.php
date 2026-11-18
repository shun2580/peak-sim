<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Ladder;

use PeakSim\Runtime\Simulate\Predict\AppLimitRisk;
use PeakSim\Runtime\Simulate\Predict\Coefficients;

/**
 * Takes the routes that cannot run to the end under the lab's settings for B out of a target scenario's mix and gives their share to the routes
 * of the same group (simulator-spec.md section 7.4.4). Pure: arrays in, arrays out; no clock, no files, no database.
 *
 * What is taken out: a route that the placeholder side (`app_limit_risk_placeholders`) or the memory side (`app_limit_risk_memory`) marks at the
 * scenario's table size. The CPU-time side drops nothing: B runs with no execution limit, so it has no mark; it is a stop instead
 * ({@see self::slowRoutes}).
 *
 * A group is the routes of one scenario that carry the same rps in the scenario file (the same share of the total, to 12 decimals): the
 * routes of the 80% side, the routes of the 20% side, or all the routes of an even mix. The file has no other notion of a group, and the
 * five scenarios s-1 .. s-5 are written exactly so. A route alone at its rps is a group of its own: if it is marked its group is empty, and
 * the run stops rather than guess where its share goes.
 *
 * Redistribution: the group keeps its total. Each route that stays gets its rps times (the group's rps in the file / the staying routes'
 * rps in the file), so the staying routes keep their proportions to each other. The sum of the scenario's rps is therefore unchanged, and the
 * naive estimate (section 7.3), which depends on that sum only, is the same as before: it is given for the same allocation.
 *
 * Boundaries: a mark is the mark of the risk classes ({@see \PeakSim\Runtime\Simulate\Predict\AppPlaceholderRisk}: more than the limit; memory:
 * at or above the limit), taken as they give it. A route that a risk class could not judge (no figure) is not taken out; the record lists it.
 */
final class RouteAllocation
{
    public const EXIT_EMPTY_GROUP = 38;

    public const EXIT_SLOW_REQUEST = 39;

    public const EXIT_NO_LIMITS = 40;

    /** The time one request of B may take before the load tool and the web server give up (simulator-spec.md section 7.4.4). */
    public const DEFAULT_REQUEST_TIMEOUT_S = 1800.0;

    /** Shares of the total closer than this are the same share. */
    private const SHARE_DECIMALS = 12;

    public const DROP_RULE = 'a route marked by the placeholder side or the memory side at the scenario\'s table size is taken out of the mix; the rps of the routes of its group (the routes with the same rps in the scenario file) stay in the group, shared among the routes that stay in proportion to their rps in the file; the sum of rps is unchanged, so the naive estimate is the same';

    public const STOP_RULE = 'the run stops (no prediction) when a group is left with no route, or when the php CPU time the model gives for one request of a route (the CPU-time mark\'s bound) is above half of the request timeout';

    /**
     * @param array<string, mixed>                                                                      $scenario        a target scenario of the loaded input
     * @param array{marked_routes: list<string>, routes: list<array<string, mixed>>}                    $placeholderRisk the placeholder risk of the scenario's routes
     * @param array{marked_routes: list<string>, routes: list<array<string, mixed>>}                    $memoryRisk      the memory risk of the scenario's routes
     *
     * @return array{scenario: array<string, mixed>, record: array<string, mixed>, emptied: list<list<string>>}
     *         `scenario` is the scenario with the dropped routes out and the others' rps raised; `emptied` lists the groups (as lists of routes) that
     *         are left with no route (then `scenario` is the one given)
     */
    public static function apply(array $scenario, array $placeholderRisk, array $memoryRisk): array
    {
        $reasons = [];
        foreach ($placeholderRisk['marked_routes'] as $name) {
            $reasons[(string) $name][] = 'placeholders';
        }
        foreach ($memoryRisk['marked_routes'] as $name) {
            $reasons[(string) $name][] = 'memory';
        }
        $routes = array_values((array) $scenario['routes']);
        $total = 0.0;
        foreach ($routes as $r) {
            $total += (float) $r['rps'];
        }
        $groups = [];
        foreach ($routes as $i => $r) {
            $groups[self::shareKey((float) $r['rps'], $total)][] = $i;
        }

        $rpsAfter = [];
        $dropped = [];
        $emptied = [];
        $groupRecords = [];
        foreach ($groups as $members) {
            $drop = array_values(array_filter($members, static fn (int $i): bool => isset($reasons[(string) $routes[$i]['route']])));
            $keep = array_values(array_diff($members, $drop));
            $groupSum = 0.0;
            foreach ($members as $i) {
                $groupSum += (float) $routes[$i]['rps'];
            }
            $keepSum = 0.0;
            foreach ($keep as $i) {
                $keepSum += (float) $routes[$i]['rps'];
            }
            if ($drop !== [] && $keep === [] && $groupSum > 0.0) {
                $emptied[] = array_map(static fn (int $i): string => (string) $routes[$i]['route'], $members);
            }
            $factor = ($drop !== [] && $keepSum > 0.0) ? $groupSum / $keepSum : 1.0;
            foreach ($keep as $i) {
                $rpsAfter[$i] = (float) $routes[$i]['rps'] * $factor;
            }
            foreach ($drop as $i) {
                $dropped[] = [
                    'route' => (string) $routes[$i]['route'],
                    'reasons' => $reasons[(string) $routes[$i]['route']],
                    'rps_in_file' => (float) $routes[$i]['rps'],
                ];
            }
            if ($drop !== []) {
                $groupRecords[] = [
                    'rps_each_in_file' => (float) $routes[$members[0]]['rps'],
                    'routes_in_file' => array_map(static fn (int $i): string => (string) $routes[$i]['route'], $members),
                    'dropped' => array_map(static fn (int $i): string => (string) $routes[$i]['route'], $drop),
                    'kept' => array_map(static fn (int $i): string => (string) $routes[$i]['route'], $keep),
                    'group_rps_in_file' => $groupSum,
                    'kept_rps_in_file' => $keepSum,
                    'factor' => $factor,
                ];
            }
        }

        $kept = [];
        $before = [];
        $after = [];
        foreach ($routes as $i => $r) {
            $before[(string) $r['route']] = (float) $r['rps'];
            if (array_key_exists($i, $rpsAfter)) {
                $r['rps'] = $rpsAfter[$i];
                $after[(string) $r['route']] = $rpsAfter[$i];
                $kept[] = $r;
            }
        }
        $made = $scenario;
        $made['routes'] = $kept;

        return [
            'scenario' => $emptied === [] ? $made : $scenario,
            'emptied' => $emptied,
            'record' => [
                'rule' => self::DROP_RULE,
                'dropped_routes' => $dropped,
                'groups_changed' => $groupRecords,
                'rps_in_file' => $before,
                'rps_after' => $after,
                'rps_total_in_file' => array_sum($before),
                'rps_total_after' => array_sum($after),
                'not_judged' => [
                    'placeholders' => self::notJudged($placeholderRisk),
                    'memory' => self::notJudged($memoryRisk),
                ],
                'naive_estimate' => 'unchanged: it scales with the sum of rps only, and the sum of rps is the same',
            ],
        ];
    }

    /**
     * The routes whose one request the model gives more than `$halfTimeout` seconds of php CPU time (the bound of the CPU-time mark,
     * {@see AppLimitRisk}), whether or not they are taken out of the mix: a request that long is not one the settings of B can be said to run.
     * Exactly `$halfTimeout` is not over it. A route with no cost cannot be judged and is not listed here.
     *
     * @param list<array<string, mixed>> $routes the routes of one scenario as the loaded input gives them
     *
     * @return list<array{route: string, php_cpu_bound_s: float}>
     */
    public static function slowRoutes(array $routes, Coefficients $c, float $halfTimeout): array
    {
        $slow = [];
        foreach (AppLimitRisk::evaluate($routes, $c, $halfTimeout)['routes'] as $row) {
            if ($row['status'] !== AppLimitRisk::NOT_EVALUATED && (float) $row['php_cpu_bound_s'] > $halfTimeout) {
                $slow[] = ['route' => (string) $row['route'], 'php_cpu_bound_s' => (float) $row['php_cpu_bound_s']];
            }
        }

        return $slow;
    }

    /**
     * The longest php CPU time bound over a scenario's routes (null when no route has a cost), for the record.
     *
     * @param list<array<string, mixed>> $routes
     *
     * @return array{route: string, php_cpu_bound_s: float}|null
     */
    public static function longestRequest(array $routes, Coefficients $c, float $halfTimeout): ?array
    {
        $best = null;
        foreach (AppLimitRisk::evaluate($routes, $c, $halfTimeout)['routes'] as $row) {
            if ($row['status'] !== AppLimitRisk::NOT_EVALUATED && ($best === null || (float) $row['php_cpu_bound_s'] > $best['php_cpu_bound_s'])) {
                $best = ['route' => (string) $row['route'], 'php_cpu_bound_s' => (float) $row['php_cpu_bound_s']];
            }
        }

        return $best;
    }

    /** @param array{routes: list<array<string, mixed>>} $risk @return list<string> */
    private static function notJudged(array $risk): array
    {
        $names = [];
        foreach ($risk['routes'] as $row) {
            if (($row['status'] ?? null) === 'not_evaluated') {
                $names[] = (string) $row['route'];
            }
        }

        return $names;
    }

    private static function shareKey(float $rps, float $total): string
    {
        return $total > 0.0 ? sprintf('%.' . self::SHARE_DECIMALS . 'F', round($rps / $total, self::SHARE_DECIMALS)) : '0';
    }
}
