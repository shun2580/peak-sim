<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

/** The wire under `StatsStatements`: runs one statement and returns its rows (a statement without a result set returns none). */
interface StatsDb
{
    /** @return list<array<string, mixed>> */
    public function run(string $sql): array;
}
