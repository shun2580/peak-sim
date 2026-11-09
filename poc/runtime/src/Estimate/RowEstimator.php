<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Estimate;

use PeakSim\Runtime\Judge\CostFit;

/**
 * The rows a route reads at a target size N, by the candidates H1 to H3 of `docs/research/explain-estimate.md`:
 *
 *   estimate(route) = sum over statement types s of  per_exec(s, N) x count(s, N)
 *
 * `count(s, N)` is the type's fitted count formula from `capture.json` (the same for every candidate). Only `per_exec` differs:
 *
 *   H1  the type's measured rows per run at the capture's scales (rows_sum / runs) fitted with CostFit (a + b*N^k), put at N.
 *   H2  as H1, but a type marked `plan_changes_at_scale` or `plan_unstable` uses the measured value at the largest capture
 *       scale x E(s, N) / E(s, that scale). E is `PlanReads::perExecution` of the plan on the database of that size.
 *   H3  every type uses the ratio of H2.
 *
 *   H1_RANGE  H1 for the point, and for the upper side the value H2 gives to a marked type (the others keep their H1 value). A route
 *       with no marked statement in its EXPLAIN has no upper side (`rows_examined_upper` is null); the point is H1 for every route. The
 *       marked routes are those of `MarkedRoutes` (section 7.1), taken from every statement of the route's EXPLAIN.
 *
 * E(s, N) (section 9.3): a type marked plan_unstable takes the heaviest of the statistics states that were taken (D107); any other
 * type takes the default state. E(s, small) is the default state at the small size.
 *
 * A type that cannot take the ratio falls back to H1, and the fall is counted with its reason (never a silent 0). The reasons, with the
 * item of section 9.3 each one is, checked in this order:
 *   explain_unavailable:<why>, e_small_not_taken, no_explain_entry          item 2 (E(s, small) could not be taken)
 *   e_n_not_taken, e_not_taken_unrecognised_plan:<why>                       item 3 (E(s, N) could not be taken) / item 2 (an unrecognised shape)
 *   e_small_zero                                                             item 1 (E(s, small) = 0)
 *   per_exec_measured_undefined                                              item 4
 * E(s, N) = 0 with E(s, small) above 0 is not a fall: the ratio is 0 and so is the estimate of the type.
 *
 * The fit of a type's rows per run needs three scales, as CostFit does (coefficients rounded with CostFit::sig). A type that ran at fewer
 * scales than three keeps the value of its largest scale (`held_from_largest_scale`), counted in `per_exec_held`. A type that read no rows anywhere in the capture
 * is unavailable and makes its route incomplete.
 */
final class RowEstimator
{
    public const H1 = 'H1';

    public const H2 = 'H2';

    public const H3 = 'H3';

    /** H1 for the point and, for the types that are marked, the value H2 gives as the upper side (the form of the 200,000-row test, section 7.1). */
    public const H1_RANGE = 'H1_RANGE';

    public const CANDIDATES = [self::H1, self::H2, self::H3, self::H1_RANGE];

    public const SCHEMA_VERSION = '1';

    /**
     * @param array<string, mixed>      $capture     the decoded capture.json
     * @param array<string, mixed>      $explain     the decoded explain.json of the database of N rows (it also holds the small side)
     * @param array<string, mixed>|null $predictions the decoded predictions.json of bin/predict at N (queries, peak memory at route level), or null
     * @param bool                      $withFormulas add the formulas themselves (`formula` per type; `table`, `axis`, `peak_memory_formula` per route;
     *                                  `capture_scales` and `route_index` at the top) so a reader can evaluate them at another size. Nothing else changes.
     *
     * @return array<string, mixed>
     */
    public static function estimate(array $capture, array $explain, string $candidate, ?int $n = null, ?array $predictions = null, bool $withFormulas = false): array
    {
        if (!in_array($candidate, self::CANDIDATES, true)) {
            throw new \InvalidArgumentException('the candidate must be one of ' . implode(', ', self::CANDIDATES));
        }
        $n ??= (int) ($explain['scale'] ?? 0);
        if ($n < 1) {
            throw new \InvalidArgumentException('the size N must be a positive integer');
        }
        $explainRoutes = [];
        foreach ((array) ($explain['routes'] ?? []) as $r) {
            if (is_array($r) && isset($r['route'])) {
                $explainRoutes[(string) $r['route']] = $r;
            }
        }
        $predicted = [];
        foreach ((array) ($predictions['routes'] ?? []) as $r) {
            if (is_array($r) && isset($r['route'])) {
                $predicted[(string) $r['route']] = $r;
            }
        }
        $representative = (int) ($capture['representative_scale'] ?? 0);
        $routes = [];
        $fallbacks = [];
        $byItem = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $routesByItem = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $held = 0;
        $ratioUsed = 0;
        foreach ((array) ($capture['routes'] ?? []) as $cr) {
            if (!is_array($cr) || !isset($cr['route'])) {
                continue;
            }
            $route = self::route($cr, $explainRoutes[(string) $cr['route']] ?? null, $candidate, $n, $representative, $withFormulas);
            $seen = [];
            foreach ($route['fallbacks'] as $reason => $count) {
                $fallbacks[$reason] = ($fallbacks[$reason] ?? 0) + $count;
                $byItem[self::item($reason)] += $count;
                $seen[self::item($reason)] = true;
            }
            foreach (array_keys($seen) as $item) {
                $routesByItem[$item]++;
            }
            $held += $route['per_exec_held'];
            $ratioUsed += $route['ratio_used'];
            $p = $predicted[(string) $cr['route']]['quantities'] ?? null;
            $route['peak_memory'] = self::predicted($p['peak_memory'] ?? null);
            $route['queries_route_fit'] = self::predicted($p['queries'] ?? null);
            if ($withFormulas) {
                $pr = $predicted[(string) $cr['route']] ?? [];
                $route['table'] = isset($pr['table']) ? (string) $pr['table'] : null;
                $route['axis'] = isset($pr['axis']) ? (string) $pr['axis'] : null;
                $route['peak_memory_formula'] = self::formulaOf($p['peak_memory'] ?? null);
            }
            $routes[] = $route;
        }
        usort($routes, static fn (array $x, array $y): int => strcmp($x['route'], $y['route']));
        ksort($fallbacks);

        $extra = [];
        if ($withFormulas) {
            $index = [];
            foreach ($predicted as $name => $pr) {
                $index[$name] = ['route' => $name, 'axis' => isset($pr['axis']) ? (string) $pr['axis'] : null, 'table' => isset($pr['table']) ? (string) $pr['table'] : null, 'in_estimate' => false];
            }
            foreach ($routes as $r) {
                $index[$r['route']] = ['route' => $r['route'], 'axis' => $r['axis'], 'table' => $r['table'], 'in_estimate' => true];
            }
            ksort($index);
            $extra = [
                'capture_scales' => array_values(array_map('intval', (array) ($capture['scales'] ?? []))),
                'route_index' => array_values($index),
            ];
        }

        return $extra + [
            'schema_version' => self::SCHEMA_VERSION,
            'candidate' => $candidate,
            'at' => $n,
            'small_scale' => $representative,
            'app' => $capture['app'] ?? null,
            'totals' => [
                'routes' => count($routes),
                'complete' => count(array_filter($routes, static fn (array $r): bool => $r['complete'])),
                'ratio_used' => $ratioUsed,
                'fell_back_to_h1' => array_sum($fallbacks),
                'fell_back_by_reason' => $fallbacks,
                'fell_back_by_item' => $byItem,
                'routes_with_fall_by_item' => $routesByItem,
                'per_exec_held' => $held,
            ],
            'routes' => $routes,
        ];
    }

    /** The item of section 9.3 a fallback reason belongs to (1: E(small) = 0; 2: E(small) not taken; 3: E(N) not taken; 4: no measured value). */
    private static function item(string $reason): int
    {
        return match (true) {
            $reason === 'e_small_zero' => 1,
            $reason === 'e_n_not_taken' => 3,
            $reason === 'per_exec_measured_undefined' => 4,
            default => 2,
        };
    }

    /**
     * @param array<string, mixed>      $cr
     * @param array<string, mixed>|null $er
     *
     * @return array<string, mixed>
     */
    private static function route(array $cr, ?array $er, string $candidate, int $n, int $representative, bool $withFormulas = false): array
    {
        $status = (string) ($cr['status'] ?? 'captured');
        $out = [
            'route' => (string) $cr['route'],
            'status' => $status,
            'complete' => true,
            'queries' => 0.0,
            'rows_examined' => 0.0,
            'types' => [],
            'unavailable' => [],
            'fallbacks' => [],
            'per_exec_held' => 0,
            'ratio_used' => 0,
        ];
        $statements = [];
        foreach ((array) ($er['statements'] ?? []) as $s) {
            if (is_array($s) && isset($s['id'])) {
                $statements[(string) $s['id']] = $s;
            }
        }
        foreach ((array) ($cr['statement_types'] ?? []) as $ty) {
            $type = self::type((array) $ty, $statements[(string) ($ty['id'] ?? '')] ?? null, $candidate, $n, $representative);
            if ($withFormulas && !isset($type['unavailable'])) {
                $type['formula'] = self::formula((array) $ty, $type);
            }
            if (isset($type['unavailable'])) {
                $out['complete'] = false;
                $out['unavailable'][] = ['id' => $type['id'], 'pattern' => $type['pattern'], 'reason' => $type['unavailable']];
            } else {
                $out['queries'] += $type['count_at_n'];
                $out['rows_examined'] += $type['per_exec'] * $type['count_at_n'];
                if ($type['basis'] === 'ratio' || ($type['upper_basis'] ?? null) === 'ratio') {
                    $out['ratio_used']++;
                }
                if (isset($type['fallback'])) {
                    $out['fallbacks'][$type['fallback']] = ($out['fallbacks'][$type['fallback']] ?? 0) + 1;
                }
                if (($type['per_exec_h1_basis'] ?? null) === 'held_from_largest_scale') {
                    $out['per_exec_held']++;
                }
            }
            $out['types'][] = $type;
        }
        if ($status !== 'captured') {
            $out['complete'] = false;
        }
        if ($candidate === self::H1_RANGE) {
            $out['marked'] = MarkedRoutes::isMarked($er);
            $upper = 0.0;
            foreach ($out['types'] as $t) {
                if (isset($t['unavailable'])) {
                    continue;
                }
                $upper += ($t['per_exec_upper'] ?? $t['per_exec']) * $t['count_at_n'];
            }
            // the upper side exists for a marked route only; a marked route whose statements are not all available has an incomplete upper side, like its point
            $out['rows_examined_upper'] = $out['marked'] ? $upper : null;
        }

        return $out;
    }

    /**
     * @param array<string, mixed>      $ty
     * @param array<string, mixed>|null $explained the statement entry of explain.json, or null
     *
     * @return array<string, mixed>
     */
    private static function type(array $ty, ?array $explained, string $candidate, int $n, int $representative): array
    {
        $rec = ['id' => (string) ($ty['id'] ?? ''), 'kind' => (string) ($ty['kind'] ?? ''), 'pattern' => substr((string) ($ty['pattern'] ?? ''), 0, 160)];
        $fit = (array) ($ty['count_fit'] ?? []);
        if (($fit['fit_status'] ?? null) !== CostFit::OK || !isset($fit['a'], $fit['b'], $fit['k'])) {
            return $rec + ['unavailable' => 'count_formula:' . (string) ($fit['fit_status'] ?? 'missing')];
        }
        $count = CostFit::evaluate((float) $fit['a'], (float) $fit['b'], (float) $fit['k'], $n);
        $rec['count_at_n'] = $count;

        $series = self::series((array) ($ty['per_scale'] ?? []));
        if ($series === []) {
            return $rec + ['unavailable' => 'no_rows_read_in_capture'];
        }
        if (count($series) >= CostFit::MIN_SCALES) {
            $f = CostFit::fit(array_keys($series), array_values($series));
            $h1 = CostFit::evaluate(CostFit::sig($f['a']), CostFit::sig($f['b']), CostFit::sig($f['k']), $n);
            $rec['per_exec_h1_basis'] = 'fit';
        } else {
            $h1 = $series[max(array_keys($series))];
            $rec['per_exec_h1_basis'] = 'held_from_largest_scale';
        }
        $rec['per_exec_h1'] = $h1;

        $marks = (array) ($explained['marks'] ?? []);
        $marked = ($marks['plan_changes_at_scale'] ?? false) === true || ($marks['plan_unstable'] ?? false) === true;
        $rec['marks'] = ['plan_changes_at_scale' => ($marks['plan_changes_at_scale'] ?? false) === true, 'plan_unstable' => ($marks['plan_unstable'] ?? false) === true];
        if ($candidate === RowEstimator::H1_RANGE) {
            if (!$marked) {
                return $rec + ['per_exec' => $h1, 'basis' => 'h1'];
            }
            // a marked type: the point stays H1, the upper side is what H2 gives (its fall-back to H1 is counted, never silent)
            $h2 = self::type($ty, $explained, RowEstimator::H2, $n, $representative);
            $upper = ['per_exec' => $h1, 'basis' => 'h1', 'per_exec_upper' => $h2['per_exec'], 'upper_basis' => $h2['basis']];
            if (isset($h2['fallback'])) {
                $upper['fallback'] = $h2['fallback'];
            }
            foreach (['e_at_n', 'e_at_small', 'measured_small'] as $key) {
                if (isset($h2[$key])) {
                    $upper[$key] = $h2[$key];
                }
            }

            return $rec + $upper;
        }
        if ($candidate !== RowEstimator::H1 && $explained === null) {
            // no plan and no marks: whether the type would take the ratio is not known, so it is counted, not passed over
            return $rec + ['per_exec' => $h1, 'basis' => 'h1', 'fallback' => 'no_explain_entry'];
        }
        if ($candidate === RowEstimator::H1 || ($candidate === RowEstimator::H2 && !$marked)) {
            return $rec + ['per_exec' => $h1, 'basis' => 'h1'];
        }

        // the ratio: measured rows per run at the largest capture scale x E(s, N) / E(s, that scale)   (section 9.3)
        $fallback = null;
        $measuredSmall = $representative > 0 && isset($series[$representative]) ? $series[$representative] : null;
        $eN = null;
        $eSmall = null;
        $unstable = $marks['plan_unstable'] ?? false;
        if (isset($marks['explain_unavailable']) && $marks['explain_unavailable'] !== null && $marks['explain_unavailable'] !== '') {
            $fallback = 'explain_unavailable:' . (string) $marks['explain_unavailable'];
        } else {
            try {
                $eSmall = self::stateReads((array) ($explained['small_scale'] ?? []), ['default']);
                if ($eSmall === null) {
                    $fallback = 'e_small_not_taken';
                }
                // N's side: a plan_unstable type takes the heaviest state that was taken (D107); any other type takes the default state
                $eN = self::stateReads((array) ($explained['target_scale'] ?? []), $unstable === true ? null : ['default'], (array) ($marks['unavailable_states'] ?? []));
                if ($fallback === null && $eN === null) {
                    $fallback = 'e_n_not_taken';
                }
            } catch (UnrecognisedPlan $e) {
                $fallback = 'e_not_taken_unrecognised_plan:' . $e->getMessage();
            }
        }
        if ($fallback === null && $eSmall !== null && $eSmall <= 0.0) {
            $fallback = 'e_small_zero';
        }
        if ($fallback === null && $measuredSmall === null) {
            $fallback = 'per_exec_measured_undefined';
        }
        if ($fallback !== null) {
            return $rec + ['per_exec' => $h1, 'basis' => 'h1', 'fallback' => $fallback];
        }
        $rec['e_at_n'] = $eN;
        $rec['e_at_small'] = $eSmall;
        $rec['measured_small'] = $measuredSmall;

        return $rec + ['per_exec' => (float) $measuredSmall * (float) $eN / (float) $eSmall, 'basis' => 'ratio'];
    }

    /**
     * The formulas behind a type's point and upper side, for a reader that puts another size in. The same numbers `type` used.
     *
     * `count`: a, b, k of the count fit. `per_exec`: the H1 fit (a, b, k as rounded by CostFit::sig) or, with fewer scales than the fit needs,
     * the value held from the largest scale. `upper`: for a type with an upper side, its value at the size of this estimate, the H1 value there
     * and their ratio (null when the H1 value is 0: a reader then holds the upper value as it is).
     *
     * @param array<string, mixed> $ty
     * @param array<string, mixed> $type the record `type` made
     *
     * @return array<string, mixed>
     */
    private static function formula(array $ty, array $type): array
    {
        $fit = (array) ($ty['count_fit'] ?? []);
        $series = self::series((array) ($ty['per_scale'] ?? []));
        if (count($series) >= CostFit::MIN_SCALES) {
            $f = CostFit::fit(array_keys($series), array_values($series));
            $perExec = ['basis' => 'fit', 'a' => CostFit::sig($f['a']), 'b' => CostFit::sig($f['b']), 'k' => CostFit::sig($f['k'])];
        } else {
            $perExec = ['basis' => 'held_from_largest_scale', 'value' => $series[max(array_keys($series))]];
        }
        $out = [
            'count' => ['a' => (float) $fit['a'], 'b' => (float) $fit['b'], 'k' => (float) $fit['k']],
            'per_exec' => $perExec,
            'scales' => array_keys($series),
            'upper' => null,
        ];
        if (isset($type['per_exec_upper'])) {
            $h1 = (float) $type['per_exec_h1'];
            $up = (float) $type['per_exec_upper'];
            $out['upper'] = ['at_n' => $up, 'h1_at_n' => $h1, 'ratio' => $h1 > 0.0 ? $up / $h1 : null];
        }

        return $out;
    }

    /**
     * a, b, k of a route-level quantity of predictions.json, or null when it was not predicted.
     *
     * @param array<string, mixed>|null $q
     *
     * @return array{a: float, b: float, k: float}|null
     */
    private static function formulaOf(?array $q): ?array
    {
        if ($q === null || ($q['status'] ?? null) !== 'predicted' || !isset($q['a'], $q['b'], $q['k'])) {
            return null;
        }

        return ['a' => (float) $q['a'], 'b' => (float) $q['b'], 'k' => (float) $q['k']];
    }

    /**
     * Measured rows per run, by scale: rows_sum / (runs that were read), for the scales where the type ran and was read.
     *
     * @param array<array-key, mixed> $perScale
     *
     * @return array<int, float> ascending by scale
     */
    private static function series(array $perScale): array
    {
        $series = [];
        foreach ($perScale as $scale => $v) {
            if (!is_array($v) || !isset($v['count']) || !array_key_exists('rows_sum', $v) || $v['rows_sum'] === null) {
                continue;
            }
            $read = (int) $v['count'] - (int) ($v['unread'] ?? 0);
            if ($read > 0) {
                $series[(int) $scale] = (float) $v['rows_sum'] / $read;
            }
        }
        ksort($series);

        return $series;
    }

    /**
     * The largest E over the named states that have a plan; null when none of them has.
     *
     * @param array<string, mixed> $states      state name => {summary, raw}
     * @param list<string>|null    $take        the states to take the largest of; null: every state there is
     * @param list<mixed>          $unavailable the states the explain run could not read (left out)
     *
     * @throws UnrecognisedPlan
     */
    private static function stateReads(array $states, ?array $take, array $unavailable = []): ?float
    {
        $best = null;
        foreach ($take ?? array_keys($states) as $name) {
            $state = $states[$name] ?? null;
            if (in_array($name, $unavailable, true) || !is_array($state) || !isset($state['raw']) || !is_array($state['raw']) || $state['raw'] === []) {
                continue;
            }
            $e = PlanReads::perExecution($state['raw']);
            $best = $best === null ? $e : max($best, $e);
        }

        return $best;
    }

    /**
     * @param array<string, mixed>|null $q a quantity of predictions.json
     *
     * @return array{status: string, value?: float, reason?: string}
     */
    private static function predicted(?array $q): array
    {
        if ($q === null) {
            return ['status' => 'not_given'];
        }
        if (($q['status'] ?? null) !== 'predicted' || !isset($q['value'])) {
            return ['status' => 'not_predicted', 'reason' => (string) ($q['reason'] ?? $q['fit_status'] ?? 'missing')];
        }

        return ['status' => 'predicted', 'value' => (float) $q['value']];
    }
}
