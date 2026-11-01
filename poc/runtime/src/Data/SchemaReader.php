<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

use PDO;

/**
 * Reads the STRUCTURE of the current database from information_schema: tables, columns (type, length, NULL,
 * default, ENUM members, generated), primary and unique keys, foreign keys. It never reads table contents
 * (D5 stays as it is; reading the synthetic rows the tool wrote itself is D44, and this class does not even do
 * that).
 */
final class SchemaReader
{
    public static function read(PDO $pdo): DatabaseSchema
    {
        $schema = 'information_schema';
        $tables = self::rows($pdo, "SELECT TABLE_NAME AS table_name FROM $schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME");
        $columns = self::rows($pdo, "SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, ORDINAL_POSITION AS position,
            DATA_TYPE AS data_type, COLUMN_TYPE AS column_type, CHARACTER_MAXIMUM_LENGTH AS char_length,
            NUMERIC_PRECISION AS num_precision, NUMERIC_SCALE AS num_scale, IS_NULLABLE AS is_nullable,
            COLUMN_DEFAULT AS column_default, EXTRA AS extra
            FROM $schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $keys = self::rows($pdo, "SELECT TABLE_NAME AS table_name, CONSTRAINT_NAME AS constraint_name, COLUMN_NAME AS column_name,
            ORDINAL_POSITION AS position, REFERENCED_TABLE_NAME AS ref_table, REFERENCED_COLUMN_NAME AS ref_column
            FROM $schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION");
        $stats = self::rows($pdo, "SELECT TABLE_NAME AS table_name, INDEX_NAME AS index_name, NON_UNIQUE AS non_unique,
            SEQ_IN_INDEX AS seq, COLUMN_NAME AS column_name
            FROM $schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX");

        return self::fromRows($tables, $columns, $keys, $stats);
    }

    /**
     * @param list<array<string, mixed>> $tables  [table_name]
     * @param list<array<string, mixed>> $columns [table_name, column_name, position, data_type, column_type, char_length,
     *                                            num_precision, num_scale, is_nullable, column_default, extra]
     * @param list<array<string, mixed>> $keys    [table_name, constraint_name, column_name, position, ref_table, ref_column]
     * @param list<array<string, mixed>> $stats   [table_name, index_name, non_unique, seq, column_name]
     */
    public static function fromRows(array $tables, array $columns, array $keys, array $stats): DatabaseSchema
    {
        $names = [];
        foreach ($tables as $row) {
            $names[(string) $row['table_name']] = true;
        }

        $columnsByTable = [];
        foreach ($columns as $row) {
            if (isset($names[(string) $row['table_name']])) {
                $columnsByTable[(string) $row['table_name']][] = $row;
            }
        }

        $primary = [];
        $unique = [];
        foreach (self::grouped($stats, 'index_name') as $index) {
            $table = (string) $index[0]['table_name'];
            usort($index, static fn (array $a, array $b): int => (int) $a['seq'] <=> (int) $b['seq']);
            $cols = array_column($index, 'column_name');
            if (in_array(null, $cols, true) || (int) $index[0]['non_unique'] !== 0) {
                continue; // functional index (no plain column) or not unique
            }
            if ($index[0]['index_name'] === 'PRIMARY') {
                $primary[$table] = array_map('strval', $cols);
            } else {
                $unique[$table][] = array_map('strval', $cols);
            }
        }

        $foreign = [];
        foreach (self::grouped($keys, 'constraint_name') as $constraint) {
            usort($constraint, static fn (array $a, array $b): int => (int) $a['position'] <=> (int) $b['position']);
            $table = (string) $constraint[0]['table_name'];
            $foreign[$table][] = new ForeignKey(
                (string) $constraint[0]['constraint_name'],
                array_map('strval', array_column($constraint, 'column_name')),
                (string) $constraint[0]['ref_table'],
                array_map('strval', array_column($constraint, 'ref_column')),
            );
        }

        $built = [];
        foreach (array_keys($names) as $name) {
            $rows = $columnsByTable[$name] ?? [];
            usort($rows, static fn (array $a, array $b): int => (int) $a['position'] <=> (int) $b['position']);
            $built[] = new TableSchema(
                $name,
                array_map(self::column(...), $rows),
                $primary[$name] ?? [],
                $unique[$name] ?? [],
                $foreign[$name] ?? [],
            );
        }

        return new DatabaseSchema($built);
    }

    /** @param array<string, mixed> $row */
    private static function column(array $row): ColumnSchema
    {
        $columnType = (string) $row['column_type'];
        $extra = strtolower((string) ($row['extra'] ?? ''));
        $nullable = strtoupper((string) $row['is_nullable']) === 'YES';
        $default = $row['column_default'] === null ? null : (string) $row['column_default'];
        $dataType = strtolower((string) $row['data_type']);

        return new ColumnSchema(
            (string) $row['column_name'],
            $dataType,
            $columnType,
            self::nullableInt($row['char_length'] ?? null),
            self::nullableInt($row['num_precision'] ?? null),
            self::nullableInt($row['num_scale'] ?? null),
            $nullable,
            $default,
            $default !== null || $nullable,
            str_contains($extra, 'auto_increment'),
            str_contains($extra, 'virtual generated') || str_contains($extra, 'stored generated'),
            str_contains(strtolower($columnType), 'unsigned'),
            in_array($dataType, ['enum', 'set'], true) ? self::members($columnType) : [],
        );
    }

    /** @return list<string> */
    private static function members(string $columnType): array
    {
        if (preg_match_all("/'((?:[^']|'')*)'/", $columnType, $m) === 0) {
            return [];
        }

        return array_map(static fn (string $v): string => str_replace("''", "'", $v), $m[1]);
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<list<array<string, mixed>>> rows grouped by (table_name, $key)
     */
    private static function grouped(array $rows, string $key): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['table_name'] . "\0" . $row[$key]][] = $row;
        }

        return array_values($groups);
    }

    /** @return list<array<string, mixed>> */
    private static function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);

        return $statement === false ? [] : $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
