<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Estimate;

/**
 * E(s, N): the rows one execution of a statement reads, according to its `EXPLAIN FORMAT=JSON` (decoded). This is the
 * "C2" reading of `docs/research/explain-estimate.md` (sections 2 and 4): the nested scan sum of the plan's tables, with
 * the subquery rules, plus a per-scan overhead by access type. It is the figure the ratio candidates H2 and H3 use at two
 * sizes; it is never used as a size by itself.
 *
 *   scans of the first table                = loops
 *   scans of table i (i > 1)                = loops x rows_produced_per_join(table i-1)
 *   scans of a table with a join buffer     = loops
 *   reads of a table                        = scans x rows_examined_per_scan
 *   per-scan overhead of a table (C2)       = scans x c(access_type)
 *
 * A node this class does not name, where the walk cannot go on without it, raises UnrecognisedPlan; the caller marks the
 * statement `explain_unavailable` (it is never read as 0).
 */
final class PlanReads
{
    /** The per-scan overhead c(access_type) of the C2 reading. */
    private const OVERHEAD = [
        'ALL' => 2, 'index' => 2,
        'ref' => 1, 'ref_or_null' => 1, 'range' => 1, 'index_merge' => 1, 'fulltext' => 1,
        'unique_subquery' => 1, 'index_subquery' => 1,
        'eq_ref' => 0, 'const' => 0, 'system' => 0,
    ];

    private const WRAPPERS = ['ordering_operation', 'grouping_operation', 'duplicates_removal', 'buffer_result', 'windowing'];

    private const BLOCK_PARTS = ['table', 'nested_loop', 'ordering_operation', 'grouping_operation', 'duplicates_removal', 'buffer_result', 'windowing', 'union_result'];

    private const ROW_SUBQUERIES = ['select_list_subqueries', 'having_subqueries', 'order_by_subqueries', 'group_by_subqueries'];

    /**
     * @param array<string, mixed> $plan the decoded EXPLAIN FORMAT=JSON (it has a `query_block`)
     *
     * @throws UnrecognisedPlan
     */
    public static function perExecution(array $plan): float
    {
        if (!isset($plan['query_block']) || !is_array($plan['query_block'])) {
            throw new UnrecognisedPlan('no query_block');
        }

        return self::block($plan['query_block'], 1.0)[0];
    }

    /**
     * @param array<string, mixed> $qb
     *
     * @return array{float, float} reads (with the overhead) of the block, and the rows the block's last table produces
     *
     * @throws UnrecognisedPlan
     */
    private static function block(array $qb, float $loops): array
    {
        $hasPart = false;
        foreach (self::BLOCK_PARTS as $part) {
            if (isset($qb[$part])) {
                $hasPart = true;
                break;
            }
        }
        if (isset($qb['message']) && !$hasPart) {
            return [0.0, 0.0];
        }
        $tables = [];
        $subs = [];
        self::descend($qb, $tables, $subs);
        $reads = 0.0;
        $prevCum = null;
        $first = true;
        $lastRows = 1.0;
        foreach ($tables as $t) {
            if (isset($t['_union'])) {
                foreach ((array) ($t['_union']['query_specifications'] ?? []) as $spec) {
                    if (!is_array($spec) || !isset($spec['query_block']) || !is_array($spec['query_block'])) {
                        throw new UnrecognisedPlan('union specification without query_block');
                    }
                    $reads += self::block($spec['query_block'], $loops)[0];
                }
                continue;
            }
            $access = isset($t['access_type']) ? (string) $t['access_type'] : null;
            $rows = self::number($t['rows_examined_per_scan'] ?? null);
            $filtered = isset($t['filtered']) && is_numeric($t['filtered']) ? (float) $t['filtered'] : 100.0;
            $insert = ($t['insert'] ?? false) === true;
            if ($first || isset($t['using_join_buffer'])) {
                $scans = $loops;
            } else {
                $scans = $loops * ($prevCum ?? 1.0);
            }
            if ($rows === null) {
                if (!$insert && !isset($t['message'])) {
                    throw new UnrecognisedPlan('table without rows_examined_per_scan: ' . (string) ($t['table_name'] ?? '?'));
                }
                $rowsHere = 0.0;
            } else {
                $rowsHere = $rows;
            }
            if ($access !== null && !isset(self::OVERHEAD[$access]) && !$insert && $rows !== null) {
                throw new UnrecognisedPlan('access_type ' . $access);
            }
            $c = ($insert || $rows === null || $access === null) ? 0 : self::OVERHEAD[$access];
            $reads += $scans * $rowsHere + $scans * $c;
            $produced = self::number($t['rows_produced_per_join'] ?? null);
            $cum = $produced ?? ($prevCum ?? 1.0) * $rowsHere * $filtered / 100.0;
            $prevCum = $cum;
            $lastRows = $cum;
            $first = false;
            foreach ((array) ($t['attached_subqueries'] ?? []) as $sq) {
                $subs[] = ['table', $sq, $scans * $rowsHere];
            }
            if (isset($t['materialized_from_subquery'])) {
                $m = $t['materialized_from_subquery'];
                if (!is_array($m) || !isset($m['query_block']) || !is_array($m['query_block'])) {
                    throw new UnrecognisedPlan('materialized_from_subquery without query_block');
                }
                $reads += self::block($m['query_block'], $loops)[0];
            }
        }
        foreach ($subs as $s) {
            $sq = $s[1];
            if (!is_array($sq) || !isset($sq['query_block']) || !is_array($sq['query_block'])) {
                throw new UnrecognisedPlan('subquery without query_block');
            }
            $perRow = ($sq['dependent'] ?? false) === true || ($sq['cacheable'] ?? true) === false;
            if (!$perRow) {
                $l = $loops;
            } elseif ($s[0] === 'row') {
                $l = $loops * $lastRows;
            } else {
                $l = $s[2];
            }
            $reads += self::block($sq['query_block'], $l)[0];
        }

        return [$reads, $lastRows];
    }

    /**
     * @param array<string, mixed>       $node
     * @param list<array<string, mixed>> $tables
     * @param list<array{string, mixed}> $subs
     *
     * @throws UnrecognisedPlan
     */
    private static function descend(array $node, array &$tables, array &$subs): void
    {
        foreach ($node as $key => $val) {
            if ($key === 'table' && is_array($val)) {
                $tables[] = $val;
            } elseif ($key === 'nested_loop' && is_array($val)) {
                foreach ($val as $item) {
                    if (!is_array($item)) {
                        throw new UnrecognisedPlan('nested_loop item is not a node');
                    }
                    $named = isset($item['table']);
                    foreach ([...self::WRAPPERS, 'nested_loop'] as $w) {
                        $named = $named || isset($item[$w]);
                    }
                    if (!$named) {
                        throw new UnrecognisedPlan('nested_loop item without table: ' . implode(',', array_keys($item)));
                    }
                    self::descend($item, $tables, $subs);
                }
            } elseif (in_array($key, self::WRAPPERS, true) && is_array($val)) {
                self::descend($val, $tables, $subs);
            } elseif ($key === 'union_result' && is_array($val)) {
                $tables[] = ['_union' => $val];
            } elseif (in_array($key, self::ROW_SUBQUERIES, true) && is_array($val)) {
                foreach ($val as $s) {
                    $subs[] = ['row', $s];
                }
            }
        }
    }

    private static function number(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        return is_string($v) && is_numeric($v) ? (float) $v : null;
    }
}
