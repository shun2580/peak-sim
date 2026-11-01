<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

/**
 * The value of one cell, as a pure function of (table, column, row number): the same input always gives the
 * same value, so a child row can compute the value its parent row holds without reading anything back, and a
 * run can be repeated exactly. No randomness, no clock.
 *
 * The rules are the table of design 1.2.3. Where a column is unique (primary key or unique index) the row
 * number is always part of the value.
 */
final class ValueFactory
{
    /** Leave the column out of the INSERT (the database fills it). */
    public const OMIT = 'omit';

    /** Write value() for this column. */
    public const VALUE = 'value';

    /** The column is required but its type is one the generator cannot fill. */
    public const UNSUPPORTED = 'unsupported';

    /** 2026-01-01 00:00:00 UTC. */
    private const BASE_TS = 1767225600;

    /** Days a date column cycles through (keeps TIMESTAMP columns below 2038). */
    private const DATE_CYCLE_DAYS = 3000;

    private const INT_BITS = [
        'tinyint' => 8,
        'smallint' => 16,
        'mediumint' => 24,
        'int' => 32,
        'integer' => 32,
        'bigint' => 64,
    ];

    private const UNSUPPORTED_TYPES = [
        'geometry', 'point', 'linestring', 'polygon', 'multipoint', 'multilinestring', 'multipolygon',
        'geometrycollection', 'bit',
    ];

    /** Enum members that would hide the row from the code under test (design question Q7). */
    private const HIDING_ENUM_VALUE = '/deleted|inactive|banned|archived|disabled/i';

    /** Boolean columns that grant privilege or hide a row stay 0. */
    private const FALSE_FLAG = '/admin|(^|_)(super\w*|deleted|archived|banned|disabled|inactive|blocked|suspended)(_|$)/i';

    /** Should the column be written, and can it be? */
    public function usage(TableSchema $table, ColumnSchema $column): string
    {
        if ($column->generated || $column->autoIncrement) {
            return self::OMIT;
        }

        $unique = $table->isUniqueColumn($column->name);
        $foreign = $table->foreignKeyOf($column->name) !== null;
        $supported = $this->isSupported($column);

        if (!$supported) {
            return $column->nullable || $column->hasDefault ? self::OMIT : self::UNSUPPORTED;
        }
        if ($unique || $foreign) {
            return self::VALUE;
        }
        if ($column->hasDefault && (!$column->nullable || $column->default !== null)) {
            return self::OMIT; // the database's own default is the intended value
        }

        return self::VALUE;
    }

    public function isSupported(ColumnSchema $column): bool
    {
        $type = $column->dataType;
        if (in_array($type, self::UNSUPPORTED_TYPES, true)) {
            return false;
        }

        return isset(self::INT_BITS[$type]) || in_array($type, [
            'decimal', 'numeric', 'float', 'double', 'real', 'year', 'date', 'datetime', 'timestamp', 'time',
            'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set', 'json',
            'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob',
        ], true);
    }

    /**
     * @param int $i 1-based row number
     *
     * @return string|int|float|null
     */
    public function value(TableSchema $table, ColumnSchema $column, int $i): string|int|float|null
    {
        $unique = $table->isUniqueColumn($column->name);
        $type = $column->dataType;

        $byName = $this->byName($column, $i);
        if ($byName !== false) {
            return $byName;
        }

        if (isset(self::INT_BITS[$type])) {
            return $this->integer($column, $i);
        }

        return match ($type) {
            'decimal', 'numeric' => $this->decimal($column, $i, $unique),
            'float', 'double', 'real' => sprintf('%.1f', ($unique ? $i : $i % 100) + 0.5) + 0.0,
            'year' => 2000 + (($i - 1) % 100),
            'date' => gmdate('Y-m-d', self::BASE_TS + $this->dayOffset($i) * 86400),
            'datetime', 'timestamp' => $unique
                ? gmdate('Y-m-d H:i:s', self::BASE_TS + ($i - 1))
                : gmdate('Y-m-d H:i:s', self::BASE_TS + $this->dayOffset($i) * 86400),
            'time' => gmdate('H:i:s', ($i - 1) % 86400),
            'enum', 'set' => $this->enumeration($column, $i),
            'json' => '{}',
            'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob' => $this->fitted('peak-sim-blob', $i, $this->byteLimit($column)),
            default => $this->text($table, $column, $i),
        };
    }

    /**
     * Name based rules that win over the type rule. false means "no rule for this name".
     *
     * @return string|int|null|false
     */
    private function byName(ColumnSchema $column, int $i): string|int|null|false
    {
        $type = $column->dataType;
        $name = strtolower($column->name);
        $isTime = in_array($type, ['datetime', 'timestamp', 'date'], true);

        if ($isTime && ($name === 'created_at' || $name === 'updated_at')) {
            return gmdate('Y-m-d H:i:s', self::BASE_TS);
        }
        if ($isTime && $name === 'deleted_at') {
            return $column->nullable ? null : gmdate('Y-m-d H:i:s', self::BASE_TS);
        }
        if ($type === 'tinyint' && str_starts_with(strtolower($column->columnType), 'tinyint(1)')) {
            return preg_match(self::FALSE_FLAG, $column->name) === 1 ? 0 : $i % 2;
        }

        return false;
    }

    private function integer(ColumnSchema $column, int $i): int
    {
        $bits = self::INT_BITS[$column->dataType];
        if ($bits === 64) {
            return $i;
        }
        $max = $column->unsigned ? (1 << $bits) - 1 : (1 << ($bits - 1)) - 1;

        return (($i - 1) % $max) + 1;
    }

    private function decimal(ColumnSchema $column, int $i, bool $unique): string
    {
        $precision = $column->precision ?? 10;
        $scale = $column->scale ?? 0;
        $intDigits = max(0, $precision - $scale);
        $maxInt = $intDigits === 0 ? 0 : (int) min(10 ** $intDigits - 1, PHP_INT_MAX);
        $whole = $unique ? ($i - 1) % ($maxInt + 1) : min($i % 100, $maxInt);
        $fraction = $scale === 0 ? '' : '.' . '5' . str_repeat('0', $scale - 1);

        return $whole . $fraction;
    }

    private function dayOffset(int $i): int
    {
        return ($i - 1) % self::DATE_CYCLE_DAYS;
    }

    private function enumeration(ColumnSchema $column, int $i): ?string
    {
        $values = $column->enumValues;
        if ($values === []) {
            return null;
        }
        $visible = array_values(array_filter($values, static fn (string $v): bool => preg_match(self::HIDING_ENUM_VALUE, $v) !== 1));
        if ($visible === []) {
            $visible = $values;
        }

        return $visible[($i - 1) % count($visible)];
    }

    private function text(TableSchema $table, ColumnSchema $column, int $i): string
    {
        $limit = $column->length;
        $tableName = $this->safe($table->name);
        $columnName = $this->safe($column->name);

        if (in_array($column->dataType, ['char', 'varchar'], true) && $limit === 36) {
            return sprintf('%08x-0000-4000-8000-%012x', crc32($table->name . '.' . $column->name), $i);
        }
        if (preg_match('/(^|_)e?mail($|_)/i', $column->name) === 1 && !str_ends_with(strtolower($column->name), '_at')) {
            $address = strtolower($tableName) . $i . '@example.test';
            if ($limit === null || strlen($address) <= $limit) {
                return $address;
            }
        }

        return $this->fitted($tableName . '_' . $columnName, $i, $limit);
    }

    /**
     * "{prefix}_{i}" cut to $limit characters; the row number survives the cut so a unique column stays unique.
     * When even "_{i}" does not fit, the last digits of i are kept.
     */
    private function fitted(string $prefix, int $i, ?int $limit): string
    {
        $full = $prefix . '_' . $i;
        if ($limit === null || strlen($full) <= $limit) {
            return $full;
        }
        $suffix = '_' . $i;
        if (strlen($suffix) <= $limit) {
            return substr($prefix, 0, $limit - strlen($suffix)) . $suffix;
        }

        return substr((string) $i, -max(1, $limit));
    }

    private function byteLimit(ColumnSchema $column): ?int
    {
        return match ($column->dataType) {
            'tinyblob' => 255,
            default => $column->length,
        };
    }

    private function safe(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $name) ?? $name;
    }
}
