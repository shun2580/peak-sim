<?php

declare(strict_types=1);

namespace PeakSim\Runtime\LabEntry;

use PeakSim\Runtime\Guard\RollbackGuard;

/**
 * The write rule of the lab's entry script (poc/lab/README.md, safety condition 6): a request is not wrapped in a
 * transaction there, so the only thing it may write is the page view counter, the `views` table, with the two
 * statements the app uses for it (an insert and an update). Anything else that writes, commits, locks tables, changes
 * the server or writes a file is a violation. Pure functions over SQL text.
 *
 * It never lets a statement through because it could not read it: a write that is not exactly one of the two allowed
 * forms is a violation (a multi-table update, an alias, a delete, a replace, a write inside a `WITH`). So is a statement
 * with an executable comment (one that opens with `/*!`, with or without a version number; MySQL runs what is inside),
 * anywhere in it: it is refused, not stripped and read, because the server would run text this class cannot see as a statement.
 * poc/runtime does not use this class: its measurement keeps the rollback.
 */
final class ViewsOnlyWrites
{
    public const TABLE = 'views';

    /** @see AllowedWrites::DEFAULT */
    private const DEFAULT_TABLES = AllowedWrites::DEFAULT;

    private const KEEP = 200;

    /**
     * Why this statement text (it may hold several statements) is not allowed on the lab's entry, or null.
     *
     * $allowedTables are the tables that may be written (AllowedWrites); `views` alone when none are given, which is the BookStack lab.
     *
     * @param list<string> $allowedTables
     */
    public static function violation(string $connection, string $defaultConnection, string $measureDb, string $sql, array $allowedTables = self::DEFAULT_TABLES): ?string
    {
        if (preg_match('/\/\*M?!/', $sql) === 1) {
            return 'executable_comment: ' . self::cut($sql);
        }
        if ($connection !== $defaultConnection && RollbackGuard::isWrite($sql)) {
            return 'other_connection: ' . $connection;
        }
        $reason = RollbackGuard::implicitCommitReason($sql);
        if ($reason !== null) {
            return $reason . ': ' . self::cut($sql);
        }
        foreach (self::statements($sql) as $statement) {
            $found = self::statementViolation($statement, $measureDb, $allowedTables);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** @param list<string> $allowedTables */
    private static function statementViolation(string $statement, string $measureDb, array $allowedTables): ?string
    {
        if (preg_match('/^SET\s+(?:GLOBAL|PERSIST|PERSIST_ONLY)\b/i', $statement) === 1
            || preg_match('/^SET\s+@@(?:GLOBAL|PERSIST|PERSIST_ONLY)\./i', $statement) === 1) {
            return 'global_setting: ' . self::cut($statement);
        }
        if (preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $statement) === 1) {
            return 'file_write: ' . self::cut($statement);
        }
        $writes = preg_match('/^(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $statement) === 1
            || (preg_match('/^WITH\b/i', $statement) === 1 && preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $statement) === 1);
        if (!$writes || self::isAllowedWrite($statement, $measureDb, $allowedTables)) {
            return null;
        }

        return ($allowedTables === self::DEFAULT_TABLES ? 'write_outside_views: ' : 'write_outside_allowed_tables: ') . self::cut($statement);
    }

    /**
     * The table name is case sensitive (as on the lab's server); the keywords are not. Only the insert and the update of an allowed table
     * pass, whatever the table: a delete, a replace, an alias or a statement that writes two tables is refused for every one of them.
     *
     * @param list<string> $allowedTables
     */
    private static function isAllowedWrite(string $statement, string $measureDb, array $allowedTables): bool
    {
        if ($allowedTables === []) {
            return false;
        }
        $names = implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $allowedTables));
        $table = '(?:`?' . preg_quote($measureDb, '/') . '`?\s*\.\s*)?`?(?:' . $names . ')`?';
        $insert = '/^(?i:INSERT\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*(?:INTO\s+)?)' . $table . '(?=[\s(]|$)/';
        $update = '/^(?i:UPDATE\s+(?:(?:LOW_PRIORITY|IGNORE)\s+)*)' . $table . '(?i:\s+SET\b)/';

        return preg_match($insert, $statement) === 1 || preg_match($update, $statement) === 1;
    }

    /**
     * Statements of a SQL text, each without leading whitespace, parentheses and comments. The split on `;` is naive
     * on purpose: a `;` inside a string can only add a harmless extra look, and a missed statement is the worse mistake.
     *
     * @return list<string>
     */
    private static function statements(string $sql): array
    {
        $out = [];
        foreach (explode(';', $sql) as $part) {
            $part = self::stripLeadingNoise($part);
            if ($part !== '') {
                $out[] = (string) preg_replace('/\s+/', ' ', $part);
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

    private static function cut(string $text): string
    {
        return mb_substr((string) preg_replace('/\s+/', ' ', $text), 0, self::KEEP);
    }
}
