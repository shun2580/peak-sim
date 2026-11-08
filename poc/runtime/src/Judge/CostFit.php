<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Judge;

/**
 * The cost formula of a route: how one measured quantity grows with the size N of the table that scales, as
 *
 *     y = a + b * N^k        a >= 0, b >= 0, 0 <= k <= 3
 *
 * and the record-to-`fits.json` step around it (simulator-spec.md section 4).
 *
 * How it is found (no randomness: the same input always gives the same output):
 *  - k runs over 0.00, 0.01, ... 3.00 (an integer step counter divided by 100, so the grid does not drift).
 *  - for each k, a and b are the linear least squares of y on x = N^k. A coefficient that comes out negative is cut to
 *    0 and the other is found again.
 *  - the k with the smallest squared error wins. On a tie the smaller k wins; "tie" means a difference below
 *    TIE_TOLERANCE of the series' own sum of squares, so floating-point noise does not pick the larger k.
 *  - at N = 0 the term N^k is 0 for k > 0 and 1 for k = 0, as in the formula. The empty system therefore pins `a` for
 *    every k > 0.
 *
 * One exception to the empty system (D105): a route whose request at scale 0 was answered `not_found` (the record its address
 * names lives in the table the plan empties at N = 0) has no empty-system point. For that route only, scale 0 is left out,
 * `a` is free, and the smallest scale (10 in the sweep of D105) is the first point. It needs at least
 * ABSENT_RECORD_MIN_SCALES points: three free numbers on three points would fit exactly and could not disagree with
 * their own data. Every other route is fitted as before (D79, D81).
 *
 * With k = 0 the formula is the constant a + b: a and b cannot be told apart. The fit then reports the constant as
 * `a` and b = 0. The sum a + b is what is identified.
 */
final class CostFit
{
    public const K_MAX = 3.0;

    public const K_STEPS = 300;

    /** Two squared errors closer than this share of the series' sum of squares are the same error. */
    public const TIE_TOLERANCE = 1e-12;

    /** The step from which a static lower bound makes a query-count exponent this small a contradiction (D21). */
    public const CONTRADICTION_MAX_K = 0.2;

    public const OK = 'ok';

    public const CONTRADICTS_STATIC = 'contradicts_static';

    public const INSUFFICIENT_SCALES = 'insufficient_scales';

    public const SCHEMA_VERSION = '0.1';

    /** Fewest scales, counting the empty system (scale 0), a formula is made from. */
    public const MIN_SCALES = 3;

    /** Fewest points a route without an empty-system point is fitted on (D105: 10, 100, 1,000 and 10,000). */
    public const ABSENT_RECORD_MIN_SCALES = 4;

    /** The class of a scale-0 request that answered "the record is not there" (`Classification::NOT_FOUND`). */
    public const EMPTY_SYSTEM_NOT_FOUND = 'not_found';

    /** The quantities and the field of an attempt each one is read from. */
    public const QUANTITIES = ['queries' => 'queries', 'rows_examined' => 'rows_examined', 'peak_memory' => 'peak_mem'];

    /** The static lower bounds that are at least O(N). Anything else (null, O(1)) forbids no shape. */
    private const LINEAR_OR_MORE = ['O(N)', 'O(N*M)'];

    /**
     * The best formula over all scales.
     *
     * @param list<int>       $scales ascending or not; one entry per value
     * @param list<int|float> $values
     *
     * @return array{a: float, b: float, k: float, sse: float}
     */
    public static function fit(array $scales, array $values): array
    {
        return self::search($scales, $values, null);
    }

    /**
     * The formula through two scales only, with `a` held at the value of the fit over all scales (so the two
     * points decide b and k, not a).
     *
     * @param list<int>       $scales exactly the scales to use
     * @param list<int|float> $values
     *
     * @return array{a: float, b: float, k: float, sse: float}
     */
    public static function fitWithFixedA(array $scales, array $values, float $a): array
    {
        return self::search($scales, $values, $a);
    }

    /** The value of a formula at N. */
    public static function evaluate(float $a, float $b, float $k, int $n): float
    {
        return $a + $b * self::power($n, $k);
    }

    /**
     * The three formulas from two scales each, `a` fixed at the full fit's. Scale 0 is not one of the scales
     * paired: it belongs to the full fit, where it pins `a`.
     *
     * @param list<int>       $scales
     * @param list<int|float> $values
     *
     * @return list<array{scales: list<int>, b: float, k: float}>
     */
    public static function pairs(array $scales, array $values, float $a): array
    {
        $points = [];
        foreach (array_values($scales) as $i => $scale) {
            if ($scale > 0) {
                $points[$scale] = $values[$i];
            }
        }
        ksort($points);
        $keys = array_keys($points);
        $out = [];
        for ($i = 0; $i < count($keys); $i++) {
            for ($j = $i + 1; $j < count($keys); $j++) {
                $f = self::fitWithFixedA([$keys[$i], $keys[$j]], [$points[$keys[$i]], $points[$keys[$j]]], $a);
                $out[] = ['scales' => [$keys[$i], $keys[$j]], 'b' => self::sig($f['b']), 'k' => self::sig($f['k'])];
            }
        }

        return $out;
    }

    /**
     * One quantity of one route, as the entry of `fits.json`.
     *
     * @param array<int, int|float|null> $series         scale => value (null: that scale could not be measured)
     * @param array<int, string>         $unmeasured     scale => why it could not be measured
     * @param list<int>                  $expectedScales every scale the run measured the routes at
     * @param int                        $minScales      fewest scales the formula is made from
     *
     * @return array<string, mixed>
     */
    public static function quantity(array $series, array $unmeasured, array $expectedScales, bool $isQueryCount, ?string $staticLowerBound, int $minScales = self::MIN_SCALES): array
    {
        $reasons = [];
        foreach ($expectedScales as $scale) {
            if (isset($series[$scale])) {
                continue;
            }
            $reasons[] = sprintf('scale %d: %s', $scale, $unmeasured[$scale] ?? 'not measured');
        }
        $scales = array_keys(array_filter($series, static fn (mixed $v): bool => $v !== null));
        sort($scales);
        if ($reasons !== []) {
            return ['fit_status' => self::INSUFFICIENT_SCALES, 'reason' => implode('; ', $reasons)];
        }
        if (count($scales) < $minScales) {
            return [
                'fit_status' => self::INSUFFICIENT_SCALES,
                'reason' => $minScales === self::MIN_SCALES
                    ? sprintf('%d scales measured (counting scale 0), at least %d are needed', count($scales), $minScales)
                    : sprintf('%d scales measured (scale 0 is left out), at least %d are needed', count($scales), $minScales),
            ];
        }

        $values = array_map(static fn (int $s): int|float => $series[$s], $scales);
        $fit = self::fit($scales, $values);

        if ($isQueryCount && $staticLowerBound !== null && in_array($staticLowerBound, self::LINEAR_OR_MORE, true) && $fit['k'] <= self::CONTRADICTION_MAX_K) {
            return [
                'fit_status' => self::CONTRADICTS_STATIC,
                'reason' => sprintf(
                    'the static analysis puts a lower bound of %s on the query count, the measured exponent is k=%s (<= %s)',
                    $staticLowerBound,
                    self::sig($fit['k']),
                    self::CONTRADICTION_MAX_K,
                ),
                'rejected_fit' => ['a' => self::sig($fit['a']), 'b' => self::sig($fit['b']), 'k' => self::sig($fit['k']), 'sse' => self::sig($fit['sse'])],
                'scales' => $scales,
            ];
        }

        return [
            'fit_status' => self::OK,
            'a' => self::sig($fit['a']),
            'b' => self::sig($fit['b']),
            'k' => self::sig($fit['k']),
            'sse' => self::sig($fit['sse']),
            'scales' => $scales,
            'pairs' => self::pairs($scales, $values, $fit['a']),
        ];
    }

    /**
     * `fits.json` from a measurement record.
     *
     * @param list<array<string, mixed>> $attempts the lines of attempts.jsonl
     * @param list<array<string, mixed>> $routes   the lines of routes.jsonl
     * @param list<array<string, mixed>> $tasks    the entries of tasks.json (may be empty: `table` is then null)
     *
     * @return array<string, mixed>
     */
    public static function fromRecords(array $attempts, array $routes, array $tasks, string $source): array
    {
        $tableOf = [];
        foreach ($tasks as $task) {
            $spec = $task['spec'] ?? [];
            $nTable = $spec['static']['n_table'] ?? null;
            $tableOf[self::key((string) ($spec['method'] ?? ''), (string) ($spec['uri'] ?? ''))] = is_string($nTable) ? $nTable : null;
        }
        $staticOf = [];
        $routeRow = [];
        foreach ($routes as $row) {
            $key = self::key((string) ($row['method'] ?? ''), (string) ($row['route'] ?? ''));
            $staticOf[$key] = $row['static']['lower_bound'] ?? null;
            $routeRow[$key] = $row;
        }

        $expected = [];
        $byRoute = [];
        foreach ($attempts as $a) {
            if (strtolower((string) ($a['axis'] ?? 'N')) !== 'n') {
                continue; // the child-table sweep is not fitted here
            }
            $scale = (int) $a['scale'];
            $expected[$scale] = true;
            $byRoute[self::key((string) ($a['method'] ?? ''), (string) ($a['route'] ?? ''))][] = $a;
        }
        $expectedScales = array_keys($expected);
        sort($expectedScales);

        // D105: a route whose empty-system request answered not_found has no scale-0 point; it is fitted without it.
        // The driver stops at the first attempt when the empty system answers not_found, so there may be no cold attempt
        // at scale 0: the last attempt recorded at scale 0 (a 422 for a missing body may come before it) decides.
        $noEmptyPoint = [];
        foreach ($byRoute as $key => $list) {
            $last = null;
            foreach ($list as $a) {
                if ((int) $a['scale'] === 0) {
                    $last = $a['class'] ?? null;
                }
            }
            if ($last === self::EMPTY_SYSTEM_NOT_FOUND) {
                $noEmptyPoint[$key] = true;
            }
        }

        $out = [];
        foreach (array_unique([...array_keys($byRoute), ...array_keys($routeRow)]) as $key) {
            $quantities = [];
            foreach (self::QUANTITIES as $name => $field) {
                if (! isset($byRoute[$key])) {
                    $row = $routeRow[$key] ?? [];
                    $why = (string) ($row['reason_detail'] ?? $row['reason'] ?? $row['class'] ?? 'not measured');
                    $quantities[$name] = ['fit_status' => self::INSUFFICIENT_SCALES, 'reason' => 'route not measured: ' . $why];

                    continue;
                }
                [$series, $unmeasured] = self::coldSeries($byRoute[$key], $field);
                if (isset($noEmptyPoint[$key])) {
                    unset($series[0], $unmeasured[0]);
                    $quantities[$name] = self::quantity(
                        $series,
                        $unmeasured,
                        array_values(array_filter($expectedScales, static fn (int $s): bool => $s > 0)),
                        $name === 'queries',
                        $staticOf[$key] ?? null,
                        self::ABSENT_RECORD_MIN_SCALES,
                    );

                    continue;
                }
                $quantities[$name] = self::quantity($series, $unmeasured, $expectedScales, $name === 'queries', $staticOf[$key] ?? null);
            }
            $out[$key] = [
                'route' => $key,
                'axis' => 'N',
                'table' => $tableOf[$key] ?? null,
                'static_lower_bound' => $staticOf[$key] ?? null,
                'quantities' => $quantities,
            ] + (isset($noEmptyPoint[$key]) ? ['empty_system' => self::EMPTY_SYSTEM_NOT_FOUND] : []);
        }
        ksort($out);

        return ['schema_version' => self::SCHEMA_VERSION, 'source' => $source, 'routes' => array_values($out)];
    }

    /**
     * The cold attempt's value at each scale, and why a scale has none.
     *
     * @param list<array<string, mixed>> $attempts
     *
     * @return array{0: array<int, int|float>, 1: array<int, string>}
     */
    private static function coldSeries(array $attempts, string $field): array
    {
        $series = [];
        $unmeasured = [];
        foreach ($attempts as $a) {
            if ((int) ($a['attempt'] ?? 0) !== 2) {
                continue;
            }
            $scale = (int) $a['scale'];
            $value = $a[$field] ?? ($field === 'rows_examined' ? ($a['rowsExamined'] ?? null) : null);
            if (($a['class'] ?? null) !== 'ok') {
                unset($series[$scale]);
                $unmeasured[$scale] = (string) ($a['class'] ?? 'not measured');
            } elseif ($value === null) {
                unset($series[$scale]);
                $unmeasured[$scale] = $field . ' not recorded';
            } else {
                $series[$scale] = $value;
                unset($unmeasured[$scale]);
            }
        }

        return [$series, $unmeasured];
    }

    private static function key(string $method, string $uri): string
    {
        return $method . ' ' . $uri;
    }

    /**
     * @param list<int>       $scales
     * @param list<int|float> $values
     *
     * @return array{a: float, b: float, k: float, sse: float}
     */
    private static function search(array $scales, array $values, ?float $fixedA): array
    {
        $scales = array_values($scales);
        $values = array_values($values);
        if ($scales === [] || count($scales) !== count($values)) {
            throw new \InvalidArgumentException('a fit needs one value per scale and at least one scale');
        }
        $ys = array_map(static fn (int|float $v): float => (float) $v, $values);
        $tolerance = self::TIE_TOLERANCE * array_sum(array_map(static fn (float $y): float => $y * $y, $ys));

        $best = null;
        for ($step = 0; $step <= self::K_STEPS; $step++) {
            $k = $step / 100.0;
            $xs = array_map(static fn (int $n): float => self::power($n, $k), $scales);
            [$a, $b] = $fixedA === null ? self::leastSquares($xs, $ys) : [$fixedA, self::bWithFixedA($xs, $ys, $fixedA)];
            $sse = 0.0;
            foreach ($xs as $i => $x) {
                $sse += ($ys[$i] - $a - $b * $x) ** 2;
            }
            if ($best === null || $sse < $best['sse'] - $tolerance) {
                $best = ['a' => $a, 'b' => $b, 'k' => $k, 'sse' => $sse];
            }
        }
        assert($best !== null);

        return $best;
    }

    /**
     * a, b >= 0 minimising the squared error of y = a + b x.
     *
     * @param list<float> $xs
     * @param list<float> $ys
     *
     * @return array{0: float, 1: float}
     */
    private static function leastSquares(array $xs, array $ys): array
    {
        $n = count($xs);
        $sx = array_sum($xs);
        $sy = array_sum($ys);
        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($xs as $i => $x) {
            $sxx += $x * $x;
            $sxy += $x * $ys[$i];
        }
        $mean = max(0.0, $sy / $n);
        $det = $n * $sxx - $sx * $sx;
        if ($det <= 1e-12 * $n * $sxx) {
            return [$mean, 0.0]; // x is constant (k = 0): only a + b is identified
        }
        $b = ($n * $sxy - $sx * $sy) / $det;
        $a = ($sy - $b * $sx) / $n;
        if ($b < 0.0) {
            return [$mean, 0.0];
        }
        if ($a < 0.0) {
            return [0.0, $sxx > 0.0 ? max(0.0, $sxy / $sxx) : 0.0];
        }

        return [$a, $b];
    }

    /**
     * @param list<float> $xs
     * @param list<float> $ys
     */
    private static function bWithFixedA(array $xs, array $ys, float $a): float
    {
        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($xs as $i => $x) {
            $sxx += $x * $x;
            $sxy += $x * ($ys[$i] - $a);
        }

        return $sxx > 0.0 ? max(0.0, $sxy / $sxx) : 0.0;
    }

    private static function power(int|float $n, float $k): float
    {
        return $n == 0 ? ($k == 0.0 ? 1.0 : 0.0) : (float) $n ** $k;
    }

    /** 12 significant digits: the float noise of the arithmetic is not written down, a coefficient keeps its precision. */
    public static function sig(float $v): float
    {
        return (float) sprintf('%.12G', $v);
    }
}
