<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

final class TableSchema
{
    /** @var array<string, ColumnSchema> */
    private array $byName = [];

    /** @var array<string, true> */
    private array $uniqueColumns = [];

    /**
     * @param list<ColumnSchema>     $columns       in table order
     * @param list<string>           $primaryKey
     * @param list<list<string>>     $uniqueIndexes unique indexes other than the primary key
     * @param list<ForeignKey>       $foreignKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly array $primaryKey = [],
        public readonly array $uniqueIndexes = [],
        public readonly array $foreignKeys = [],
    ) {
        foreach ($columns as $column) {
            $this->byName[$column->name] = $column;
        }
        foreach ([$primaryKey, ...$uniqueIndexes] as $index) {
            foreach ($index as $name) {
                $this->uniqueColumns[$name] = true;
            }
        }
    }

    public function column(string $name): ?ColumnSchema
    {
        return $this->byName[$name] ?? null;
    }

    /** True when the column is part of the primary key or of a unique index. */
    public function isUniqueColumn(string $name): bool
    {
        return isset($this->uniqueColumns[$name]);
    }

    /** The foreign key that holds this column, if any. */
    public function foreignKeyOf(string $column): ?ForeignKey
    {
        foreach ($this->foreignKeys as $fk) {
            if (in_array($column, $fk->columns, true)) {
                return $fk;
            }
        }

        return null;
    }

    /** The only primary key column, when it is a single integer column; otherwise null. */
    public function singleIntegerPrimaryKey(): ?ColumnSchema
    {
        if (count($this->primaryKey) !== 1) {
            return null;
        }
        $column = $this->column($this->primaryKey[0]);
        if ($column === null || !in_array($column->dataType, ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true)) {
            return null;
        }

        return $column;
    }
}
