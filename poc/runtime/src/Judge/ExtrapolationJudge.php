<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Judge;

/**
 * Checks recorded predictions against what was then measured at the predicted size, and gives the pass / fail of the
 * extrapolation test (docs/simulator-spec.md 7.4, A). The numbers below are those of the spec and are not changed
 * here.
 *
 * Which routes count (the denominator) is decided per quantity, not per route. For a quantity, a route is in the
 * denominator when the `fit_status` of that quantity is `ok` and the cold attempt at the predicted size was measured
 * (class `ok`, a value present for that quantity). A route that the static analysis contradicts for that quantity, or
 * that had too few scales for its formula, is not in that quantity's denominator; it is counted and listed, by
 * quantity, in the same result so that it is never dropped silently. A route with a formula for that quantity that
 * could not be measured at the predicted size (timeout, failure, value missing) is `unmeasured` for it.
 *
 * Per quantity: the share of denominator routes whose relative error |predicted - measured| / measured stays in the
 * tolerance (the edge counts as inside). A measured value of 0 has no relative error: the prediction is a match when
 * it is below 0.5, and a mismatch otherwise.
 *
 * Verdict: `pass` when the query count and the examined rows are each inside for at least 80% of their own
 * denominator; `fail` when not; `indeterminate` (never a pass) when, for either of the two, the denominator has fewer
 * than 5 routes, or the routes that could not be measured are more than 20% of the routes with a formula for it.
 * Peak memory does not decide the test; it is counted the same way and the routes whose peak memory is inside the
 * tolerance are listed.
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

        $quantities = [];
        foreach (Predictor::QUANTITIES as $q) {
            $quantities[$q] = ['denominator' => 0, 'within' => 0, self::UNMEASURED => 0, self::CONTRADICTS_STATIC => 0, self::INSUFFICIENT_SCALES => 0];
        }
        $memoryWithin = [];
        foreach ($routes as $r) {
            foreach (Predictor::QUANTITIES as $q) {
                $category = $r['quantities'][$q]['category'];
                if ($category !== self::IN_DENOMINATOR) {
                    ++$quantities[$q][$category];
                    continue;
                }
                ++$quantities[$q]['denominator'];
                if ($r['quantities'][$q]['outcome'] === 'match') {
                    ++$quantities[$q]['within'];
                    if ($q === 'peak_memory') {
                        $memoryWithin[] = $r['route'];
                    }
                }
            }
        }
        foreach ($quantities as $q => $c) {
            $quantities[$q]['with_formula'] = $c['denominator'] + $c[self::UNMEASURED];
            $quantities[$q]['percent'] = $c['denominator'] === 0 ? null : round($c['within'] * 100 / $c['denominator'], 2);
            $quantities[$q]['tolerance_percent'] = self::TOLERANCE_PERCENT[$q];
        }

        [$verdict, $reasons] = self::verdict($quantities);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'at' => $at,
            'predictions_source' => $predictions['source'] ?? null,
            'measured_source' => $measuredName,
            'verdict' => $verdict,
            'reasons' => $reasons,
            'summary' => ['routes' => count($routes)],
            'quantities' => $quantities,
            'peak_memory_within_tolerance' => $memoryWithin,
            'routes' => $routes,
        ];
    }

    /**
     * Each of the two deciding quantities is judged on its own denominator; if either cannot be judged, the test cannot.
     *
     * @param array<string, array<string, mixed>> $quantities
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function verdict(array $quantities): array
    {
        $reasons = [];
        foreach (self::GATING as $q) {
            $c = $quantities[$q];
            if ($c['denominator'] < self::MIN_DENOMINATOR) {
                $reasons[] = sprintf('%s: the denominator has %d routes, fewer than %d', $q, $c['denominator'], self::MIN_DENOMINATOR);
            }
            if ($c[self::UNMEASURED] * 100 > self::MAX_UNMEASURED_PERCENT * $c['with_formula']) {
                $reasons[] = sprintf('%s: %d of %d routes with a formula could not be measured at the predicted size, more than %d%%', $q, $c[self::UNMEASURED], $c['with_formula'], self::MAX_UNMEASURED_PERCENT);
            }
        }
        if ($reasons !== []) {
            return [self::INDETERMINATE, $reasons];
        }

        $verdict = self::PASS;
        foreach (self::GATING as $q) {
            $c = $quantities[$q];
            $inside = $c['within'] * 100 >= self::PASS_SHARE_PERCENT * $c['denominator'];
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
     * One row per route; every quantity carries its own `category` (and a `reason` when it is not in the denominator).
     *
     * @param array<string, mixed>               $route
     * @param array<string, array<string, mixed>> $cold
     *
     * @return array<string, mixed>
     */
    private static function route(array $route, array $cold): array
    {
        $name = (string) $route['route'];
        $quantities = (array) ($route['quantities'] ?? []);
        $row = ['route' => $name, 'axis' => $route['axis'] ?? null, 'quantities' => []];

        $key = $name . "\0" . ((string) ($route['axis'] ?? ''));
        $a = $cold[$key] ?? null;
        // Why the cold attempt as a whole cannot be used (the same for every quantity); null when it can.
        $attemptProblem = null;
        if ($a === null) {
            $attemptProblem = 'no cold attempt at the predicted size';
        } elseif (($a['class'] ?? null) !== 'ok') {
            $attemptProblem = 'cold attempt class ' . (string) ($a['class'] ?? 'unknown');
        }

        foreach (Predictor::QUANTITIES as $q) {
            $p = (array) ($quantities[$q] ?? []);
            $fit = (string) ($p['fit_status'] ?? 'missing');
            $measured = $a[self::ATTEMPT_FIELD[$q]] ?? null;

            // contradicts_static and insufficient_scales leave this quantity's denominator, and are listed.
            if ($fit === self::CONTRADICTS_STATIC || $fit === self::INSUFFICIENT_SCALES) {
                $row['quantities'][$q] = ['category' => $fit, 'reason' => (string) ($p['reason'] ?? $fit), 'outcome' => 'no_prediction', 'measured' => $measured];
                continue;
            }
            if ($fit !== 'ok') {
                $row['quantities'][$q] = ['category' => self::INSUFFICIENT_SCALES, 'reason' => 'fit_status ' . $fit, 'outcome' => 'no_prediction', 'measured' => $measured];
                continue;
            }
            if (($p['status'] ?? null) !== Predictor::PREDICTED) {
                $row['quantities'][$q] = ['category' => self::INSUFFICIENT_SCALES, 'reason' => (string) ($p['reason'] ?? 'no prediction'), 'outcome' => 'no_prediction', 'measured' => $measured];
                continue;
            }

            // The formula is ok: the quantity is in the denominator when it was measured, and unmeasured when not.
            $problem = $attemptProblem ?? ($measured === null ? $q . ' was not measured' : null);
            if ($problem !== null) {
                $row['quantities'][$q] = ['category' => self::UNMEASURED, 'reason' => $problem, 'outcome' => 'not_measured', 'predicted' => $p['value']];
                continue;
            }
            $row['quantities'][$q] = ['category' => self::IN_DENOMINATOR]
                + self::compare((float) $p['value'], (float) $measured, self::TOLERANCE_PERCENT[$q])
                + ['a' => $p['a'], 'b' => $p['b'], 'k' => $p['k']];
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
     * The result for reading: the verdict, the table of shares, and the routes left out of each denominator.
     *
     * @param array<string, mixed> $result
     */
    public static function toMarkdown(array $result): string
    {
        $md = sprintf("# Extrapolation test at N = %d\n\n", $result['at']);
        $md .= sprintf("Verdict: **%s**\n\n", $result['verdict']);
        foreach ($result['reasons'] as $reason) {
            $md .= '- ' . $reason . "\n";
        }
        $md .= "\n| quantity | inside | denominator | unmeasured | contradicts_static | insufficient_scales | share | tolerance | decides the test |\n| --- | --- | --- | --- | --- | --- | --- | --- | --- |\n";
        foreach ($result['quantities'] as $q => $c) {
            $md .= sprintf(
                "| %s | %d | %d | %d | %d | %d | %s | +-%d%% | %s |\n",
                $q,
                $c['within'],
                $c['denominator'],
                $c[self::UNMEASURED],
                $c[self::CONTRADICTS_STATIC],
                $c[self::INSUFFICIENT_SCALES],
                $c['percent'] === null ? 'n/a' : $c['percent'] . '%',
                $c['tolerance_percent'],
                in_array($q, self::GATING, true) ? 'yes' : 'no',
            );
        }
        $md .= sprintf("\nRoutes: %d. The denominator is counted per quantity (the query count and the examined rows each have their own).\n", $result['summary']['routes']);
        $md .= "\n## Left out of a denominator\n\n";
        $left = [];
        foreach ($result['routes'] as $r) {
            foreach ($r['quantities'] as $q => $c) {
                if ($c['category'] !== self::IN_DENOMINATOR) {
                    $left[] = sprintf("| %s | %s | %s | %s |\n", $r['route'], $q, $c['category'], $c['reason'] ?? '');
                }
            }
        }
        $md .= $left === [] ? "none\n" : "| route | quantity | category | reason |\n| --- | --- | --- | --- |\n" . implode('', $left);
        $md .= "\n## Peak memory inside the tolerance\n\n";
        $md .= $result['peak_memory_within_tolerance'] === [] ? "none\n" : implode("\n", array_map(static fn (string $r): string => '- ' . $r, $result['peak_memory_within_tolerance'])) . "\n";

        return $md;
    }
}
