<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Guard;

use PDO;

final class PdoExecutor implements DbExecutor
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function column(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        if ($statement === false) {
            return [];
        }

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
