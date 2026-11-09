<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Simulate\Load;

use PeakSim\Runtime\Judge\CostFit;

/**
 * The estimate directory: `estimate.json` made by `bin/estimate --candidate=H1|H1_RANGE --predictions=... --with-formulas`.
 * It holds, per statement type, the formulas of the count and of the rows per run (and the ratio of the upper side), and per route the
 * table that scales and the formula of the peak memory. Here they are put at the table size of one scenario, with {@see CostFit::evaluate}.
 */
final class EstimateFormulas
{
    public const FILE = 'estimate.json';

    /**
     * @param array<string, array<string, mixed>>                                                  $routes   route => the route of estimate.json
     * @param array<string, array{axis: string|null, table: string|null, in_estimate: bool}>       $index    route => what the static analysis knows
     */
    private function __construct(
        public readonly ?string $app,
        public readonly int $at,
        public readonly array $routes,
        public readonly array $index,
    ) {
    }

    /** @throws LoadFailure ESTIMATE_INVALID */
    public static function load(SimulateIo $io, string $dir): self
    {
        $path = rtrim($dir, '/') . '/' . self::FILE;
        try {
            $raw = $io->read($path);
        } catch (\RuntimeException $e) {
            throw new LoadFailure(LoadFailure::ESTIMATE_INVALID, 'the estimate directory has no readable ' . self::FILE . ' (' . $path . ')');
        }
        try {
            $doc = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LoadFailure(LoadFailure::ESTIMATE_INVALID, self::FILE . ' is not JSON: ' . $e->getMessage());
        }

        return self::fromDocument(is_array($doc) ? $doc : []);
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @throws LoadFailure ESTIMATE_INVALID
     */
    public static function fromDocument(array $doc): self
    {
        if (!in_array($doc['candidate'] ?? null, ['H1', 'H1_RANGE'], true)) {
            throw new LoadFailure(LoadFailure::ESTIMATE_INVALID, self::FILE . ' must be made with --candidate=H1 or H1_RANGE (the point is H1)');
        }
        if (!isset($doc['route_index']) || !is_array($doc['route_index']) || !isset($doc['routes']) || !is_array($doc['routes']) || (int) ($doc['at'] ?? 0) < 1) {
            throw new LoadFailure(LoadFailure::ESTIMATE_INVALID, self::FILE . ' has no formulas: make it with bin/estimate --with-formulas');
        }
        $index = [];
        foreach ($doc['route_index'] as $r) {
            if (is_array($r) && isset($r['route'])) {
                $index[(string) $r['route']] = [
                    'axis' => isset($r['axis']) ? (string) $r['axis'] : null,
                    'table' => isset($r['table']) ? (string) $r['table'] : null,
                    'in_estimate' => ($r['in_estimate'] ?? false) === true,
                ];
            }
        }
        $routes = [];
        foreach ($doc['routes'] as $r) {
            if (!is_array($r) || !isset($r['route'])) {
                continue;
            }
            foreach ((array) ($r['types'] ?? []) as $t) {
                if (is_array($t) && !isset($t['unavailable']) && !isset($t['formula'])) {
                    throw new LoadFailure(LoadFailure::ESTIMATE_INVALID, self::FILE . ' has no formulas: make it with bin/estimate --with-formulas');
                }
            }
            $routes[(string) $r['route']] = $r;
            $index[(string) $r['route']] ??= ['axis' => null, 'table' => null, 'in_estimate' => true];
        }
        ksort($index);

        return new self(isset($doc['app']) ? (string) $doc['app'] : null, (int) $doc['at'], $routes, $index);
    }

    public function knows(string $route): bool
    {
        return isset($this->index[$route]);
    }

    /** @return list<string> sorted */
    public function routeNames(): array
    {
        return array_keys($this->index);
    }

    public function tableOf(string $route): ?string
    {
        return $this->index[$route]['table'] ?? null;
    }

    /**
     * One route at the table size n of a scenario.
     *
     * `queries_low` / `queries_high` / `rows_low` / `rows_high` are the width of section 6.3 that the estimate gives: the least and the most over the
     * four formulas of the fitted values (the full fit and the three of two scales each) and, for a plan_unstable type, over its statistics states.
     * They are null when no type of the route has such a formula or state.
     *
     * @return array<string, mixed> {cost, unknown_reason, queries, rows, rows_upper, queries_low, queries_high, rows_low, rows_high, marked, peak_memory, peak_memory_status, n_mark, sources, types}
     */
    public function evaluate(string $route, ?int $n): array
    {
        $out = [
            'cost' => 'unknown',
            'unknown_reason' => null,
            'queries' => null,
            'rows' => null,
            'rows_upper' => null,
            'queries_low' => null,
            'queries_high' => null,
            'rows_low' => null,
            'rows_high' => null,
            'marked' => false,
            'peak_memory' => null,
            'peak_memory_status' => 'unknown',
            'n_mark' => null,
            'sources' => ['queries' => null, 'rows' => null, 'rows_upper' => null, 'peak_memory' => null],
            'types' => [],
        ];
        $r = $this->routes[$route] ?? null;
        if ($r === null) {
            return array_merge($out, ['unknown_reason' => 'no_estimate']);
        }
        if (($r['complete'] ?? false) !== true) {
            $first = (array) (($r['unavailable'] ?? [])[0] ?? []);
            $why = isset($first['reason']) ? (string) $first['reason'] : (string) ($r['status'] ?? 'incomplete');

            return array_merge($out, ['unknown_reason' => 'incomplete:' . $why]);
        }
        if ($n === null) {
            $table = $this->tableOf($route);

            return array_merge($out, ['unknown_reason' => $table === null ? 'no_axis_table' : 'table_count_missing:' . $table]);
        }

        $types = [];
        $queries = 0.0;
        $rows = 0.0;
        $upper = 0.0;
        $scales = [];
        $width = ['queries_low' => 0.0, 'queries_high' => 0.0, 'rows_low' => 0.0, 'rows_high' => 0.0, 'given' => false];
        foreach ((array) ($r['types'] ?? []) as $t) {
            $f = (array) $t['formula'];
            $count = CostFit::evaluate((float) $f['count']['a'], (float) $f['count']['b'], (float) $f['count']['k'], $n);
            $pe = (array) $f['per_exec'];
            $perExec = ($pe['basis'] ?? '') === 'fit'
                ? CostFit::evaluate((float) $pe['a'], (float) $pe['b'], (float) $pe['k'], $n)
                : (float) $pe['value'];
            $perUpper = null;
            if (isset($f['upper']) && is_array($f['upper'])) {
                $perUpper = $f['upper']['ratio'] !== null ? $perExec * (float) $f['upper']['ratio'] : (float) $f['upper']['at_n'];
            }
            $range = self::widthOf($f, $n, $count, $perExec);
            $width['queries_low'] += $range['count_low'];
            $width['queries_high'] += $range['count_high'];
            $width['rows_low'] += $range['per_exec_low'] * $range['count_low'];
            $width['rows_high'] += $range['per_exec_high'] * $range['count_high'];
            $width['given'] = $width['given'] || $range['given'];
            foreach ((array) ($f['scales'] ?? []) as $s) {
                $scales[(int) $s] = true;
            }
            $queries += $count;
            $rows += $perExec * $count;
            $upper += ($perUpper ?? $perExec) * $count;
            $marks = (array) ($t['marks'] ?? []);
            $types[] = [
                'id' => (string) $t['id'],
                'kind' => (string) ($t['kind'] ?? ''),
                'pattern' => (string) ($t['pattern'] ?? ''),
                'count' => $count,
                'per_exec' => $perExec,
                'per_exec_upper' => $perUpper,
                'marks' => ['plan_changes_at_scale' => ($marks['plan_changes_at_scale'] ?? false) === true, 'plan_unstable' => ($marks['plan_unstable'] ?? false) === true],
                'rows' => $perExec * $count,
                'rows_upper' => ($perUpper ?? $perExec) * $count,
            ];
        }
        usort($types, static fn (array $a, array $b): int => [$b['rows'], $a['id']] <=> [$a['rows'], $b['id']]);

        $marked = ($r['marked'] ?? false) === true;
        $pf = $r['peak_memory_formula'] ?? null;
        $pmStatus = (string) (($r['peak_memory'] ?? [])['status'] ?? 'not_given');
        $out['cost'] = 'ok';
        $out['queries'] = $queries;
        $out['rows'] = $rows;
        $out['rows_upper'] = $marked ? $upper : null;
        if ($width['given']) {
            $out['queries_low'] = $width['queries_low'];
            $out['queries_high'] = $width['queries_high'];
            $out['rows_low'] = $width['rows_low'];
            $out['rows_high'] = $width['rows_high'];
        }
        $out['marked'] = $marked;
        if (is_array($pf)) {
            $out['peak_memory'] = CostFit::evaluate((float) $pf['a'], (float) $pf['b'], (float) $pf['k'], $n);
            $out['peak_memory_status'] = 'predicted';
        } else {
            $out['peak_memory_status'] = $pmStatus === 'predicted' ? 'not_predicted' : $pmStatus;
        }
        $out['n_mark'] = self::mark($n, array_keys($scales));
        $out['sources'] = ['queries' => 'fit', 'rows' => 'fit', 'rows_upper' => $marked ? 'explain' : null, 'peak_memory' => is_array($pf) ? 'fit' : null];
        $out['types'] = $types;

        return $out;
    }

    /**
     * The least and the most of a type's count of runs and rows per run at the table size n (section 6.3). The point is inside both ranges: it is the
     * full fit, one of the formulas. The count and the rows per run vary on their own (every value is >= 0, so the least rows is the least rows per run
     * times the least count, and the most is the most times the most).
     *
     * @param array<string, mixed> $f       the type's `formula`
     * @param float                $count   the point's count at n
     * @param float                $perExec the point's rows per run at n
     *
     * @return array{count_low: float, count_high: float, per_exec_low: float, per_exec_high: float, given: bool}
     */
    private static function widthOf(array $f, int $n, float $count, float $perExec): array
    {
        $counts = [$count];
        $per = [$perExec];
        $lines = (array) ($f['lines'] ?? []);
        foreach ((array) ($lines['count'] ?? []) as $l) {
            $counts[] = CostFit::evaluate((float) $l['a'], (float) $l['b'], (float) $l['k'], $n);
        }
        foreach ((array) ($lines['per_exec'] ?? []) as $l) {
            $per[] = CostFit::evaluate((float) $l['a'], (float) $l['b'], (float) $l['k'], $n);
        }
        // a plan_unstable type: the rows per run of each statistics state, kept in the ratio to the point that it had where the estimate was made
        foreach ((array) ($f['states'] ?? []) as $state) {
            $per[] = $state['ratio'] !== null ? $perExec * (float) $state['ratio'] : (float) $state['at_n'];
        }

        return [
            'count_low' => min($counts),
            'count_high' => max($counts),
            'per_exec_low' => min($per),
            'per_exec_high' => max($per),
            'given' => count($counts) > 1 || count($per) > 1,
        ];
    }

    /** @param list<int> $scales the sizes the type's rows per run were measured at */
    private static function mark(int $n, array $scales): ?string
    {
        if ($scales === []) {
            return null;
        }
        if (in_array($n, $scales, true)) {
            return 'measured';
        }

        return $n > min($scales) && $n < max($scales) ? 'interpolated' : 'extrapolated';
    }
}
