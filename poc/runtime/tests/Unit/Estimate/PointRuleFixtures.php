<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Estimate;

use PeakSim\Runtime\Estimate\RowEstimator;

/**
 * Made-up capture and EXPLAIN for the point rules P1 and P2 of `RowEstimator` (H1_RANGE). One route, N = 100,000, captured at 100 / 1,000 / 10,000.
 *
 * Two routes of statements:
 *
 *   `GET /moved`     every statement is one the rules move, or one they must leave alone, named for the case it is (the ids say which).
 *   `GET /untouched` marked statements and unmarked ones that neither rule moves. Its estimate must be what it was before the rules existed
 *                    (`golden/point-rules-untouched.json`, made with the code of the commit before the rules).
 */
final class PointRuleFixtures
{
    public const N = 100000;

    /** @return array<string, mixed> a plan that reads one table by $access, $rows rows per scan */
    public static function plan(string $access, int $rows): array
    {
        return ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 't', 'access_type' => $access, 'rows_examined_per_scan' => $rows, 'rows_produced_per_join' => $rows, 'filtered' => '100.00']]];
    }

    /** @return array<string, mixed> a join of a full scan of $rows rows and a lookup by key */
    public static function joinPlan(int $rows): array
    {
        return ['query_block' => ['select_id' => 1, 'nested_loop' => [
            ['table' => ['table_name' => 'a', 'access_type' => 'ALL', 'rows_examined_per_scan' => $rows, 'rows_produced_per_join' => $rows, 'filtered' => '100.00']],
            ['table' => ['table_name' => 'b', 'access_type' => 'eq_ref', 'rows_examined_per_scan' => 1, 'rows_produced_per_join' => $rows, 'filtered' => '100.00']],
        ]]];
    }

    /** @return array<string, mixed> a full scan of the table with a subquery that reads another table */
    public static function subqueryPlan(int $rows): array
    {
        return ['query_block' => ['select_id' => 1, 'table' => [
            'table_name' => 'a', 'access_type' => 'ALL', 'rows_examined_per_scan' => $rows, 'rows_produced_per_join' => $rows, 'filtered' => '100.00',
            'attached_subqueries' => [['dependent' => false, 'cacheable' => true, 'query_block' => ['select_id' => 2, 'table' => ['table_name' => 'c', 'access_type' => 'ALL', 'rows_examined_per_scan' => 3, 'rows_produced_per_join' => 3, 'filtered' => '100.00']]]],
        ]]];
    }

    /**
     * @param list<int|float|null> $perExec rows per run at 100 / 1,000 / 10,000 (null: the rows were not read at that scale)
     *
     * @return array<string, mixed>
     */
    public static function captureType(string $id, int $count, array $perExec): array
    {
        $perScale = [];
        foreach ([100, 1000, 10000] as $i => $scale) {
            $perScale[(string) $scale] = $perExec[$i] === null
                ? ['count' => 10, 'texts' => 1, 'rows_sum' => null, 'rows_min' => 0, 'rows_max' => 0, 'unread' => 10]
                : ['count' => 10, 'texts' => 1, 'rows_sum' => $perExec[$i] * 10, 'rows_min' => 0, 'rows_max' => 0, 'unread' => 0];
        }

        return [
            'id' => $id, 'kind' => 'select', 'pattern' => 'select ' . $id,
            'representative' => ['scale' => 10000, 'sql' => 'select ' . $id, 'bindings' => []],
            'per_scale' => $perScale,
            'count_fit' => ['fit_status' => 'ok', 'a' => (float) $count, 'b' => 0.0, 'k' => 0.0, 'sse' => 0.0, 'scales' => [100, 1000, 10000]],
        ];
    }

    /**
     * @param array<string, mixed>|null $small the plan on the small side (default state), null: not taken
     * @param array<string, mixed>|null $target the plan at N (default state)
     *
     * @return array<string, mixed>
     */
    public static function statement(string $id, bool $changes, bool $unstable, ?array $small, ?array $target): array
    {
        $state = static fn (?array $plan): array => $plan === null ? [] : ['default' => ['summary' => [], 'raw' => $plan]];

        return [
            'id' => $id,
            'marks' => ['plan_unstable' => $unstable, 'plan_changes_at_scale' => $changes, 'explain_unavailable' => null, 'unavailable_states' => [], 'unstable_states' => []],
            'small_scale' => $state($small),
            'target_scale' => $state($target),
        ];
    }

    /**
     * @param array<string, array{count: int, rows: list<int|float|null>}> $types
     * @param list<array<string, mixed>>                              $statements
     *
     * @return array{array<string, mixed>, array<string, mixed>} capture and explain
     */
    public static function route(string $name, array $types, array $statements): array
    {
        $captureTypes = [];
        foreach ($types as $id => $t) {
            $captureTypes[] = self::captureType($id, $t['count'], $t['rows']);
        }

        return [
            ['route' => $name, 'status' => 'captured', 'statement_types' => $captureTypes],
            ['route' => $name, 'statements' => $statements],
        ];
    }

    /** @return array{array<string, mixed>, array<string, mixed>} capture and explain of both routes */
    public static function fixture(): array
    {
        $capture = ['capture_version' => 1, 'app' => 'demo', 'scales' => [100, 1000, 10000], 'representative_scale' => 10000, 'routes' => []];
        $explain = ['explain_version' => 1, 'scale' => self::N, 'small_scale' => 10000, 'routes' => []];
        $s = static fn (string $id, bool $c, bool $u, ?array $small, ?array $target): array => self::statement($id, $c, $u, $small, $target);

        // GET /moved: each statement reads 50 rows per run at every size (a flat fit), count 1, unless said
        [$c, $e] = self::route('GET /moved', [
            'p1_scan' => ['count' => 1, 'rows' => [50, 50, 50]],
            'p1_scan_below_fit' => ['count' => 1, 'rows' => [10, 100, 1000]],
            'p1_join' => ['count' => 1, 'rows' => [50, 50, 50]],
            'p1_subquery' => ['count' => 1, 'rows' => [50, 50, 50]],
            'p1_ref' => ['count' => 1, 'rows' => [50, 50, 50]],
            'p1_unstable_only' => ['count' => 1, 'rows' => [50, 50, 50]],
            'p1_unmarked' => ['count' => 1, 'rows' => [50, 50, 50]],
            'p1_no_plan' => ['count' => 1, 'rows' => [50, 50, 50]],
            'p2_changes' => ['count' => 1, 'rows' => [50, 500, 1000]],
            'p2_unstable' => ['count' => 1, 'rows' => [3, 30, 60]],
            'p2_unmarked' => ['count' => 1, 'rows' => [3, 30, 60]],
        ], [
            $s('p1_scan', true, false, self::plan('ALL', 1000), self::plan('ALL', 99000)),
            $s('p1_scan_below_fit', true, false, self::plan('ALL', 1000), self::plan('ALL', 20)),
            $s('p1_join', true, false, self::joinPlan(1000), self::joinPlan(99000)),
            $s('p1_subquery', true, false, self::subqueryPlan(1000), self::subqueryPlan(99000)),
            $s('p1_ref', true, false, self::plan('ref', 1000), self::plan('ref', 99000)),
            $s('p1_unstable_only', false, true, self::plan('ALL', 1000), self::plan('ALL', 99000)),
            $s('p1_unmarked', false, false, self::plan('ALL', 1000), self::plan('ALL', 99000)),
            $s('p1_no_plan', true, false, null, null),
            $s('p2_changes', true, false, self::plan('ref', 1000), self::plan('ref', 2000)),
            $s('p2_unstable', false, true, self::plan('ref', 1000), self::plan('ref', 2000)),
            $s('p2_unmarked', false, false, self::plan('ref', 1000), self::plan('ref', 2000)),
        ]);
        $capture['routes'][] = $c;
        $explain['routes'][] = $e;

        // GET /untouched: marked statements that neither rule moves (a join, or a fit steeper than the line, or too few scales for a fit), and statements with no mark
        [$c, $e] = self::route('GET /untouched', [
            'join_steep' => ['count' => 2, 'rows' => [10, 1000, 100000]],
            'ref_steep' => ['count' => 3, 'rows' => [10, 1000, 100000]],
            'unstable_steep' => ['count' => 1, 'rows' => [10, 1000, 100000]],
            'held_marked' => ['count' => 1, 'rows' => [null, null, 40]],
            'plain_a' => ['count' => 4, 'rows' => [10, 100, 1000]],
            'plain_b' => ['count' => 5, 'rows' => [7, 7, 7]],
        ], [
            $s('join_steep', true, false, self::joinPlan(1000), self::joinPlan(99000)),
            $s('ref_steep', true, false, self::plan('ref', 5), self::plan('ref', 8)),
            $s('unstable_steep', false, true, self::plan('ref', 1000), self::plan('ref', 100000)),
            $s('held_marked', true, false, self::plan('ref', 1000), self::plan('ref', 2000)),
            $s('plain_a', false, false, self::plan('ref', 1000), self::plan('ref', 10000)),
            $s('plain_b', false, false, self::plan('ref', 5), self::plan('ref', 8)),
        ]);
        $capture['routes'][] = $c;
        $explain['routes'][] = $e;

        return [$capture, $explain];
    }

    /**
     * What every candidate says of the fixture's `GET /untouched` route and what H1, H2 and H3 say of both routes. These are the outputs that the rules must not change.
     *
     * @return array<string, mixed>
     */
    public static function untouchedOutputs(): array
    {
        [$capture, $explain] = self::fixture();
        $only = static function (array $doc, string $route): array {
            $doc['routes'] = array_values(array_filter($doc['routes'], static fn (array $r): bool => $r['route'] === $route));

            return $doc;
        };
        $out = [];
        foreach (RowEstimator::CANDIDATES as $candidate) {
            $out[$candidate . ' GET /untouched'] = RowEstimator::estimate($only($capture, 'GET /untouched'), $only($explain, 'GET /untouched'), $candidate, null, null, true);
        }
        // at a size that is not above the largest captured scale, P2 does not apply
        $out['H1_RANGE GET /untouched at 10000'] = RowEstimator::estimate($only($capture, 'GET /untouched'), $only($explain, 'GET /untouched'), 'H1_RANGE', 10000, null, true);
        foreach (['H1', 'H2', 'H3'] as $candidate) {
            $out[$candidate . ' both routes'] = RowEstimator::estimate($capture, $explain, $candidate);
        }

        return $out;
    }
}
