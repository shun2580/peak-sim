<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Data;

use PeakSim\Runtime\Data\RowSink;
use PeakSim\Runtime\Data\SinkFailure;

/** A RowSink that remembers what the generator did. Counts and auto-increment spans follow the inserts. */
final class RecordingSink implements RowSink
{
    /** @var list<string> */
    public array $log = [];

    /** @var array<string, list<array{columns: list<string>, rows: list<list<mixed>>}>> */
    public array $batches = [];

    /** @var list<string> */
    public array $executed = [];

    /** @var array<string, array{0: string, 1: string}> table => [reason, message]: the insert into it fails */
    public array $failOn = [];

    /** @var array<string, int> */
    public array $countOverride = [];

    /** @var array<string, array{0: int, 1: int}> */
    public array $spanOverride = [];

    public int $rollBacks = 0;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> tables whose rows were rolled back */
    private array $pending = [];

    public function __construct(public ?string $database = 'peak_sim_measure_abcdef123456_100')
    {
    }

    public function currentDatabase(): ?string
    {
        return $this->database;
    }

    public function begin(): void
    {
        $this->log[] = 'begin';
    }

    public function commit(): void
    {
        $this->log[] = 'commit';
        $this->pending = [];
    }

    public function rollBack(): void
    {
        $this->log[] = 'rollback';
        $this->rollBacks++;
        foreach ($this->pending as $table) {
            unset($this->counts[$table], $this->batches[$table]);
        }
        $this->pending = [];
    }

    public function insert(string $table, array $columns, array $rows): void
    {
        $this->log[] = sprintf('insert %s x%d', $table, count($rows));
        if (isset($this->failOn[$table])) {
            throw new SinkFailure($this->failOn[$table][0], $this->failOn[$table][1]);
        }
        $this->batches[$table][] = ['columns' => $columns, 'rows' => $rows];
        $this->counts[$table] = ($this->counts[$table] ?? 0) + count($rows);
        $this->pending[] = $table;
    }

    public function execute(string $sql): int
    {
        $this->log[] = 'execute ' . $sql;
        $this->executed[] = $sql;

        return 0;
    }

    public function count(string $table): int
    {
        return $this->countOverride[$table] ?? ($this->counts[$table] ?? 0);
    }

    public function span(string $table, string $column): ?array
    {
        if (isset($this->spanOverride[$table])) {
            return $this->spanOverride[$table];
        }
        $n = $this->counts[$table] ?? 0;

        return $n === 0 ? null : [1, $n];
    }

    /** @return list<array<string, mixed>> rows of a table as column => value maps, in insert order */
    public function assoc(string $table): array
    {
        $out = [];
        foreach ($this->batches[$table] ?? [] as $batch) {
            foreach ($batch['rows'] as $row) {
                $out[] = array_combine($batch['columns'], $row);
            }
        }

        return $out;
    }

    /** @return list<string> */
    public function insertedTables(): array
    {
        $tables = [];
        foreach ($this->log as $line) {
            if (preg_match('/^insert (\S+) x\d+$/', $line, $m) === 1 && !in_array($m[1], $tables, true)) {
                $tables[] = $m[1];
            }
        }

        return $tables;
    }
}
