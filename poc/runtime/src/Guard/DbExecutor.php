<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Guard;

/**
 * The only SQL door of MeasureDb. The real implementation talks PDO to the compose DB service; tests use a recorder.
 */
interface DbExecutor
{
    public function exec(string $sql): void;

    /**
     * First column of every row.
     *
     * @return list<string>
     */
    public function column(string $sql): array;
}
