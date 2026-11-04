<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker\Support;

/**
 * The statement types that `RowsExaminedMatchesServerTest` leaves out when it compares the app's "rows examined" with
 * the server's count, and the rule that decides, statement by statement, which type a statement is.
 *
 * Why these three: for each, the two numbers differ for a known reason, and the app's figure (the storage engine's
 * handler reads, which is what the database spends its CPU on) is the one that is kept. The server's per-statement
 * ROWS_EXAMINED is the optimizer's count of rows it handled, which leaves these reads out.
 *
 * The rule is MECHANICAL: it reads only what the statement says (its SQL text) and what the server's plan says for it
 * (EXPLAIN, taken at the moment the statement ran). Nobody decides per route, and no route name is used. A statement
 * that matches none of the rules stays in the comparison.
 */
final class ExcludedStatementTypes
{
    /**
     * A `count(*)` over a whole table, with no WHERE (and no join, group or limit): the server records 0 rows examined for
     * it, while the engine reads the whole index, in proportion to the table's row count.
     * Rule: the SQL text is exactly `select count(*) as aggregate from <table>`.
     */
    public const COUNT_ALL = 'count_all';

    /**
     * A read of a range of an index: after the last row of the range the engine reads once more to see the range has
     * ended, and that read is counted by the handler but not by the server.
     * Rule: the plan has a row whose access type is one that reads a range of a non-unique index (see RANGE_ACCESS).
     * Every table of the statement is taken as it is in the plan, so a statement with such a read in a subquery is
     * left out whole.
     */
    public const RANGE_END = 'range_end';

    /**
     * A look-up by a unique index that finds no row: the server records 0 rows examined, the engine makes one read
     * to find that there is none.
     * Rule: the plan says the look-up found nothing, which the server decides before the statement runs
     * ("no matching row in const table" / "Impossible WHERE noticed after reading const tables").
     */
    public const UNIQUE_MISS = 'unique_miss';

    public const ALL = [self::COUNT_ALL, self::RANGE_END, self::UNIQUE_MISS];

    /** The EXPLAIN access types of RANGE_END. A unique look-up (const, eq_ref, system) and a scan of a whole table or index (ALL, index) are not among them. */
    public const RANGE_ACCESS = ['ref', 'ref_or_null', 'range', 'index_merge'];

    /**
     * A fourth type, of another kind: the statement is NOT left out. A scan of a whole table or index (EXPLAIN access type
     * ALL or index) makes the engine read a few rows more than the server counts (the read that finds the end of the scan),
     * a small constant that does not depend on the table's size. For such a statement the handler's count is taken down by
     * that difference, and the statement stays in the comparison with the server's count.
     * Rule: the plan has a row whose access type is ALL or index, and the handler's count is MORE than the server's by
     * 1 to SCAN_END_MAX rows. A bigger difference is not taken off (so it stays in the comparison and can fail it).
     * Never applied to a statement of the three types above (those are left out whole).
     */
    public const SCAN_END = 'scan_end';

    /** The EXPLAIN access types of SCAN_END. */
    public const SCAN_ACCESS = ['ALL', 'index'];

    /** The most rows SCAN_END takes off one statement. Fixed by ruling; it is not widened after seeing results. */
    public const SCAN_END_MAX = 3;

    private const COUNT_ALL_SQL = '/^select count\(\*\) as `?aggregate`? from `?[A-Za-z0-9_$]+`?$/D';

    /**
     * @param array<int, array{type: ?string, extra: ?string}>|array{error: string}|null $plan the rows of EXPLAIN for a
     *                                                                                          select, or null for a statement
     *                                                                                          that is not a select (or has no plan)
     *
     * @return string|null one of self::ALL, or null when the statement is compared
     */
    public static function of(string $sql, ?array $plan): ?string
    {
        if (preg_match(self::COUNT_ALL_SQL, trim((string) preg_replace('/\s+/', ' ', $sql))) === 1) {
            return self::COUNT_ALL;
        }
        if ($plan === null || isset($plan['error'])) {
            return null;
        }
        foreach ($plan as $row) {
            $extra = (string) ($row['extra'] ?? '');
            if (str_contains($extra, 'no matching row in const table') || str_contains($extra, 'Impossible WHERE noticed after reading const tables')) {
                return self::UNIQUE_MISS;
            }
        }
        foreach ($plan as $row) {
            if (in_array($row['type'] ?? null, self::RANGE_ACCESS, true)) {
                return self::RANGE_END;
            }
        }

        return null;
    }

    /**
     * The rows the SCAN_END rule takes off the handler's count of one statement that is compared (0 when it does not apply).
     *
     * @param array<int, array{type: ?string, extra: ?string}>|array{error: string}|null $plan
     */
    public static function scanEndSurplus(string $sql, ?array $plan, int $handlerReads, int $serverRows): int
    {
        if (self::of($sql, $plan) !== null || $plan === null || isset($plan['error'])) {
            return 0;
        }
        $surplus = $handlerReads - $serverRows;
        if ($surplus < 1 || $surplus > self::SCAN_END_MAX) {
            return 0;
        }
        foreach ($plan as $row) {
            if (in_array($row['type'] ?? null, self::SCAN_ACCESS, true)) {
                return $surplus;
            }
        }

        return 0;
    }

    /**
     * True when the handler's count of a compared scan exceeds the server's by more than SCAN_END_MAX (not taken off).
     *
     * @param array<int, array{type: ?string, extra: ?string}>|array{error: string}|null $plan
     */
    public static function scanEndOverCap(string $sql, ?array $plan, int $handlerReads, int $serverRows): bool
    {
        if (self::of($sql, $plan) !== null || $plan === null || isset($plan['error'])) {
            return false;
        }
        if ($handlerReads - $serverRows <= self::SCAN_END_MAX) {
            return false;
        }
        foreach ($plan as $row) {
            if (in_array($row['type'] ?? null, self::SCAN_ACCESS, true)) {
                return true;
            }
        }

        return false;
    }
}
