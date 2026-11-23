<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Estimate;

use PeakSim\Runtime\Judge\CostFit;

/**
 * The mark `offset_chunk_fit` of a route: the route reads a table in chunks by `limit … offset …`, and the formula the estimate fits for those statements does
 * not follow their rows inside the sizes the capture saw.
 *
 * It is a mark of the same kind as `plan_changes_at_scale` and `plan_unstable`: it tells, at the time of the prediction, that the route's estimate is the less
 * reliable one. It is made from the capture and from the estimate alone and uses no measurement of a size above the capture's largest.
 *
 * The rule, per route:
 *
 *   1. Take the statements of the route whose text (the type's pattern, or the representative's SQL) holds an `offset` clause.
 *   2. For each, hold the last captured size out: fit the count and the rows per run (CostFit, as the estimate does) on the sizes before it, put both at the
 *      held-out size, and compare the product (count x rows per run) with the captured one. The relative difference is the held-out error `e`.
 *      A statement whose count, or whose rows per run, stands on fewer than four sizes has no held-out error (three free numbers on three points fit exactly)
 *      and counts for nothing. The rows per run are fitted on the sizes where some run was read, as the estimate fits them; the count on every size.
 *   3. Only a statement the route runs twice or more at N (`count_at_n` of the estimate) counts: one run of `limit 20 offset 0` is a page, not a chunked read.
 *   4. The route's score is S = sum over the counted statements of (the statement's rows at N / the route's rows at N) x |e|.
 *   5. S >= GOAL marks the route. GOAL is the goal of the tool's own rows test (the rows read within 10%); no number was chosen for this rule.
 *
 * Limits, which belong with every use of the mark:
 *
 *   - The held-out step is 3 times the size (10,000 to 30,000) where the prediction is about 8 times (30,000 to 240,000): `e` comes out smaller than the
 *     error of the real prediction, so the score leans low. No mark does not mean safe.
 *   - `e` sees only how badly the formula fits inside the captured sizes. A bend that lies above the largest captured size is not seen.
 *   - The form of the rule was chosen after the outliers it is meant to explain were known, and so was the limit to statements run twice or more. The
 *     records it was applied to are a fit, not a test. Whether it works can be shown only on an application not yet seen.
 */
final class OffsetChunkFit
{
    /** The rows goal of the tool's rows test (simulator-spec.md section 7.4, the target for rows examined): a route is within the goal when the rows it read are within 10%. */
    public const GOAL = 0.10;

    /** Fewest statement runs at N for a statement to count: a chunked read is repeated. */
    public const MIN_RUNS = 2.0;

    /** Fewest captured sizes a held-out error is made from: three sizes leave three free numbers on three points. */
    public const MIN_SCALES = 4;

    public const MARK = 'offset_chunk_fit';

    /** Whether a statement's text holds an `offset` clause (the type's pattern is the text with every literal taken out, the representative's SQL has them). */
    public static function hasOffset(array $captureType): bool
    {
        $rep = $captureType['representative'] ?? null;

        return preg_match('/\boffset\b/i', (string) ($captureType['pattern'] ?? '')) === 1
            || (is_array($rep) && preg_match('/\boffset\b/i', (string) ($rep['sql'] ?? '')) === 1);
    }

    /**
     * The error of the formula at the last captured size when it is made from the sizes before it: (put - captured) / captured, on the product of the count
     * and the rows per run. Null when either column has fewer than MIN_SCALES sizes, or the captured product is 0.
     *
     * The two columns are fitted on the points the estimate itself fits them on (RowEstimator::series and the capture's count fit): the count on every size
     * (also a size where the rows could not be read: the count is still known), the rows per run only on the sizes where some run was read (a size where the
     * statement ran but read nothing, or whose rows could not be read, has no rows per run, and is left out).
     *
     * @param array<string, mixed> $perScale the capture's `per_scale` of the type: size => {count, rows_sum, unread}
     */
    public static function heldOutError(array $perScale): ?float
    {
        $counts = [];
        $rows = [];
        foreach ($perScale as $scale => $v) {
            if (!is_array($v) || !isset($v['count'])) {
                continue;
            }
            $counts[(int) $scale] = (float) $v['count'];
            $read = (int) $v['count'] - (int) ($v['unread'] ?? 0);
            if (($v['rows_sum'] ?? null) !== null && $read > 0) {
                $rows[(int) $scale] = (float) $v['rows_sum'] / $read;
            }
        }
        ksort($counts);
        ksort($rows);
        if (count($counts) < self::MIN_SCALES) {
            return null;
        }
        $last = array_key_last($counts);
        if (!isset($rows[$last]) || count($rows) < self::MIN_SCALES) {
            return null; // nothing was read at the held-out size (captured product 0), or the rows per run stand on fewer than four sizes
        }
        $captured = $counts[$last] * $rows[$last];
        if ($captured <= 0.0) {
            return null;
        }
        $putAt = static function (array $points) use ($last): float {
            unset($points[$last]);
            $f = CostFit::fit(array_keys($points), array_values($points));

            return CostFit::evaluate($f['a'], $f['b'], $f['k'], $last);
        };

        return ($putAt($counts) * $putAt($rows) - $captured) / $captured;
    }

    /**
     * The mark of one route.
     *
     * @param array<string, mixed>        $captureRoute the capture's entry of the route
     * @param list<array<string, mixed>>  $types        the estimate's types of the route (`id`, `count_at_n`, `per_exec`; or `unavailable`)
     *
     * @return array<string, mixed>|null null when the route has no statement with an offset clause (the route carries no such entry at all)
     */
    public static function ofRoute(array $captureRoute, array $types): ?array
    {
        $offsets = [];
        foreach ((array) ($captureRoute['statement_types'] ?? []) as $ty) {
            if (is_array($ty) && self::hasOffset($ty)) {
                $offsets[(string) ($ty['id'] ?? '')] = $ty;
            }
        }
        if ($offsets === []) {
            return null;
        }
        $total = 0.0;
        $complete = true;
        $byId = [];
        foreach ($types as $t) {
            if (isset($t['unavailable'])) {
                $complete = false;
                continue;
            }
            $total += (float) $t['per_exec'] * (float) $t['count_at_n'];
            $byId[(string) $t['id']] = $t;
        }
        $statements = [];
        $score = 0.0;
        foreach ($offsets as $id => $ty) {
            $t = $byId[$id] ?? null;
            $rows = $t === null ? null : (float) $t['per_exec'] * (float) $t['count_at_n'];
            $share = $t === null || !$complete || $total <= 0.0 ? null : $rows / $total;
            $error = self::heldOutError((array) ($ty['per_scale'] ?? []));
            $reason = match (true) {
                $t === null || !$complete => 'route_incomplete',
                (float) $t['count_at_n'] < self::MIN_RUNS => 'fewer_than_two_runs',
                $error === null => 'captured_at_fewer_than_four_sizes',
                default => null,
            };
            $counted = $reason === null && $share !== null;
            if ($counted) {
                $score += $share * abs($error);
            }
            $statements[] = [
                'id' => $id,
                'count_at_n' => $t === null ? null : (float) $t['count_at_n'],
                'share' => $share,
                'held_out_error' => $error,
                'counted' => $counted,
                'not_counted_because' => $reason,
            ];
        }
        usort($statements, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

        return [
            'marked' => $score >= self::GOAL,
            'score' => $score,
            'goal' => self::GOAL,
            'statements' => $statements,
        ];
    }
}
