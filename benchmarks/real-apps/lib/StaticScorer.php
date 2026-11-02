<?php

declare(strict_types=1);

namespace PeakSim\Benchmarks\RealApps;

/**
 * Scores the static analysis (the JSON that `peak-sim analyze --format=json` writes) against the fixed answers.
 *
 * For every answered axis of every answered route the static output falls into exactly one category:
 *
 *   match                the output is determined (O(1), O(N), O(N*M)) and equals the answer
 *   bound_tight          the output is unknown, and its lower bound equals the answer
 *   bound_loose          the output is unknown, and its lower bound is below the answer (valid, but weaker)
 *   unknown              the output is unknown and has no lower bound (nothing was claimed)
 *   bound_over           the lower bound is above the answer (the "lower bound" is more than the code does)
 *   overclaim            the output is determined and heavier than the answer
 *   missed               the output is determined and lighter than the answer (said light, actually heavy)
 *
 * and, for the answers that are not a single fixed growth:
 *
 *   conditional_ok       the answer depends on a condition; the output is determined and equals the heavy side
 *   missed_if_condition  the answer depends on a condition; the output is determined and lighter than the heavy side
 *   bound_on_conditional the answer depends on a condition; the output gives a lower bound that rests on that
 *                        condition (not above the heavy side, but not always true either)
 *   unverifiable         the answer is only "at least"; the output is above that floor, so nothing can be said
 *
 * Not scored: truth_undecided (the answer is not decided), route_missing and route_ambiguous (the route is not once
 * in the analysis).
 */
final class StaticScorer
{
    public const CATEGORIES = [
        'match', 'bound_tight', 'conditional_ok', 'bound_loose', 'unknown', 'bound_on_conditional', 'unverifiable',
        'bound_over', 'overclaim', 'missed', 'missed_if_condition',
        'truth_undecided', 'route_missing', 'route_ambiguous',
    ];

    /**
     * The category of one axis. $truthAxis is an entry of truth route 'axes'; $growth and $lowerBound are the static output.
     *
     * @param array<string, mixed> $truthAxis
     */
    public static function classify(array $truthAxis, ?string $growth, ?string $lowerBound): string
    {
        $truthGrowth = $truthAxis['growth'] ?? null;
        if ($truthGrowth === null || ($truthAxis['decided'] ?? true) === false) {
            return 'truth_undecided';
        }

        $conditional = $truthGrowth === 'conditional';
        $target = $conditional ? (int) $truthAxis['growth_if_condition_degree'] : (int) Truth::degree($truthGrowth);
        $atLeast = ($truthAxis['at_least'] ?? false) === true;

        $got = Truth::degree($growth);           // null when the output is unknown
        $bound = Truth::degree($lowerBound);     // null when there is no lower bound
        if ($bound === 0) {
            $bound = null;                       // a bound of O(1) says nothing
        }

        if ($got !== null) {
            if ($conditional) {
                return $got < $target ? 'missed_if_condition' : ($got === $target ? 'conditional_ok' : 'overclaim');
            }
            if ($got < $target) {
                return 'missed';
            }
            if ($got === $target) {
                return 'match';
            }

            return $atLeast ? 'unverifiable' : 'overclaim';
        }

        if ($bound === null) {
            return 'unknown';
        }
        if ($conditional) {
            return $bound > $target ? 'bound_over' : 'bound_on_conditional';
        }
        if ($bound === $target) {
            return 'bound_tight';
        }

        return $bound < $target ? 'bound_loose' : ($atLeast ? 'unverifiable' : 'bound_over');
    }

    /**
     * @param array<string, mixed> $truth     a loaded truth document
     * @param array<string, array<string, mixed>> $analyses  app => decoded analysis JSON
     * @param array{apps?: list<string>, sets?: list<string>, axes?: list<string>} $filter
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function score(array $truth, array $analyses, array $filter = []): array
    {
        $index = [];
        foreach ($analyses as $app => $analysis) {
            foreach ($analysis['routes'] ?? [] as $route) {
                foreach ($route['methods'] ?? [] as $method) {
                    $index[$app][$method . ' ' . $route['route']][] = $route;
                }
            }
        }

        $rows = [];
        foreach ($truth['routes'] as $tr) {
            if (isset($filter['apps']) && $filter['apps'] !== [] && !in_array($tr['app'], $filter['apps'], true)) {
                continue;
            }
            if (isset($filter['sets']) && $filter['sets'] !== [] && array_intersect($filter['sets'], $tr['sets'] ?? []) === []) {
                continue;
            }
            if (!isset($analyses[$tr['app']])) {
                continue; // no analysis was given for this app: it is not part of this run
            }
            $found = $index[$tr['app']][$tr['method'] . ' ' . $tr['uri']] ?? [];
            foreach ($tr['axes'] as $axisName => $axis) {
                if (isset($filter['axes']) && $filter['axes'] !== [] && !in_array($axisName, $filter['axes'], true)) {
                    continue;
                }
                $row = [
                    'id' => $tr['id'], 'app' => $tr['app'], 'sets' => $tr['sets'] ?? [], 'axis' => $axisName,
                    'truth' => $axis['growth'] ?? null, 'static' => null, 'lower_bound' => null,
                ];
                if (count($found) === 0) {
                    $row['category'] = 'route_missing';
                } elseif (count($found) > 1) {
                    $row['category'] = 'route_ambiguous';
                } else {
                    $out = $found[0][$axisName] ?? [];
                    $row['static'] = $out['growth'] ?? 'unknown';
                    $row['lower_bound'] = $out['lower_bound'] ?? null;
                    $row['category'] = self::classify($axis, $row['static'], $row['lower_bound']);
                }
                $rows[] = $row;
            }
        }

        return ['rows' => $rows, 'summary' => self::summarise($rows)];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    public static function summarise(array $rows): array
    {
        $zero = array_fill_keys(self::CATEGORIES, 0);
        $groups = ['all' => $zero];
        foreach ($rows as $row) {
            $keys = ['all', 'app:' . $row['app'], 'axis:' . $row['axis']];
            foreach ($row['sets'] as $set) {
                $keys[] = 'set:' . $set;
            }
            foreach ($keys as $key) {
                $groups[$key] ??= $zero;
                $groups[$key][$row['category']]++;
            }
        }
        ksort($groups);

        $summary = [];
        foreach ($groups as $key => $counts) {
            $scored = array_sum($counts) - $counts['truth_undecided'] - $counts['route_missing'] - $counts['route_ambiguous'];
            $summary[$key] = [
                'answers' => array_sum($counts),
                'scored' => $scored,
                'agree' => $counts['match'] + $counts['bound_tight'] + $counts['conditional_ok'],
                'unknown' => $counts['unknown'],
                'bound_over' => $counts['bound_over'],
                'missed' => $counts['missed'] + $counts['missed_if_condition'],
                'counts' => $counts,
            ];
        }

        return $summary;
    }
}
