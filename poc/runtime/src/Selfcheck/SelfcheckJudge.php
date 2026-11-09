<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Selfcheck;

use PeakSim\Runtime\Judge\ExtrapolationJudge;

/**
 * Compares a sealed estimate with what was measured at the same size: the comparison of the 200,000-row extrapolation test
 * (docs/simulator-spec.md 7.1 and 7.4), run on the user's own app (8.1). Nothing is decided here that the spec has not
 * already decided; the numbers are the spec's and are taken from ExtrapolationJudge where it holds them.
 *
 *  - Rule A (shown, does not decide): every route against its single cold value.
 *  - Rule B (decides): an unmarked route against its single cold value; a marked route against the largest of all the cold
 *    values measured at the size (the one of the measurement and the extra ones). A failed try is a try without a value;
 *    a marked route with a try that ended at the 600-second cut is not measured under rule B (D126, D126b).
 *  - Allowed error: the lowest standard 5% (queries), 20% (rows examined), 30% (peak memory); the goal 2%, 10%, 30%.
 *  - Verdict: queries and rows examined each inside the lowest standard for 80% or more of their own denominator;
 *    indeterminate (never a pass) with fewer than 5 routes in a denominator or more than 20% of the routes with an estimate
 *    unmeasured (D95).
 *
 * Reads decoded data only. The same input always gives the same output.
 */
final class SelfcheckJudge
{
    public const SCHEMA_VERSION = '0.1';

    public const QUANTITIES = ['queries', 'rows_examined', 'peak_memory'];

    public const GATING = ['queries', 'rows_examined'];

    /** The goal (D71), in percent. */
    public const GOAL_PERCENT = ['queries' => 2, 'rows_examined' => 10, 'peak_memory' => 30];

    public const GROUPS = ['all', 'unmarked', 'marked'];

    /** Upper edges (percent) of the buckets the errors are counted in; a last bucket takes the rest. */
    public const ERROR_BUCKETS = [2, 5, 10, 20, 30];

    private const ATTEMPT_FIELD = ['queries' => 'queries', 'rows_examined' => 'rows_examined', 'peak_memory' => 'peak_mem'];

    private const COLD = 2;

    /**
     * @param array<string, mixed>       $estimate        the sealed estimate (bin/estimate --candidate=H1_RANGE)
     * @param array<string, mixed>       $markedList      the sealed list of marked routes (bin/marked-routes)
     * @param list<array<string, mixed>> $measureAttempts the records of the measurement (attempts.jsonl of bin/measure)
     * @param list<list<array<string, mixed>>> $extraTries one list of records per extra cold try file (attempt.jsonl of bin/variance-run)
     * @param array<string, mixed>|null  $explainTotals   `totals` of the explain.json of the target size
     *
     * @return array<string, mixed>
     */
    public static function judge(array $estimate, array $markedList, array $measureAttempts, array $extraTries, ?array $explainTotals = null): array
    {
        $at = (int) ($estimate['at'] ?? 0);
        if ($at < 1) {
            throw new \InvalidArgumentException('the estimate has no "at"');
        }
        $marked = [];
        foreach ((array) ($markedList['routes'] ?? []) as $r) {
            $marked[(string) ((array) $r)['route']] = true;
        }

        $measureTry = [];
        $knownMeasured = [];
        foreach (self::groupByRoute($measureAttempts) as $key => $records) {
            $knownMeasured[$key] = true;
            $try = self::makeTry($records, $at);
            if ($try !== null) {
                $measureTry[$key] = $try;
            }
        }
        $extraTry = [];
        foreach ($extraTries as $records) {
            foreach (self::groupByRoute($records) as $key => $recs) {
                $try = self::makeTry($recs, $at);
                if ($try !== null) {
                    $extraTry[$key][] = $try;
                }
            }
        }

        $problems = [];
        $rows = [];
        $seen = [];
        foreach ((array) ($estimate['routes'] ?? []) as $r) {
            $r = (array) $r;
            $route = (string) $r['route'];
            $seen[$route] = true;
            $isMarked = isset($marked[$route]);
            if (((bool) ($r['marked'] ?? false)) !== $isMarked) {
                $problems[] = $route . ': `marked` of the estimate differs from the list of marked routes';
            }
            $rows[] = self::row($r, $isMarked, $measureTry[$route] ?? null, $extraTry[$route] ?? []);
        }
        usort($rows, static fn (array $x, array $y): int => strcmp($x['route'], $y['route']));
        $notEstimated = array_values(array_filter(array_keys($knownMeasured), static fn (string $k): bool => !isset($seen[$k])));
        sort($notEstimated);

        $rules = [];
        foreach (['A', 'B'] as $rule) {
            [$verdict, $reasons] = self::verdict($rows, $rule);
            $shares = [];
            foreach (self::GROUPS as $g) {
                foreach (self::QUANTITIES as $q) {
                    $shares[$g][$q] = self::tally($rows, $rule, $g, $q);
                }
            }
            $distribution = [];
            foreach (self::QUANTITIES as $q) {
                $distribution[$q] = self::distribution($rows, $rule, $q);
            }
            $rules[$rule] = ['verdict' => $verdict, 'reasons' => $reasons, 'shares' => $shares, 'error_distribution' => $distribution];
        }

        $failed = [];
        foreach ($rows as $row) {
            foreach ($row['failed_tries'] as $f) {
                $failed[] = ['route' => $row['route'], 'class' => $f['class']];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'at' => $at,
            'decides_by' => 'B',
            'summary' => self::summary($rules['B'], $rows, $explainTotals),
            'rules' => $rules,
            'explain' => $explainTotals === null ? null : [
                'statement_types' => $explainTotals['statement_types'] ?? null,
                'plan_unstable' => $explainTotals['plan_unstable'] ?? null,
                'plan_changes_at_scale' => $explainTotals['plan_changes_at_scale'] ?? null,
                'explain_unavailable' => $explainTotals['explain_unavailable'] ?? null,
            ],
            'routes' => $rows,
            'failed_tries' => $failed,
            'measured_without_estimate' => $notEstimated,
            'problems' => $problems,
        ];
    }

    /**
     * The short form that the output of `simulate` carries as "accuracy on this app" (section 6.4).
     *
     * @param array<string, mixed>       $ruleB
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>|null  $explainTotals
     *
     * @return array<string, mixed>
     */
    private static function summary(array $ruleB, array $rows, ?array $explainTotals): array
    {
        $quantities = [];
        foreach (self::QUANTITIES as $q) {
            $t = $ruleB['shares']['all'][$q];
            $quantities[$q] = [
                'denominator' => $t['denominator'],
                'within_lowest_standard' => $t['within_min'],
                'within_lowest_standard_percent' => self::share($t['within_min'], $t['denominator']),
                'within_goal' => $t['within_goal'],
                'within_goal_percent' => self::share($t['within_goal'], $t['denominator']),
                'unmeasured' => $t['unmeasured'],
                'tolerance_percent' => ExtrapolationJudge::TOLERANCE_PERCENT[$q],
                'goal_percent' => self::GOAL_PERCENT[$q],
            ];
        }
        $markedCount = count(array_filter($rows, static fn (array $r): bool => $r['marked']));

        return [
            'verdict' => $ruleB['verdict'],
            'reasons' => $ruleB['reasons'],
            'routes' => count($rows),
            'marked_routes' => $markedCount,
            'unmarked_routes' => count($rows) - $markedCount,
            'quantities' => $quantities,
            'plan_unstable_statements' => $explainTotals['plan_unstable'] ?? null,
            'explain_unavailable_statements' => $explainTotals['explain_unavailable'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>      $est
     * @param array<string, mixed>|null $measureTry
     * @param list<array<string, mixed>> $extra
     *
     * @return array<string, mixed>
     */
    private static function row(array $est, bool $marked, ?array $measureTry, array $extra): array
    {
        $first = $measureTry !== null && $measureTry['ok'] ? $measureTry['cold'] : null;
        $colds = $first !== null ? [$first] : [];
        $fails = [];
        if ($measureTry !== null && !$measureTry['ok']) {
            $fails[] = $measureTry;
        }
        foreach ($extra as $t) {
            if ($t['ok']) {
                $colds[] = $t['cold'];
            } else {
                $fails[] = $t;
            }
        }
        $timedOut = false;
        foreach ($fails as $f) {
            $timedOut = $timedOut || $f['class'] === 'timeout';
        }
        $failedTries = array_map(static fn (array $f): array => ['class' => $f['class'], 'status' => $f['status'], 'detail' => $f['detail']], $fails);
        $failText = $fails === [] ? '' : '; failed tries: ' . implode(', ', array_map(static fn (array $f): string => $f['class'], $fails));

        $cells = [];
        foreach (self::QUANTITIES as $q) {
            [$pred, $why] = self::estimateOf($est, $q);
            $values = [];
            foreach ($colds as $a) {
                $v = self::value($a, $q);
                if ($v !== null) {
                    $values[] = $v;
                }
            }
            $v1 = $first === null ? null : self::value($first, $q);
            $cell = ['predicted' => $pred, 'cold_values' => $values, 'cold_first' => $v1];
            if ($pred === null) {
                $cell += ['category' => 'no_estimate', 'reason' => $why];
            } elseif ($values === []) {
                $cell += ['category' => 'unmeasured', 'reason' => ($colds === [] ? 'no cold value at the size' : $q . ' not in the cold record') . $failText];
            } else {
                $cell['category'] = 'in_denominator';
                $ref = ['A' => $v1, 'B' => $marked ? max($values) : $v1];
                foreach (['A', 'B'] as $rule) {
                    if ($rule === 'B' && $marked && $timedOut) {
                        $cell[$rule] = ['category' => 'unmeasured', 'reason' => '600 seconds or more (at least one cold try timed out)' . $failText];
                    } elseif ($ref[$rule] === null) {
                        $cell[$rule] = ['category' => 'unmeasured', 'reason' => 'the cold value of the measurement run is missing' . $failText];
                    } else {
                        $min = ExtrapolationJudge::compare($pred, $ref[$rule], ExtrapolationJudge::TOLERANCE_PERCENT[$q]);
                        $goal = ExtrapolationJudge::compare($pred, $ref[$rule], self::GOAL_PERCENT[$q]);
                        $cell[$rule] = [
                            'category' => 'in_denominator',
                            'measured' => $ref[$rule],
                            'relative_error' => $min['relative_error'],
                            'within_min' => $min['outcome'] === 'match',
                            'within_goal' => $goal['outcome'] === 'match',
                        ];
                    }
                }
            }
            $cells[$q] = $cell;
        }

        return [
            'route' => (string) $est['route'],
            'marked' => $marked,
            'cold_values_used' => count($colds),
            'failed_tries' => $failedTries,
            'upper_rows_examined' => $est['rows_examined_upper'] ?? null,
            'q' => $cells,
        ];
    }

    /**
     * @param array<string, mixed> $est
     *
     * @return array{0: float|null, 1: string|null}
     */
    private static function estimateOf(array $est, string $q): array
    {
        if ($q === 'peak_memory') {
            $p = (array) ($est['peak_memory'] ?? []);
            if (($p['status'] ?? null) === 'predicted' && isset($p['value'])) {
                return [(float) $p['value'], null];
            }

            return [null, 'peak_memory ' . ($p['status'] ?? 'missing')];
        }
        if (!($est['complete'] ?? false)) {
            return [null, 'estimate incomplete (' . ($est['status'] ?? 'unknown') . ')'];
        }
        if (!isset($est[$q])) {
            return [null, 'no ' . $q];
        }

        return [(float) $est[$q], null];
    }

    /** @param array<string, mixed> $a */
    private static function value(array $a, string $q): ?float
    {
        $v = $a[self::ATTEMPT_FIELD[$q]] ?? null;

        return $v === null ? null : (float) $v;
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function groupByRoute(array $records): array
    {
        $by = [];
        foreach ($records as $a) {
            $by[((string) ($a['method'] ?? '')) . ' ' . ((string) ($a['route'] ?? ''))][] = $a;
        }

        return $by;
    }

    /**
     * One try of one route at the size: the cold record if it is ok, else the worst record, else nothing.
     *
     * @param list<array<string, mixed>> $records
     *
     * @return array<string, mixed>|null
     */
    private static function makeTry(array $records, int $at): ?array
    {
        $here = array_values(array_filter($records, static fn (array $a): bool => (int) ($a['scale'] ?? -1) === $at && ($a['axis'] ?? 'N') === 'N'));
        foreach ($here as $a) {
            if ((int) ($a['attempt'] ?? 0) === self::COLD && ($a['class'] ?? null) === 'ok') {
                return ['ok' => true, 'cold' => $a];
            }
        }
        $bad = array_values(array_filter($here, static fn (array $a): bool => ($a['class'] ?? null) !== 'ok'));
        if ($bad === []) {
            return null;
        }
        $worst = $bad[0];
        foreach ($bad as $a) {
            if ((int) ($a['attempt'] ?? 0) > (int) ($worst['attempt'] ?? 0)) {
                $worst = $a;
            }
        }

        return ['ok' => false, 'class' => self::failureClass($worst), 'status' => $worst['status'] ?? null, 'detail' => substr((string) ($worst['detail'] ?? ''), 0, 120)];
    }

    /** timeout | 422 | 500 | exception | other:<class> (D126, D126b: by what the record says, not by its class name). */
    private static function failureClass(array $rec): string
    {
        $class = $rec['class'] ?? null;
        $status = $rec['status'] ?? null;
        if ($class === 'timeout' || str_contains((string) ($rec['detail'] ?? ''), 'ran longer than 600 seconds')) {
            return 'timeout';
        }
        if ($status === 422 || $class === 'validation_422') {
            return '422';
        }
        if ($status === 500) {
            return '500';
        }
        if ($class === 'exception') {
            return 'exception';
        }

        return 'other:' . (string) $class;
    }

    /** @param array<string, mixed> $cell */
    private static function category(array $cell, string $rule): string
    {
        return $cell['category'] !== 'in_denominator' ? $cell['category'] : $cell[$rule]['category'];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, int>
     */
    private static function tally(array $rows, string $rule, string $group, string $q): array
    {
        $t = ['routes' => 0, 'denominator' => 0, 'within_min' => 0, 'within_goal' => 0, 'unmeasured' => 0, 'no_estimate' => 0];
        foreach ($rows as $r) {
            if ($group !== 'all' && ($group === 'marked') !== $r['marked']) {
                continue;
            }
            ++$t['routes'];
            $cell = $r['q'][$q];
            $c = self::category($cell, $rule);
            if ($c === 'in_denominator') {
                ++$t['denominator'];
                $t['within_min'] += $cell[$rule]['within_min'] ? 1 : 0;
                $t['within_goal'] += $cell[$rule]['within_goal'] ? 1 : 0;
            } else {
                ++$t[$c];
            }
        }
        $t['with_estimate'] = $t['denominator'] + $t['unmeasured'];

        return $t;
    }

    private static function share(int $n, int $d): ?float
    {
        return $d === 0 ? null : round($n * 100 / $d, 2);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function verdict(array $rows, string $rule): array
    {
        $reasons = [];
        foreach (self::GATING as $q) {
            $t = self::tally($rows, $rule, 'all', $q);
            if ($t['denominator'] < ExtrapolationJudge::MIN_DENOMINATOR) {
                $reasons[] = sprintf('%s: the denominator has %d routes, fewer than %d', $q, $t['denominator'], ExtrapolationJudge::MIN_DENOMINATOR);
            }
            if ($t['unmeasured'] * 100 > ExtrapolationJudge::MAX_UNMEASURED_PERCENT * $t['with_estimate']) {
                $reasons[] = sprintf('%s: %d of %d routes with an estimate could not be measured, more than %d%%', $q, $t['unmeasured'], $t['with_estimate'], ExtrapolationJudge::MAX_UNMEASURED_PERCENT);
            }
        }
        if ($reasons !== []) {
            return [ExtrapolationJudge::INDETERMINATE, $reasons];
        }
        $verdict = ExtrapolationJudge::PASS;
        $why = [];
        foreach (self::GATING as $q) {
            $t = self::tally($rows, $rule, 'all', $q);
            $ok = $t['within_min'] * 100 >= ExtrapolationJudge::PASS_SHARE_PERCENT * $t['denominator'];
            $why[] = sprintf('%s: %d of %d inside +-%d%% (%s), needs %d%%', $q, $t['within_min'], $t['denominator'], ExtrapolationJudge::TOLERANCE_PERCENT[$q], $ok ? 'enough' : 'not enough', ExtrapolationJudge::PASS_SHARE_PERCENT);
            if (!$ok) {
                $verdict = ExtrapolationJudge::FAIL;
            }
        }

        return [$verdict, $why];
    }

    /**
     * How the relative errors of one quantity are spread (the routes in its denominator under the rule): counts per bucket,
     * and the min, median, 80th and 90th percentile (nearest rank) and max. A route whose measured value is 0 has no relative
     * error and is counted apart.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private static function distribution(array $rows, string $rule, string $q): array
    {
        $errors = [];
        $zeroMatched = 0;
        $zeroMissed = 0;
        foreach ($rows as $r) {
            $cell = $r['q'][$q];
            if (self::category($cell, $rule) !== 'in_denominator') {
                continue;
            }
            $x = $cell[$rule];
            if ($x['relative_error'] === null) {
                $x['within_min'] ? ++$zeroMatched : ++$zeroMissed;
                continue;
            }
            $errors[] = (float) $x['relative_error'] * 100;
        }
        sort($errors);
        $buckets = [];
        $edges = self::ERROR_BUCKETS;
        $prev = 0;
        foreach ($edges as $edge) {
            $buckets['<=' . $edge . '%'] = 0;
            $prev = $edge;
        }
        $buckets['>' . $prev . '%'] = 0;
        foreach ($errors as $e) {
            $placed = false;
            foreach ($edges as $edge) {
                if ($e <= $edge * (1 + 1e-12)) {
                    ++$buckets['<=' . $edge . '%'];
                    $placed = true;
                    break;
                }
            }
            if (!$placed) {
                ++$buckets['>' . $prev . '%'];
            }
        }
        $n = count($errors);
        $rank = static fn (float $p): ?float => $n === 0 ? null : round($errors[max(0, (int) ceil($p * $n) - 1)], 4);

        return [
            'count' => $n,
            'zero_measured_matched' => $zeroMatched,
            'zero_measured_missed' => $zeroMissed,
            'percent_buckets' => $buckets,
            'min_percent' => $n === 0 ? null : round($errors[0], 4),
            'median_percent' => $rank(0.5),
            'p80_percent' => $rank(0.8),
            'p90_percent' => $rank(0.9),
            'max_percent' => $n === 0 ? null : round($errors[$n - 1], 4),
        ];
    }
}
