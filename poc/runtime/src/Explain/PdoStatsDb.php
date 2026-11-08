<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

use PDO;

final class PdoStatsDb implements StatsDb
{
    public function __construct(private readonly PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function run(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            return [];
        }
        $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
        $statement->closeCursor();

        return $rows;
    }
}
