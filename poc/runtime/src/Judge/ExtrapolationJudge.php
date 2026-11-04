<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Judge;

/**
 * Checks recorded predictions against what was then measured at the predicted size, and gives the pass / fail of the
 * extrapolation test (docs/simulator-spec.md 7.4, A). The numbers below are those of the spec and are not changed
 * here.
 *
 * Which routes count (the denominator): the `fit_status` of the two quantities that decide the test (the query count
 * and the examined rows) is `ok`, and the cold attempt at the predicted size was measured (class `ok`, both values
 * present). A route that the static analysis contradicts, or that had too few scales for a formula, is not in the
 * denominator; it is counted and listed in the same result so that it is never dropped silently. A route with a
 * formula that could not be measured at the predicted size (timeout, failure) is listed as `unmeasured`.
 *
 * Per quantity: the share of denominator routes whose relative error |predicted - measured| / measured stays in the
 * tolerance (the edge counts as inside). A measured value of 0 has no relative error: the prediction is a match when
 * it is below 0.5, and a mismatch otherwise.
 *
 * Verdict: `pass` when the query count and the examined rows are both inside for at least 80% of the denominator;
 * `fail` when not; `indeterminate` (never a pass) when the denominator has fewer than 5 routes, or when the routes
 * that could not be measured are more than 20% of the routes with a formula. Peak memory does not decide the test;
 * the routes whose peak memory is inside the tolerance are listed.
 */
final class ExtrapolationJudge
{
    public const SCHEMA_VERSION = '0.1';

    /** Allowed relative error in percent, per quantity. */
    public const TOLERANCE_PERCENT = ['queries' => 5, 'rows_examined' => 20, 'peak_memory' => 30];

    /** The quantities the pass / fail is decided by. */
    public const GATING = ['queries', 'rows_examined'];

    public const PASS_SHARE_PERCENT = 80;

    public const MIN_DENOMINATOR = 5;

    public const MAX_UNMEASURED_PERCENT = 20;

    /** A measured 0 is matched by a prediction below this. */
    public const ZERO_MATCH_BELOW = 0.5;

    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const INDETERMINATE = 'indeterminate';

    public const IN_DENOMINATOR = 'in_denominator';

    public const UNMEASURED = 'unmeasured';

    public const CONTRADICTS_STATIC = 'contradicts_static';

    public const INSUFFICIENT_SCALES = 'insufficient_scales';

    /** attempts.jsonl field of each quantity */
    private const ATTEMPT_FIELD = ['queries' => 'queries', 'rows_examined' => 'rows_examined', 'peak_memory' => 'peak_mem'];

    private const COLD = 2;

    /**
     * @param array<string, mixed>       $predictions the decoded predictions.json
     * @param list<array<string, mixed>> $attempts    the decoded lines of the measurement's attempts.jsonl
     * @param string|null                $measuredName the name (no path) of the measurement the attempts came from
     *
     * @return array<string, mixed>
     */
    public static function judge(array $predictions, array $attempts, ?string $measuredName = null): array
    {
        $at = (int) $predictions['at'];
        $cold = self::coldAttempts($attempts, $at);

        $routes = [];
        foreach ((array) ($predictions['routes'] ?? []) as $route) {
            $routes[] = self::route((array) $route, $cold);
        }
        usort($routes, static fn (array $x, array $y): int => strcmp($x['route'], $y['route']));

        $counts = [self::IN_DENOMINATOR => 0, self::UNMEASURED => 0, self::CONTRADICTS_STATIC => 0, self::INSUFFICIENT_SCALES => 0];
        $quantities = [];
        foreach (Predictor::QUANTITIES as $q) {
            $quantities[$q] = ['denominator' => 0, 'within' => 0];
        }
        $memoryWithin = [];
        foreach ($routes as $r) {
            ++$counts[$r['category']];
            if ($r['category'] !== self::IN_DENOMINATOR) {
                continue;
            }
            foreach (Predictor::QUANTITIES as $q) {
                $outcome = $r['quantities'][$q]['outcome'] ?? null;
                if ($outcome === 'match' || $outcome === 'mismatch') {
                    ++$quantities[$q]['denominator'];
                    if ($outcome === 'match') {
                        ++$quantities[$q]['within'];
                    }
                }
            }
            if (($r['quantities']['peak_memory']['outcome'] ?? null) === 'match') {
                $memoryWithin[] = $r['route'];
            }
        }
        foreach ($quantities as $q => $c) {
            $quantities[$q]['percent'] = $c['denominator'] === 0 ? null : round($c['within'] * 100 / $c['denominator'], 2);
            $quantities[$q]['tolerance_percent'] = self::TOLERANCE_PERCENT[$q];
        }

        $withFormula = $counts[self::IN_DENOMINATOR] + $counts[self::UNMEASURED];
        [$verdict, $reasons] = self::verdict($counts, $quantities, $withFormula);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'at' => $at,
            'predictions_source' => $predictions['source'] ?? null,
            'measured_source' => $measuredName,
            'verdict' => $verdict,
            'reasons' => $reasons,
            'summary' => [
                'routes' => count($routes),
                'with_formula' => $withFormula,
                'denominator' => $counts[self::IN_DENOMINATOR],
                'unmeasured' => $counts[self::UNMEASURED],
                'contradicts_static' => $counts[self::CONTRADICTS_STATIC],
                'insufficient_scales' => $counts[self::INSUFFICIENT_SCALES],
            ],
            'quantities' => $quantities,
            'peak_memory_within_tolerance' => $memoryWithin,
            'routes' => $routes,
        ];
    }

    /**
     * @param array<string, int>                                  $counts
     * @param array<string, array{denominator: int, within: int}> $quantities
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function verdict(array $counts, array $quantities, int $withFormula): array
    {
        $den = $counts[self::IN_DENOMINATOR];
        $unmeasured = $counts[self::UNMEASURED];
        $reasons = [];
        if ($den < self::MIN_DENOMINATOR) {
            $reasons[] = sprintf('the denominator has %d routes, fewer than %d', $den, self::MIN_DENOMINATOR);
        }
        if ($unmeasured * 100 > self::MAX_UNMEASURED_PERCENT * $withFormula) {
            $reasons[] = sprintf('%d of %d routes with a formula could not be measured at the predicted size, more than %d%%', $unmeasured, $withFormula, self::MAX_UNMEASURED_PERCENT);
        }
        if ($reasons !== []) {
            return [self::INDETERMINATE, $reasons];
        }

        $verdict = self::PASS;
        foreach (self::GATING as $q) {
            $c = $quantities[$q];
            $inside = $c['denominator'] > 0 && $c['within'] * 100 >= self::PASS_SHARE_PERCENT * $c['denominator'];
            $reasons[] = sprintf('%s: %d of %d inside +-%d%% (%s), needs %d%%', $q, $c['within'], $c['denominator'], self::TOLERANCE_PERCENT[$q], $inside ? 'enough' : 'not enough', self::PASS_SHARE_PERCENT);
            if (!$inside) {
                $verdict = self::FAIL;
            }
        }

        return [$verdict, $reasons];
    }

    /**
     * The cold attempts at the predicted size, by "METHOD uri" and axis. Of two for the same key the first line wins.
     *
     * @param list<array<string, mixed>> $attempts
     *
     * @return array<string, array<string, mixed>>
     */
    private static function coldAttempts(array $attempts, int $at): array
    {
        $cold = [];
        foreach ($attempts as $a) {
            if ((int) ($a['attempt'] ?? 0) !== self::COLD || (int) ($a['scale'] ?? -1) !== $at) {
                continue;
            }
            $key = ((string) ($a['method'] ?? '')) . ' ' . ((string) ($a['route'] ?? '')) . "\0" . ((string) ($a['axis'] ?? ''));
            $cold[$key] ??= $a;
        }

        return $cold;
    }

    /**
     * @param array<string, mixed>               $route
     * @param array<string, array<string, mixed>> $cold
     *
     * @return array<string, mixed>
     */
    private static function route(array $route, array $cold): array
    {
        $name = (string) $route['route'];
        $quantities = (array) ($route['quantities'] ?? []);
        $row = ['route' => $name, 'axis' => $route['axis'] ?? null, 'category' => self::IN_DENOMINATOR, 'quantities' => []];

        // Statuses of the quantities that decide the test. contradicts_static outranks insufficient_scales.
        $gatingStatus = [];
        foreach (self::GATING as $q) {
            $gatingStatus[$q] = (string) ($quantities[$q]['fit_status'] ?? 'missing');
        }
        foreach ([self::CONTRADICTS_STATIC, self::INSUFFICIENT_SCALES] as $excluded) {
            foreach (self::GATING as $q) {
                if ($gatingStatus[$q] === $excluded) {
                    $row['category'] = $excluded;
                    $row['reason'] = sprintf('%s: %s', $q, (string) ($quantities[$q]['reason'] ?? $excluded));

                    return $row;
                }
            }
        }
        foreach (self::GATING as $q) {
            if ($gatingStatus[$q] !== 'ok') {
                $row['category'] = self::INSUFFICIENT_SCALES;
                $row['reason'] = sprintf('%s: fit_status %s', $q, $gatingStatus[$q]);

                return $row;
            }
        }

        $key = $name . "\0" . ((string) ($route['axis'] ?? ''));
        $a = $cold[$key] ?? null;
        $problem = null;
        if ($a === null) {
            $problem = 'no cold attempt at the predicted size';
        } elseif (($a['class'] ?? null) !== 'ok') {
            $problem = 'cold attempt class ' . (string) ($a['class'] ?? 'unknown');
        } else {
            foreach (self::GATING as $q) {
                if (!isset($a[self::ATTEMPT_FIELD[$q]])) {
                    $problem = $q . ' was not measured';
                    break;
                }
            }
        }
        if ($problem !== null) {
            $row['category'] = self::UNMEASURED;
            $row['reason'] = $problem;

            return $row;
        }

        foreach (Predictor::QUANTITIES as $q) {
            $p = (array) ($quantities[$q] ?? []);
            $measured = $a[self::ATTEMPT_FIELD[$q]] ?? null;
            if (($p['status'] ?? null) !== Predictor::PREDICTED) {
                $row['quantities'][$q] = ['outcome' => 'no_prediction', 'measured' => $measured, 'reason' => (string) ($p['reason'] ?? ($p['fit_status'] ?? 'no prediction'))];
                continue;
            }
            if ($measured === null) {
                $row['quantities'][$q] = ['outcome' => 'not_measured', 'predicted' => $p['value']];
                continue;
            }
            $row['quantities'][$q] = self::compare((float) $p['value'], (float) $measured, self::TOLERANCE_PERCENT[$q]);
            $row['quantities'][$q] += ['a' => $p['a'], 'b' => $p['b'], 'k' => $p['k']];
        }

        return $row;
    }

    /**
     * @return array{predicted: float, measured: float, relative_error: float|null, outcome: string}
     */
    public static function compare(float $predicted, float $measured, int $tolerancePercent): array
    {
        if ($measured === 0.0) {
            return ['predicted' => $predicted, 'measured' => $measured, 'relative_error' => null, 'outcome' => $predicted < self::ZERO_MATCH_BELOW ? 'match' : 'mismatch'];
        }
        $error = abs($predicted - $measured) / abs($measured);
        // Compared in percent with a hair of slack, so that an error of exactly 5% is not lost to rounding of the division.
        $inside = abs($predicted - $measured) * 100 <= $tolerancePercent * abs($measured) * (1 + 1e-12);

        return ['predicted' => $predicted, 'measured' => $measured, 'relative_error' => $error, 'outcome' => $inside ? 'match' : 'mismatch'];
    }

    /**
     * The result for reading: the verdict, the table of shares, and the routes left out of the denominator.
     *
     * @param array<string, mixed> $result
     */
    public static function toMarkdown(array $result): string
    {
        $s = $result['summary'];
        $md = sprintf("# Extrapolation test at N = %d\n\n", $result['at']);
        $md .= sprintf("Verdict: **%s**\n\n", $result['verdict']);
        foreach ($result['reasons'] as $reason) {
            $md .= '- ' . $reason . "\n";
        }
        $md .= "\n| quantity | inside | denominator | share | tolerance | decides the test |\n| --- | --- | --- | --- | --- | --- |\n";
        foreach ($result['quantities'] as $q => $c) {
            $md .= sprintf(
                "| %s | %d | %d | %s | +-%d%% | %s |\n",
                $q,
                $c['within'],
                $c['denominator'],
                $c['percent'] === null ? 'n/a' : $c['percent'] . '%',
                $c['tolerance_percent'],
                in_array($q, self::GATING, true) ? 'yes' : 'no',
            );
        }
        $md .= sprintf(
            "\nRoutes: %d; with a formula: %d (denominator %d, unmeasured %d); contradicts_static: %d; insufficient_scales: %d\n",
            $s['routes'],
            $s['with_formula'],
            $s['denominator'],
            $s['unmeasured'],
            $s['contradicts_static'],
            $s['insufficient_scales'],
        );
        $md .= "\n## Left out of the denominator\n\n";
        $left = array_filter($result['routes'], static fn (array $r): bool => $r['category'] !== self::IN_DENOMINATOR);
        if ($left === []) {
            $md .= "none\n";
        } else {
            $md .= "| route | category | reason |\n| --- | --- | --- |\n";
            foreach ($left as $r) {
                $md .= sprintf("| %s | %s | %s |\n", $r['route'], $r['category'], $r['reason'] ?? '');
            }
        }
        $md .= "\n## Peak memory inside the tolerance\n\n";
        $md .= $result['peak_memory_within_tolerance'] === [] ? "none\n" : implode("\n", array_map(static fn (string $r): string => '- ' . $r, $result['peak_memory_within_tolerance'])) . "\n";

        return $md;
    }
}
