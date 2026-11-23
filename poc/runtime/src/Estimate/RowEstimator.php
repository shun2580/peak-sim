<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Estimate;

use PeakSim\Runtime\Explain\PlanSummary;
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
 * H1_RANGE also gives a route that reads in chunks by `offset` its mark `offset_chunk_fit` (`OffsetChunkFit`): made from the capture and these figures, it
 * changes none of them, and a route with no `offset` statement carries no entry.
 *
 * H1_RANGE also carries the two rules of `docs/research/extrapolation-test-3-causes.md` (sections 7.1 and 7.2), written from the records of the
 * sizes already seen. Both change the point of a marked type and nothing else; H1, H2 and H3 are left as they were defined.
 *
 *   P1  a type marked `plan_changes_at_scale` whose plan on the database of N rows (default state) reads one table by a full scan (`ALL`, no join,
 *       no subquery), at a size above the largest scale: the point is the rows of that scan (`rows_examined_per_scan`). A plan that reads more than
 *       one table is not read this way.
 *   P2  a type marked `plan_changes_at_scale` or `plan_unstable` whose rows per run were fitted (three scales or more), at a size above the largest scale:
 *       the point is not below the value at the largest scale carried along a line through the origin (value x N / that scale).
 *
 * P1 is applied first, then P2. A type that neither rule moves keeps its H1 value, byte for byte. The fit's value stays in `per_exec_h1`; a type
 * the rules moved says so in `point_rule` (`P1`, `P2`) and its upper side is not below its point.
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
     * @param bool                      $axesOnly    read only `route`, `axis` and `table` of the predictions (the part the static analysis decides) and none of their
     *                                  `quantities`: the route-level peak memory and query fit stay `not_given` and there is no `peak_memory_formula`. For an estimate whose
     *                                  fits were not made in the way the capture was (no figure of an old measurement gets in). Without it nothing changes.
     * @param bool                      $withPlaceholders add `placeholders` to each type that has a representative statement in the capture: the number of values that
     *                                  statement was sent with, the part of them that does not depend on a list, and the scale it was taken at (the input of the
     *                                  placeholder side of the `app_limit_risk` mark). It only adds that key; every other figure is the same with and without it.
     *
     * @return array<string, mixed>
     */
    public static function estimate(array $capture, array $explain, string $candidate, ?int $n = null, ?array $predictions = null, bool $withFormulas = false, bool $axesOnly = false, bool $withPlaceholders = false): array
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
                if ($axesOnly) {
                    unset($r['quantities']);
                }
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
            $route = self::route($cr, $explainRoutes[(string) $cr['route']] ?? null, $candidate, $n, $representative, $withFormulas, $withPlaceholders);
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

    /**
     * What the capture holds about the number of values one statement of this type is sent with (the representative statement: the text of the largest scale
     * that read the most rows, with its bindings). `values` is how many values that statement carried and `scale` the scale it was taken at. `fixed` is the part of
     * them that no list holds: the `?` of the statement's text outside `in (?, ?, ...)` lists, and none for an `insert ... values` (the number of its rows is the
     * list). The rest, `values - fixed`, is what a list holds. The text is the representative's own, not the type's pattern: a literal the app wrote into the
     * statement (a `limit 20`) is a `?` in the pattern and no value in the statement. Nothing here is fitted: one figure at one scale is all the capture keeps.
     *
     * @param array<string, mixed> $ty a statement type of the capture
     *
     * @return array{scale: int, values: int, fixed: int}|null null when the capture has no representative with its text and bindings, or no scale for it
     */
    private static function placeholders(array $ty): ?array
    {
        $rep = $ty['representative'] ?? null;
        if (!is_array($rep) || !isset($rep['bindings']) || !is_array($rep['bindings']) || !is_string($rep['sql'] ?? null) || !isset($rep['scale']) || (int) $rep['scale'] < 1) {
            return null;
        }
        $values = count($rep['bindings']);
        if (preg_match('/\bvalues\b/i', $rep['sql']) === 1) {
            $fixed = 0;
        } else {
            $listed = 0;
            if (preg_match_all('/\bin\s*\(\s*\?(?:\s*,\s*\?)*\s*\)/i', $rep['sql'], $lists) > 0) {
                foreach ($lists[0] as $list) {
                    $listed += substr_count($list, '?');
                }
            }
            $fixed = max(0, $values - $listed);
        }

        return ['scale' => (int) $rep['scale'], 'values' => $values, 'fixed' => min($fixed, $values)];
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
    private static function route(array $cr, ?array $er, string $candidate, int $n, int $representative, bool $withFormulas = false, bool $withPlaceholders = false): array
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
            $type = self::type((array) $ty, $statements[(string) ($ty['id'] ?? '')] ?? null, $candidate, $n, $representative, $withFormulas);
            if ($withFormulas && !isset($type['unavailable'])) {
                $type['formula'] = self::formula((array) $ty, $type);
            }
            unset($type['e_by_state']); // only the formula carries it; the figures of the type are the same with and without the flag
            if ($withPlaceholders) {
                $placeholders = self::placeholders((array) $ty);
                if ($placeholders !== null) {
                    $type['placeholders'] = $placeholders;
                }
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
            $held = false;
            foreach ($out['types'] as $t) {
                if (isset($t['unavailable'])) {
                    continue;
                }
                $held = $held || isset($t['per_exec_h1_uncapped']);
                $upper += ($t['per_exec_upper'] ?? $t['per_exec']) * $t['count_at_n'];
            }
            // the upper side exists for a marked route only; a marked route whose statements are not all available has an incomplete upper side, like its point
            $out['rows_examined_upper'] = ($out['marked'] || $held) ? $upper : null;
            // the mark of a route that reads in chunks by `offset` (OffsetChunkFit): a route with no such statement carries no entry, so its output is as it was
            $offsetFit = OffsetChunkFit::ofRoute($cr, $out['types']);
            if ($offsetFit !== null) {
                $out[OffsetChunkFit::MARK] = $offsetFit;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>      $ty
     * @param array<string, mixed>|null $explained the statement entry of explain.json, or null
     *
     * @return array<string, mixed>
     */
    private static function type(array $ty, ?array $explained, string $candidate, int $n, int $representative, bool $withFormulas = false): array
    {
        $rec = ['id' => (string) ($ty['id'] ?? ''), 'kind' => (string) ($ty['kind'] ?? ''), 'pattern' => (string) ($ty['pattern'] ?? '')];
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
        $uncapped = null;
        if (count($series) >= CostFit::MIN_SCALES) {
            $f = CostFit::fit(array_keys($series), array_values($series));
            $h1 = CostFit::evaluate(CostFit::sig($f['a']), CostFit::sig($f['b']), CostFit::sig($f['k']), $n);
            $rec['per_exec_h1_basis'] = 'fit';
            $beyond = self::linearBeyond($series, CostFit::sig($f['a']), CostFit::sig($f['b']), CostFit::sig($f['k']));
            if ($beyond !== null && $n > $beyond['scale'] && $h1 > self::alongTangent($beyond, $n)) {
                // R2 (D174): beyond the largest scale the fit goes on along its tangent at that scale, not along its own curve
                $uncapped = $h1;
                $h1 = self::alongTangent($beyond, $n);
                $rec['per_exec_h1_uncapped'] = $uncapped;
            }
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
                // a type held to linear growth keeps the fit's own value as its upper side
                return $rec + ['per_exec' => $h1, 'basis' => 'h1'] + ($uncapped !== null ? ['per_exec_upper' => $uncapped, 'upper_basis' => 'h1_fit'] : []);
            }
            // a marked type: the point is H1 (moved by P1 or P2 where they apply), the upper side is what H2 gives (its fall-back to H1 is counted, never silent)
            $h2 = self::type($ty, $explained, RowEstimator::H2, $n, $representative, $withFormulas);
            [$point, $rule, $ruleInput] = self::pointRules($h1, $series, $explained, $marks, $n);
            $upperValue = max((float) $h2['per_exec'], (float) ($uncapped ?? 0.0));
            if ($rule !== null) {
                $upperValue = max($upperValue, $point);
                $rec['point_rule'] = $rule;
                $rec['point_rule_input'] = $ruleInput;
            }
            $upper = ['per_exec' => $point, 'basis' => 'h1', 'per_exec_upper' => $upperValue, 'upper_basis' => $h2['basis']];
            if (isset($h2['fallback'])) {
                $upper['fallback'] = $h2['fallback'];
            }
            foreach (['e_at_n', 'e_at_small', 'measured_small', 'e_by_state'] as $key) {
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
        if ($withFormulas && $unstable === true) {
            $rec['e_by_state'] = self::statesReads((array) ($explained['target_scale'] ?? []), (array) ($marks['unavailable_states'] ?? []));
        }

        return $rec + ['per_exec' => (float) $measuredSmall * (float) $eN / (float) $eSmall, 'basis' => 'ratio'];
    }

    /**
     * P1 then P2 (see the class comment). Only H1_RANGE calls this, and only for a marked type.
     *
     * @param array<int, float>    $series  scale => rows per run
     * @param array<string, mixed>|null $explained the statement entry of explain.json
     * @param array<string, mixed> $marks
     *
     * @return array{float, string|null, array<string, mixed>|null} the point, the rule that moved it (P2 when both moved it), and what the rule read
     */
    private static function pointRules(float $h1, array $series, ?array $explained, array $marks, int $n): array
    {
        $point = $h1;
        $rule = null;
        $input = null;
        // both rules are about sizes above what the capture measured; at or below its largest scale the measured values stand
        $largest = $series === [] ? 0 : (int) max(array_keys($series));
        if (($marks['plan_changes_at_scale'] ?? false) === true && $n > $largest) {
            $scan = self::singleTableScanRows((array) ($explained['target_scale']['default']['raw'] ?? []));
            if ($scan !== null && $scan !== $point) {
                $point = $scan;
                $rule = 'P1';
                $input = ['scan_rows' => $scan, 'at' => $n];
            }
        }
        if (count($series) >= CostFit::MIN_SCALES) {
            $scale = $largest;
            if ($scale > 0 && $n > $scale) {
                $value = $series[$scale];
                $line = $value * $n / $scale;
                if ($line > $point) {
                    $point = $line;
                    $rule = 'P2';
                    $input = ['scale' => $scale, 'value' => $value];
                }
            }
        }

        return [$point, $rule, $input];
    }

    /**
     * The rows of the scan when the plan reads one table by a full scan and nothing else (no join, no subquery, not an insert); null otherwise.
     *
     * @param array<string, mixed> $plan the decoded EXPLAIN FORMAT=JSON of the database of N rows
     */
    private static function singleTableScanRows(array $plan): ?float
    {
        if ($plan === []) {
            return null;
        }
        $tables = PlanSummary::tables($plan);
        if (count($tables) !== 1) {
            return null;
        }
        $t = $tables[0];
        if ($t['insert'] || $t['access_type'] !== 'ALL' || $t['rows_examined_per_scan'] === null) {
            return null;
        }

        return (float) $t['rows_examined_per_scan'];
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
        $perExecPairs = [];
        if (count($series) >= CostFit::MIN_SCALES) {
            $f = CostFit::fit(array_keys($series), array_values($series));
            $perExec = ['basis' => 'fit', 'a' => CostFit::sig($f['a']), 'b' => CostFit::sig($f['b']), 'k' => CostFit::sig($f['k'])];
            $beyond = self::linearBeyond($series, $perExec['a'], $perExec['b'], $perExec['k']);
            if ($beyond !== null) {
                // a reader that puts a size above `scale` in goes on from `value` along `slope` per row of the table (R2, D174)
                $perExec['linear_beyond'] = $beyond;
            }
            [$pairScales, $pairValues] = self::largestThree(array_keys($series), array_values($series));
            $perExecPairs = self::withA(CostFit::pairs($pairScales, $pairValues, $perExec['a']), $perExec['a']);
        } else {
            $perExec = ['basis' => 'held_from_largest_scale', 'value' => $series[max(array_keys($series))]];
        }
        // the count of runs at each scale the count fit used (a type that did not run at a scale counts 0 there, as CaptureBuilder fitted it)
        $countScales = array_values(array_map('intval', (array) ($fit['scales'] ?? [])));
        $perScale = (array) ($ty['per_scale'] ?? []);
        $countValues = array_map(static fn (int $s): int => (int) (is_array($perScale[(string) $s] ?? null) ? ($perScale[(string) $s]['count'] ?? 0) : 0), $countScales);
        [$pairCountScales, $pairCountValues] = self::largestThree($countScales, $countValues);
        $out = [
            'count' => ['a' => (float) $fit['a'], 'b' => (float) $fit['b'], 'k' => (float) $fit['k']],
            'per_exec' => $perExec,
            'scales' => array_keys($series),
            'upper' => null,
            // the formulas of the width of section 6.3: the full fit above and the three formulas of two scales each (`a` held at the full fit's)
            'lines' => [
                'count' => self::withA(CostFit::pairs($pairCountScales, $pairCountValues, (float) $fit['a']), (float) $fit['a']),
                'per_exec' => $perExecPairs,
            ],
            // plan_unstable: the rows per run at each statistics state (measured at the small size x E(state, N) / E(small)), for the width
            'states' => null,
        ];
        if (isset($type['point_rule'])) {
            // P1 or P2 moved the point at this size: a reader that puts another size in applies the same rule (P1: the scan's rows grow with the table; P2: the line)
            $out['point_rule'] = ['rule' => $type['point_rule']] + (array) $type['point_rule_input'];
        }
        if (isset($type['per_exec_upper']) && ($type['upper_basis'] ?? null) !== 'h1_fit') {
            $h1 = (float) $type['per_exec_h1'];
            $up = (float) $type['per_exec_upper'];
            $out['upper'] = ['at_n' => $up, 'h1_at_n' => $h1, 'ratio' => $h1 > 0.0 ? $up / $h1 : null];
        }
        if (isset($type['e_by_state']) && $type['e_by_state'] !== [] && isset($type['measured_small'], $type['e_at_small'])) {
            $h1 = (float) $type['per_exec_h1'];
            $states = [];
            foreach ((array) $type['e_by_state'] as $name => $e) {
                $v = (float) $type['measured_small'] * (float) $e / (float) $type['e_at_small'];
                $states[(string) $name] = ['at_n' => $v, 'ratio' => $h1 > 0.0 ? $v / $h1 : null];
            }
            $out['states'] = $states;
        }

        return $out;
    }

    /**
     * R2 (D174). A fit with an exponent above 1 read a jump of the reading between two scales (the plan the database picks changed there)
     * as growth, and carried it ten times past the largest scale. Beyond the largest scale such a fit goes on along its tangent at that
     * scale (the fit's value there, and its slope there, `k x (value - a) / scale`) instead of along its own curve; the curve stays as the
     * upper side. Returns null where the rule does not apply (a fit whose exponent is not above 1, or fewer than three scales).
     *
     * @param array<int, int|float> $series scale => rows per run
     *
     * @return array{scale: int, value: float, slope: float}|null
     */
    private static function linearBeyond(array $series, float $a, float $b, float $k): ?array
    {
        if (count($series) < CostFit::MIN_SCALES || $k <= 1.0) {
            return null;
        }
        $scale = (int) max(array_keys($series));
        if ($scale < 1) {
            return null;
        }
        $value = CostFit::evaluate($a, $b, $k, $scale);

        return ['scale' => $scale, 'value' => $value, 'slope' => $k * ($value - $a) / $scale];
    }

    /** @param array{scale: int, value: float, slope: float} $beyond */
    private static function alongTangent(array $beyond, int $n): float
    {
        return $beyond['value'] + $beyond['slope'] * ($n - $beyond['scale']);
    }

    /**
     * The three largest scales (and their values) for the formulas of two scales each. Section 6.3 asks for three formulas of two scales each,
     * so a type measured at four or more scales pairs only the larger three: the smallest scale stays in the full fit (and pins its `a`)
     * but is left out of the pairs, because a pair that holds the smallest scale and `a` of the full fit runs `k` to its limit (D135).
     * Scale 0 is never paired. With three scales or fewer the scales are returned as they are.
     *
     * @param list<int>       $scales
     * @param list<int|float> $values
     *
     * @return array{list<int>, list<int|float>}
     */
    private static function largestThree(array $scales, array $values): array
    {
        $points = [];
        foreach (array_values($scales) as $i => $scale) {
            if ($scale > 0) {
                $points[$scale] = $values[$i];
            }
        }
        ksort($points);
        $points = array_slice($points, -3, 3, true);

        return [array_keys($points), array_values($points)];
    }

    /**
     * @param list<array{scales: list<int>, b: float, k: float}> $pairs
     *
     * @return list<array{scales: list<int>, a: float, b: float, k: float}>
     */
    private static function withA(array $pairs, float $a): array
    {
        return array_map(static fn (array $p): array => ['scales' => $p['scales'], 'a' => $a, 'b' => $p['b'], 'k' => $p['k']], $pairs);
    }

    /**
     * E at N for each statistics state that has a plan, by state name (ascending). The same reading of a plan as `stateReads`.
     *
     * @param array<string, mixed> $states      state name => {summary, raw}
     * @param list<mixed>          $unavailable the states the explain run could not read (left out)
     *
     * @return array<string, float>
     *
     * @throws UnrecognisedPlan
     */
    private static function statesReads(array $states, array $unavailable): array
    {
        $names = array_map('strval', array_keys($states));
        sort($names);
        $out = [];
        foreach ($names as $name) {
            $e = self::stateReads($states, [$name], $unavailable);
            if ($e !== null) {
                $out[$name] = $e;
            }
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
