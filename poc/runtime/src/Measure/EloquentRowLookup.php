<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Measure;

use PeakSim\Runtime\Route\RowLookup;

/**
 * Answers the argument and body builders from the booted app and its measurement database. It reads only rows the
 * tool put there (the seeder's or the generator's), and structure from information_schema. "First" is the lowest
 * primary key, whatever the scale.
 */
final class EloquentRowLookup implements RowLookup
{
    private const MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    /** @param object $app Illuminate\Foundation\Application */
    public function __construct(private readonly object $app)
    {
    }

    public function tableForModel(string $modelClass): ?string
    {
        if (!is_subclass_of($modelClass, self::MODEL)) {
            return null;
        }

        return (new $modelClass())->getTable();
    }

    public function routeKeyColumn(string $modelClass): string
    {
        return is_subclass_of($modelClass, self::MODEL) ? (new $modelClass())->getRouteKeyName() : 'id';
    }

    public function hasTable(string $table): bool
    {
        return $this->app['db']->connection()->getSchemaBuilder()->hasTable($table);
    }

    public function primaryKey(string $table): ?string
    {
        $row = $this->app['db']->connection()->selectOne(
            "select column_name as c from information_schema.key_column_usage where table_schema = database() and table_name = ? and constraint_name = 'PRIMARY' order by ordinal_position limit 1",
            [$table],
        );

        return $row === null ? null : (string) $row->c;
    }

    public function firstValue(string $table, string $column, array $where = []): string|int|null
    {
        if (!$this->hasTable($table)) {
            return null;
        }
        $query = $this->app['db']->connection()->table($table);
        foreach ($where as $name => $value) {
            $query->where($name, '=', $value);
        }
        $key = $this->primaryKey($table);
        if ($key !== null) {
            $query->orderBy($key);
        }
        $value = $query->value($column);

        return is_int($value) || is_string($value) ? $value : ($value === null ? null : (string) $value);
    }
}
