<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

final class DatabaseSchema
{
    /** @var array<string, TableSchema> */
    private array $tables = [];

    /** @param list<TableSchema> $tables */
    public function __construct(array $tables)
    {
        foreach ($tables as $table) {
            $this->tables[$table->name] = $table;
        }
        ksort($this->tables, SORT_STRING);
    }

    /** @return list<string> sorted */
    public function names(): array
    {
        return array_keys($this->tables);
    }

    public function table(string $name): ?TableSchema
    {
        return $this->tables[$name] ?? null;
    }

    /** @return list<TableSchema> sorted by name */
    public function tables(): array
    {
        return array_values($this->tables);
    }
}
