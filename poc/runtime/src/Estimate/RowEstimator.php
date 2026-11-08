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
 * E(s, N) is the largest over the statistics states of N's side (a type whose plan is unstable uses its heaviest state, D107);
 * E(s, small) is the largest over the states of the small side (the explain run makes the default state there).
 *
 * A type that cannot take the ratio falls back to H1, and the fall is counted with its reason (never a silent 0):
 *   no_explain_entry, explain_unavailable:<why>, unrecognised_plan:<why>, no_plan_state, e_small_zero (E(s, small) = 0),
 *   not_run_at_small_scale (no measured value at the largest capture scale).
 *
 * The fit of a type's rows per run needs three scales, as CostFit does. A type that ran at fewer scales than three keeps the value
 * of its largest scale (`held_from_largest_scale`), counted in `per_exec_held`. A type that read no rows anywhere in the capture
 * is unavailable and makes its route incomplete.
 */
final class RowEstimator
{
    public const H1 = 'H1';

    public const H2 = 'H2';

    public const H3 = 'H3';

    public const CANDIDATES = [self::H1, self::H2, self::H3];

    public const SCHEMA_VERSION = '1';

    /**
     * @param array<string, mixed>      $capture     the decoded capture.json
     * @param array<string, mixed>      $explain     the decoded explain.json of the database of N rows (it also holds the small side)
     * @param array<string, mixed>|null $predictions the decoded predictions.json of bin/predict at N (queries, peak memory at route level), or null
     *
     * @return array<string, mixed>
     */
    public static function estimate(array $capture, array $explain, string $candidate, ?int $n = null, ?array $predictions = null): array
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
        $held = 0;
        $ratioUsed = 0;
        foreach ((array) ($capture['routes'] ?? []) as $cr) {
            if (!is_array($cr) || !isset($cr['route'])) {
                continue;
            }
            $route = self::route($cr, $explainRoutes[(string) $cr['route']] ?? null, $candidate, $n, $representative);
            foreach ($route['fallbacks'] as $reason => $count) {
                $fallbacks[$reason] = ($fallbacks[$reason] ?? 0) + $count;
            }
            $held += $route['per_exec_held'];
            $ratioUsed += $route['ratio_used'];
            $p = $predicted[(string) $cr['route']]['quantities'] ?? null;
            $route['peak_memory'] = self::predicted($p['peak_memory'] ?? null);
            $route['queries_route_fit'] = self::predicted($p['queries'] ?? null);
            $routes[] = $route;
        }
        usort($routes, static fn (array $x, array $y): int => strcmp($x['route'], $y['route']));
        ksort($fallbacks);

        return [
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
                'per_exec_held' => $held,
            ],
            'routes' => $routes,
        ];
    }

    /**
     * @param array<string, mixed>      $cr
     * @param array<string, mixed>|null $er
     *
     * @return array<string, mixed>
     */
    private static function route(array $cr, ?array $er, string $candidate, int $n, int $representative): array
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
            if (isset($type['unavailable'])) {
                $out['complete'] = false;
                $out['unavailable'][] = ['id' => $type['id'], 'pattern' => $type['pattern'], 'reason' => $type['unavailable']];
            } else {
                $out['queries'] += $type['count_at_n'];
                $out['rows_examined'] += $type['per_exec'] * $type['count_at_n'];
                if ($type['basis'] === 'ratio') {
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
            $h1 = CostFit::evaluate($f['a'], $f['b'], $f['k'], $n);
            $rec['per_exec_h1_basis'] = 'fit';
        } else {
            $h1 = $series[max(array_keys($series))];
            $rec['per_exec_h1_basis'] = 'held_from_largest_scale';
        }
        $rec['per_exec_h1'] = $h1;

        $marks = (array) ($explained['marks'] ?? []);
        $marked = ($marks['plan_changes_at_scale'] ?? false) === true || ($marks['plan_unstable'] ?? false) === true;
        $rec['marks'] = ['plan_changes_at_scale' => ($marks['plan_changes_at_scale'] ?? false) === true, 'plan_unstable' => ($marks['plan_unstable'] ?? false) === true];
        if ($candidate !== RowEstimator::H1 && $explained === null) {
            // no plan and no marks: whether the type would take the ratio is not known, so it is counted, not passed over
            return $rec + ['per_exec' => $h1, 'basis' => 'h1', 'fallback' => 'no_explain_entry'];
        }
        if ($candidate === RowEstimator::H1 || ($candidate === RowEstimator::H2 && !$marked)) {
            return $rec + ['per_exec' => $h1, 'basis' => 'h1'];
        }

        // the ratio: measured rows per run at the largest capture scale x E(s, N) / E(s, that scale)
        $fallback = null;
        $measuredSmall = $representative > 0 && isset($series[$representative]) ? $series[$representative] : null;
        $eN = null;
        $eSmall = null;
        if (isset($marks['explain_unavailable']) && $marks['explain_unavailable'] !== null && $marks['explain_unavailable'] !== '') {
            $fallback = 'explain_unavailable:' . (string) $marks['explain_unavailable'];
        } else {
            try {
                $eN = self::heaviest((array) ($explained['target_scale'] ?? []), (array) ($marks['unavailable_states'] ?? []));
                $eSmall = self::heaviest((array) ($explained['small_scale'] ?? []), []);
                if ($eN === null || $eSmall === null) {
                    $fallback = 'no_plan_state';
                }
            } catch (UnrecognisedPlan $e) {
                $fallback = 'unrecognised_plan:' . $e->getMessage();
            }
        }
        if ($fallback === null && $eSmall !== null && $eSmall <= 0.0) {
            $fallback = 'e_small_zero';
        }
        if ($fallback === null && $measuredSmall === null) {
            $fallback = 'not_run_at_small_scale';
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
     * The largest E over the states that have a plan; null when none has.
     *
     * @param array<string, mixed> $states      state name => {summary, raw}
     * @param list<mixed>          $unavailable the states of N's side the explain run could not read
     *
     * @throws UnrecognisedPlan
     */
    private static function heaviest(array $states, array $unavailable): ?float
    {
        $best = null;
        foreach ($states as $name => $state) {
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
