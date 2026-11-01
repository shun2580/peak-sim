<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Route;

/**
 * What the argument and body builders need to know about the rows of the measurement database. The implementation
 * (W2's booter) reads only rows the tool itself generated. Everything is deterministic: "first" is the lowest primary
 * key, whatever the scale.
 */
interface RowLookup
{
    /** The table of a model class, or null when the class is not a known model. */
    public function tableForModel(string $modelClass): ?string;

    /** The column the model is bound by in a route (`getRouteKeyName()`). */
    public function routeKeyColumn(string $modelClass): string;

    public function hasTable(string $table): bool;

    public function primaryKey(string $table): ?string;

    /**
     * The value of `$column` in the first row (lowest primary key) of `$table` that matches `$where`
     * (column => value, all equal), or null when there is no such row.
     *
     * @param array<string, string|int> $where
     */
    public function firstValue(string $table, string $column, array $where = []): string|int|null;
}
