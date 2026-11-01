<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Guard;

/**
 * Safety condition 6: a route that writes is rolled back after every request. What cannot be rolled back is not
 * measured; it is recorded with a reason. Pure functions over SQL text and counts.
 *
 * Reason codes (the third word of `not_rollbackable(...)` in the run report):
 *   ddl                  ALTER / CREATE / DROP / TRUNCATE / RENAME (temporary tables excepted)
 *   lock                 LOCK TABLES / UNLOCK TABLES
 *   transaction_control  BEGIN / START TRANSACTION / COMMIT / SET autocommit (an implicit commit)
 *   privilege            GRANT / REVOKE / CREATE USER ... and FLUSH
 *   maintenance          ANALYZE / OPTIMIZE / REPAIR TABLE
 *   load_data            LOAD DATA
 *   call                 CALL (a stored procedure may commit)
 *   other_connection     a write on a connection other than the default one
 *   row_count_changed    a table has a different row count after the rollback
 */
final class RollbackGuard
{
    /**
     * The first reason found in a SQL text (it may hold several statements), or null when every statement is safe.
     */
    public static function implicitCommitReason(string $sql): ?string
    {
        foreach (self::statements($sql) as $statement) {
            $reason = self::reasonForStatement($statement);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    public static function isWrite(string $sql): bool
    {
        foreach (self::statements($sql) as $statement) {
            if (preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $statement) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A write on a connection that the rollback does not cover.
     */
    public static function otherConnectionWrite(string $connection, string $defaultConnection, string $sql): bool
    {
        return $connection !== $defaultConnection && self::isWrite($sql);
    }

    /**
     * Tables whose row count differs between two snapshots (including a table that appeared or vanished).
     *
     * @param array<string, int> $before
     * @param array<string, int> $after
     *
     * @return list<string>
     */
    public static function changedTables(array $before, array $after): array
    {
        $changed = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $table) {
            if (($before[$table] ?? null) !== ($after[$table] ?? null)) {
                $changed[] = (string) $table;
            }
        }
        sort($changed);

        return $changed;
    }

    /**
     * After a request inside one outer transaction the level must be at least 1; 0 means something committed.
     */
    public static function transactionStillOpen(int $level): bool
    {
        return $level >= 1;
    }

    private static function reasonForStatement(string $statement): ?string
    {
        $rules = [
            // Temporary tables do not commit implicitly: test them first.
            ['/^(CREATE|DROP)\s+TEMPORARY\s+TABLE\b/i', null],
            ['/^(ALTER|CREATE|DROP|TRUNCATE|RENAME)\b/i', 'ddl'],
            ['/^(UNLOCK\s+TABLES|LOCK\s+TABLES|LOCK\s+INSTANCE)\b/i', 'lock'],
            ['/^(START\s+TRANSACTION|BEGIN|COMMIT|END)\b/i', 'transaction_control'],
            ['/^SET\s+(@@(SESSION\.|GLOBAL\.)?|SESSION\s+|GLOBAL\s+)?autocommit\b/i', 'transaction_control'],
            ['/^(GRANT|REVOKE|FLUSH|SET\s+PASSWORD)\b/i', 'privilege'],
            ['/^(ANALYZE|OPTIMIZE|REPAIR|CHECK)\s+(NO_WRITE_TO_BINLOG\s+|LOCAL\s+)?TABLE\b/i', 'maintenance'],
            ['/^LOAD\s+DATA\b/i', 'load_data'],
            ['/^CALL\b/i', 'call'],
        ];
        foreach ($rules as [$pattern, $reason]) {
            if (preg_match($pattern, $statement) === 1) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * Statements of a SQL text, each without leading whitespace and comments. The split on `;` is naive on purpose:
     * a `;` inside a string can only add a harmless extra look, and a missed statement is the worse mistake.
     *
     * @return list<string>
     */
    private static function statements(string $sql): array
    {
        $out = [];
        foreach (explode(';', $sql) as $part) {
            $part = self::stripLeadingNoise($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    private static function stripLeadingNoise(string $text): string
    {
        $previous = null;
        while ($previous !== $text) {
            $previous = $text;
            $text = ltrim($text, " \t\r\n(");
            $text = (string) preg_replace('/^\/\*.*?\*\//s', '', $text);
            $text = (string) preg_replace('/^(--[ \t][^\n]*|#[^\n]*)(\n|$)/', '', $text);
        }

        return $text;
    }
}
