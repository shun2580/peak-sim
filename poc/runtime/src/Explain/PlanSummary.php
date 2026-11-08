<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

/**
 * What a plan (the server's EXPLAIN FORMAT=JSON, decoded) says in the few fields the comparison needs: for every table
 * the plan reads, in the order the plan names them, the access type, the key used, the rows the server expects to read
 * for one scan (`rows_examined_per_scan`), the rows it expects to produce (`rows_produced_per_join`) and `filtered`.
 *
 * The plan's own words are kept: a table the server says nothing about (an INSERT's target) has null where a field is
 * absent, never a made-up value. The one change: the access type `ALL` the server writes for an INSERT's target is left out
 * (null), since the target is written, not scanned. The raw JSON is written next to the summary, so nothing is lost by summarising.
 *
 * `signature()` is what is compared to decide whether two plans are the same plan: the tables in order, each with its
 * access type and key. A row estimate that moved without the type or the key moving is the same plan.
 */
final class PlanSummary
{
    /**
     * @param array<string, mixed> $plan
     *
     * @return list<array{table: ?string, access_type: ?string, key: ?string, used_key_parts: ?list<string>, rows_examined_per_scan: int|float|null, rows_produced_per_join: int|float|null, filtered: float|null, insert: bool}>
     */
    public static function tables(array $plan): array
    {
        $found = [];
        self::walk($plan, $found);

        return $found;
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return array{tables: list<array<string, mixed>>, signature: string, scan_rows_sum: int|float|null}
     */
    public static function summarize(array $plan): array
    {
        $tables = self::tables($plan);
        $rows = array_map(static fn (array $t): int|float|null => $t['rows_examined_per_scan'], $tables);
        $known = array_filter($rows, static fn (mixed $r): bool => $r !== null);

        return [
            'tables' => $tables,
            'signature' => self::signature($tables),
            // Added up only to put states side by side; it is not an estimate of the rows one run reads (that is for the
            // candidates written down before the comparison to choose from).
            'scan_rows_sum' => $known === [] ? null : array_sum($known),
        ];
    }

    /** @param list<array<string, mixed>> $tables */
    public static function signature(array $tables): string
    {
        return implode(';', array_map(
            static fn (array $t): string => ($t['table'] ?? '?') . '|' . ($t['access_type'] ?? '-') . '|' . ($t['key'] ?? '-'),
            $tables,
        ));
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<array<string, mixed>> $found
     */
    private static function walk(array $node, array &$found): void
    {
        foreach ($node as $name => $value) {
            if (!is_array($value)) {
                continue;
            }
            if ($name === 'table' && isset($value['table_name']) && is_string($value['table_name'])) {
                $found[] = self::tableEntry($value);
                // a derived table (`materialized_from_subquery`) holds more tables
                self::walk($value, $found);
                continue;
            }
            self::walk($value, $found);
        }
    }

    /**
     * @param array<string, mixed> $t
     *
     * @return array{table: ?string, access_type: ?string, key: ?string, used_key_parts: ?list<string>, rows_examined_per_scan: int|float|null, rows_produced_per_join: int|float|null, filtered: float|null, insert: bool}
     */
    private static function tableEntry(array $t): array
    {
        $parts = $t['used_key_parts'] ?? null;
        $insert = ($t['insert'] ?? false) === true;

        return [
            'table' => (string) $t['table_name'],
            // the server writes `ALL` for the target of an INSERT, which reads nothing: that is not an access type of a read
            'access_type' => !$insert && isset($t['access_type']) ? (string) $t['access_type'] : null,
            'key' => isset($t['key']) ? (string) $t['key'] : null,
            'used_key_parts' => is_array($parts) ? array_values(array_map('strval', $parts)) : null,
            'rows_examined_per_scan' => self::number($t['rows_examined_per_scan'] ?? null),
            'rows_produced_per_join' => self::number($t['rows_produced_per_join'] ?? null),
            'filtered' => isset($t['filtered']) && is_numeric($t['filtered']) ? (float) $t['filtered'] : null,
            'insert' => $insert,
        ];
    }

    private static function number(mixed $v): int|float|null
    {
        if (is_int($v) || is_float($v)) {
            return $v;
        }

        return is_string($v) && is_numeric($v) ? $v + 0 : null;
    }
}
