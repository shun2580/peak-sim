<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

use InvalidArgumentException;
use PDO;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Guard\DbGuard;
use Throwable;

/**
 * Fills a measurement database with synthetic rows from its own schema (design 1.2). It reads structure only,
 * writes only INSERT (and, for broken foreign key cycles, UPDATE of the rows it inserted itself), and refuses to
 * start unless the selected database is a measurement database.
 *
 * Per table: one transaction. Any refusal by the database rolls the table back and is recorded with a Reason;
 * tables that depend on a failed table are recorded as parent_not_generated. Nothing is hidden and nothing
 * is retried with a cleverer value: a table that cannot be generated is a result.
 */
final class Generator implements DataGenerator
{
    /** Bookkeeping tables of the framework: never filled (they are not the app's data). */
    public const FRAMEWORK_TABLES = [
        'migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions',
        'password_reset_tokens', 'password_resets',
    ];

    private const MAX_BATCH_ROWS = 1000;

    /** Stay below the 65 535 placeholder ceiling of a prepared statement. */
    private const MAX_BATCH_VALUES = 60000;

    public function __construct(private readonly ValueFactory $values = new ValueFactory())
    {
    }

    public function generate(PDO $pdo, ScalePlan $plan, array $profile): GenerationReport
    {
        $sink = new PdoSink($pdo);
        $this->assertMeasurementDatabase($sink);

        return $this->generateInto(SchemaReader::read($pdo), $sink, $plan, $profile);
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @throws MeasureRefused
     */
    public function generateInto(DatabaseSchema $schema, RowSink $sink, ScalePlan $plan, array $profile = []): GenerationReport
    {
        $this->assertMeasurementDatabase($sink);

        $skip = array_flip($this->stringList($profile['skip'] ?? [], 'skip'));
        $rows = $plan->resolve($schema);
        foreach ($this->rowOverrides($profile['rows'] ?? []) as $table => $count) {
            if (isset($rows[$table])) {
                $rows[$table] = $count;
            }
        }
        $constants = is_array($profile['values'] ?? null) ? $profile['values'] : [];

        /** @var array<string, array{status: string, planned: int, actual: int, reason: ?string, detail: ?string, notes: list<string>, existing: int}> $state */
        $state = [];
        $candidates = [];
        foreach ($schema->names() as $name) {
            if (in_array($name, self::FRAMEWORK_TABLES, true)) {
                $state[$name] = $this->state(TableOutcome::SKIPPED, 0, 0, Reason::FRAMEWORK_TABLE, 'framework bookkeeping table');
            } elseif (isset($skip[$name])) {
                $state[$name] = $this->state(TableOutcome::SKIPPED, 0, 0, Reason::PROFILE_SKIP, 'skipped by the profile');
            } else {
                $unsupported = $this->unsupportedColumns($schema->table($name), $constants[$name] ?? []);
                if ($unsupported !== []) {
                    $state[$name] = $this->failed($rows[$name], Reason::UNSUPPORTED_TYPE, 'required column of a type that cannot be generated: ' . implode(', ', $unsupported));
                } else {
                    $candidates[] = $name;
                }
            }
        }

        $order = FkOrder::sort($schema, $candidates);
        foreach ($order->cyclic as $name) {
            $state[$name] = $this->failed($rows[$name], Reason::FK_CYCLE, 'foreign keys form a cycle with no nullable key to break it');
        }
        foreach ($order->blocked as $name) {
            $state[$name] = $this->failed($rows[$name], Reason::PARENT_NOT_GENERATED, 'depends on a table inside a foreign key cycle');
        }
        $deferred = [];
        foreach ($order->deferred as $edge) {
            $deferred[$edge['table']][$edge['fk']->name] = $edge['fk'];
        }

        $existing = [];
        foreach ($order->order as $name) {
            $existing[$name] = $sink->count($name);
            $state[$name] = $this->generateTable($schema, $schema->table($name), $sink, $rows, $existing, $state, $deferred[$name] ?? [], $constants);
        }

        foreach ($order->deferred as $edge) {
            $this->fillDeferred($schema, $edge['table'], $edge['fk'], $sink, $rows, $existing, $state);
        }

        $outcomes = [];
        foreach ($schema->names() as $name) {
            $s = $state[$name];
            $outcomes[] = new TableOutcome($name, $s['status'], $s['planned'], $s['actual'], $s['reason'], $s['detail'], $s['notes'], $s['existing']);
        }

        return new GenerationReport($plan->scope(), $outcomes);
    }

    /**
     * @param array<string, int>                                $rows
     * @param array<string, int>                                $existing rows each processed table held before generation
     * @param array<string, array<string, mixed>>               $state
     * @param array<string, ForeignKey>                         $deferred
     * @param array<string, mixed>                              $constants
     *
     * @return array{status: string, planned: int, actual: int, reason: ?string, detail: ?string, notes: list<string>, existing: int}
     */
    private function generateTable(DatabaseSchema $schema, TableSchema $table, RowSink $sink, array $rows, array $existing, array $state, array $deferred, array $constants): array
    {
        $planned = $rows[$table->name];
        $before = $existing[$table->name];
        $notes = $before > 0 ? ['existing_rows:' . $before] : [];
        $nullFks = []; // fk name => true: the key is inserted as NULL

        foreach ($table->foreignKeys as $fk) {
            if (isset($deferred[$fk->name])) {
                $nullFks[$fk->name] = true;
                continue;
            }
            $parent = $state[$fk->refTable] ?? null;
            if ($parent !== null && $parent['status'] === TableOutcome::GENERATED) {
                continue;
            }
            $why = $parent === null ? 'is not in the database' : 'was not generated (' . ($parent['reason'] ?? $parent['status']) . ')';
            if ($this->allNullable($table, $fk)) {
                $nullFks[$fk->name] = true;
                $notes[] = sprintf('fk_left_null:%s (parent %s %s)', implode(',', $fk->columns), $fk->refTable, $why);
            } else {
                return $this->failed($planned, Reason::PARENT_NOT_GENERATED, sprintf('needs %s, which %s', $fk->refTable, $why), 0, $before);
            }
        }

        $radix = $this->radixKeys($table, $rows);
        $plan = $this->columnPlan($table, $nullFks, $constants[$table->name] ?? []);
        $columns = array_map(static fn (array $p): string => $p['name'], $plan);
        $batchRows = max(1, min(self::MAX_BATCH_ROWS, intdiv(self::MAX_BATCH_VALUES, max(1, count($columns)))));

        $sink->begin();
        try {
            for ($start = 1; $start <= $planned; $start += $batchRows) {
                $batch = [];
                for ($i = $start; $i < $start + $batchRows && $i <= $planned; $i++) {
                    $batch[] = $this->row($schema, $table, $plan, $radix, $rows, $existing, $i, $constants);
                }
                $sink->insert($table->name, $columns, $batch);
            }

            $actual = $sink->count($table->name) - $before;
            if ($actual !== $planned) {
                $sink->rollBack();

                return $this->failed($planned, Reason::COUNT_MISMATCH, sprintf('planned %d rows, found %d', $planned, $actual), $actual, $before);
            }
            foreach ($table->columns as $column) {
                if ($column->autoIncrement) {
                    // ids must run 1..(existing + planned): a foreign key to row j is the id before + j
                    $expected = $before + $planned === 0 ? null : [1, $before + $planned];
                    if ($sink->span($table->name, $column->name) !== $expected) {
                        $sink->rollBack();

                        return $this->failed($planned, Reason::AUTOINC_GAP, sprintf('%s is not 1..%d', $column->name, $before + $planned), $actual, $before);
                    }
                }
            }
            $sink->commit();
        } catch (SinkFailure $e) {
            $sink->rollBack();

            return $this->failed($planned, $e->reason, $e->getMessage(), 0, $before);
        } catch (Throwable $e) {
            $sink->rollBack();

            return $this->failed($planned, Reason::INSERT_ERROR, $e->getMessage(), 0, $before);
        }

        $result = $this->state(TableOutcome::GENERATED, $planned, $planned, null, null, $before);
        $result['notes'] = $notes;

        return $result;
    }

    /**
     * Columns that are written: [name, kind (value|null|fk), fk (ForeignKey|null), index in key].
     *
     * @param array<string, true>  $nullFks
     * @param array<string, mixed> $tableConstants
     *
     * @return list<array{name: string, kind: string, fk: ?ForeignKey, position: int}>
     */
    private function columnPlan(TableSchema $table, array $nullFks, array $tableConstants): array
    {
        $plan = [];
        foreach ($table->columns as $column) {
            if ($column->generated || $column->autoIncrement) {
                continue;
            }
            $fk = $table->foreignKeyOf($column->name);
            if ($fk !== null && isset($nullFks[$fk->name])) {
                $plan[] = ['name' => $column->name, 'kind' => 'null', 'fk' => null, 'position' => 0];
            } elseif ($fk !== null) {
                $plan[] = ['name' => $column->name, 'kind' => 'fk', 'fk' => $fk, 'position' => (int) array_search($column->name, $fk->columns, true)];
            } elseif (array_key_exists($column->name, $tableConstants)) {
                $plan[] = ['name' => $column->name, 'kind' => 'constant', 'fk' => null, 'position' => 0];
            } elseif ($this->values->usage($table, $column) === ValueFactory::VALUE) {
                $plan[] = ['name' => $column->name, 'kind' => 'value', 'fk' => null, 'position' => 0];
            }
        }

        return $plan;
    }

    /**
     * @param list<array{name: string, kind: string, fk: ?ForeignKey, position: int}> $plan
     * @param array<string, array{stride: int}>                                        $radix
     * @param array<string, int>                                                       $rows
     * @param array<string, int>                                                       $existing
     * @param array<string, mixed>                                                     $constants
     *
     * @return list<mixed>
     */
    private function row(DatabaseSchema $schema, TableSchema $table, array $plan, array $radix, array $rows, array $existing, int $i, array $constants): array
    {
        $row = [];
        foreach ($plan as $entry) {
            $row[] = match ($entry['kind']) {
                'null' => null,
                'constant' => $constants[$table->name][$entry['name']],
                'fk' => $this->foreignKeyValue($schema, $entry['fk'], $entry['position'], $radix[$entry['fk']->name] ?? null, $rows, $existing, $i, $constants),
                default => $this->values->value($table, $table->column($entry['name']), $i),
            };
        }

        return $row;
    }

    /**
     * The value of one foreign key column: the parent's referenced column at row j. j goes round the parent's
     * rows (so a table scaled M times its parent gives every parent exactly M children); keys that must be
     * unique together walk the parents like the digits of a number.
     *
     * An auto-increment parent that held rows before generation has its generated rows after them: row j is id
     * (existing + j).
     *
     * @param ?array{stride: int} $radix
     * @param array<string, int>  $rows
     * @param array<string, int>  $existing
     * @param array<string, mixed> $constants
     */
    private function foreignKeyValue(DatabaseSchema $schema, ForeignKey $fk, int $position, ?array $radix, array $rows, array $existing, int $i, array $constants): mixed
    {
        $parentRows = max(1, $rows[$fk->refTable]);
        $j = $radix === null
            ? (($i - 1) % $parentRows) + 1
            : ((intdiv($i - 1, $radix['stride'])) % $parentRows) + 1;
        $parent = $schema->table($fk->refTable);
        $column = $parent?->column($fk->refColumns[$position]);
        if ($parent === null || $column === null) {
            return null;
        }
        if (isset($constants[$parent->name]) && array_key_exists($column->name, $constants[$parent->name])) {
            return $constants[$parent->name][$column->name];
        }

        $value = $this->values->value($parent, $column, $j);

        return $column->autoIncrement ? $existing[$parent->name] + $value : $value;
    }

    /**
     * Foreign keys that must be unique together (a pivot, or a one-to-one key): they take turns like the digits of
     * a number, the first key being the lowest digit. Returns key name => stride.
     *
     * @param array<string, int> $rows
     *
     * @return array<string, array{stride: int}>
     */
    private function radixKeys(TableSchema $table, array $rows): array
    {
        foreach ([$table->primaryKey, ...$table->uniqueIndexes] as $index) {
            $keys = ScalePlan::coveringForeignKeys($table, $index);
            if ($keys === null) {
                continue;
            }
            $out = [];
            $stride = 1;
            foreach ($keys as $fk) {
                $out[$fk->name] = ['stride' => $stride];
                $stride *= max(1, $rows[$fk->refTable] ?? 1);
            }

            return $out;
        }

        return [];
    }

    /**
     * Fills a deferred (NULL for now) foreign key, when the rows are plain enough to know the answer: a single
     * integer key column pointing at a single integer primary key. Otherwise the key stays NULL and says so.
     *
     * Only the rows the generator inserted are updated (the ids after the rows that already existed).
     *
     * @param array<string, int>   $rows
     * @param array<string, int>   $existing
     * @param array<string, array<string, mixed>> $state
     */
    private function fillDeferred(DatabaseSchema $schema, string $tableName, ForeignKey $fk, RowSink $sink, array $rows, array $existing, array &$state): void
    {
        $table = $schema->table($tableName);
        $parent = $schema->table($fk->refTable);
        $columns = implode(',', $fk->columns);

        if ($state[$tableName]['status'] !== TableOutcome::GENERATED) {
            return;
        }
        $pk = $table->singleIntegerPrimaryKey();
        $parentPk = $parent?->singleIntegerPrimaryKey();
        $reason = null;
        if ($parent === null || ($state[$fk->refTable]['status'] ?? null) !== TableOutcome::GENERATED) {
            $reason = 'parent was not generated';
        } elseif (count($fk->columns) !== 1 || $pk === null || $parentPk === null || $fk->refColumns !== [$parentPk->name]) {
            $reason = 'needs a single integer key on both sides';
        }
        if ($reason !== null) {
            $state[$tableName]['notes'][] = sprintf('fk_left_null:%s (%s)', $columns, $reason);

            return;
        }

        $t = Sql::identifier($tableName);
        $c = Sql::identifier($fk->columns[0]);
        $id = Sql::identifier($pk->name);
        $own = $existing[$tableName];
        if ($fk->refTable === $tableName) {
            // a binary tree over the generated rows: row n (n > 1) has row n DIV 2 as its parent
            $sql = $own === 0
                ? sprintf('UPDATE %s SET %s = %s DIV 2 WHERE %s > 1', $t, $c, $id, $id)
                : sprintf('UPDATE %s SET %s = %d + ((%s - %d) DIV 2) WHERE %s > %d', $t, $c, $own, $id, $own, $id, $own + 1);
        } else {
            $parent = $existing[$fk->refTable];
            $index = $own === 0 ? sprintf('(%s - 1)', $id) : sprintf('(%s - %d)', $id, $own + 1);
            $sql = sprintf('UPDATE %s SET %s = (%s %% %d) + 1%s%s', $t, $c, $index, max(1, $rows[$fk->refTable]), $parent > 0 ? ' + ' . $parent : '', $own > 0 ? sprintf(' WHERE %s > %d', $id, $own) : '');
        }
        try {
            $sink->execute($sql);
        } catch (Throwable $e) {
            $state[$tableName]['notes'][] = sprintf('fk_left_null:%s (update failed: %s)', $columns, TableOutcome::cut($e->getMessage()));
        }
    }

    /** @param array<string, mixed> $tableConstants @return list<string> */
    private function unsupportedColumns(TableSchema $table, array $tableConstants): array
    {
        $out = [];
        foreach ($table->columns as $column) {
            if (array_key_exists($column->name, $tableConstants)) {
                continue;
            }
            if ($this->values->usage($table, $column) === ValueFactory::UNSUPPORTED) {
                $out[] = sprintf('%s (%s)', $column->name, $column->dataType);
            }
        }

        return $out;
    }

    private function allNullable(TableSchema $table, ForeignKey $fk): bool
    {
        foreach ($fk->columns as $name) {
            if (!($table->column($name)?->nullable ?? false)) {
                return false;
            }
        }

        return true;
    }

    /** @throws MeasureRefused */
    private function assertMeasurementDatabase(RowSink $sink): void
    {
        DbGuard::assertDbName($sink->currentDatabase() ?? '');
    }

    /** @return array{status: string, planned: int, actual: int, reason: ?string, detail: ?string, notes: list<string>, existing: int} */
    private function state(string $status, int $planned, int $actual, ?string $reason, ?string $detail, int $existing = 0): array
    {
        return [
            'status' => $status,
            'planned' => $planned,
            'actual' => $actual,
            'reason' => $reason,
            'detail' => $detail === null ? null : TableOutcome::cut($detail),
            'notes' => [],
            'existing' => $existing,
        ];
    }

    /** @return array{status: string, planned: int, actual: int, reason: ?string, detail: ?string, notes: list<string>, existing: int} */
    private function failed(int $planned, string $reason, string $detail, int $actual = 0, int $existing = 0): array
    {
        return $this->state(TableOutcome::FAILED, $planned, $actual, $reason, $detail, $existing);
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $key): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException(sprintf('profile "%s" must be a list of table names', $key));
        }

        return array_values(array_map('strval', $value));
    }

    /** @return array<string, int> */
    private function rowOverrides(mixed $value): array
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('profile "rows" must map table names to row counts');
        }
        $out = [];
        foreach ($value as $table => $count) {
            if (!is_int($count) || $count < 1) {
                throw new InvalidArgumentException(sprintf('profile "rows" for %s must be an integer of at least 1', (string) $table));
            }
            $out[(string) $table] = $count;
        }

        return $out;
    }
}
