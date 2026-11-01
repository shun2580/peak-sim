<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Data;

/**
 * The only door through which the generator writes. PdoSink is the real one; tests use a recorder.
 * Everything goes to the database the connection has selected, which the generator first checks is a
 * measurement database.
 */
interface RowSink
{
    /** The database the connection has selected, or null. */
    public function currentDatabase(): ?string;

    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;

    /**
     * One multi-row INSERT.
     *
     * @param list<string>     $columns
     * @param list<list<mixed>> $rows
     *
     * @throws SinkFailure when the database refuses the rows
     */
    public function insert(string $table, array $columns, array $rows): void;

    /**
     * One UPDATE statement built by the generator (deferred foreign keys). Returns the affected rows.
     *
     * @throws SinkFailure
     */
    public function execute(string $sql): int;

    public function count(string $table): int;

    /**
     * Smallest and largest value of an integer column, or null for an empty table.
     *
     * @return ?array{0: int, 1: int}
     */
    public function span(string $table, string $column): ?array;
}
