<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

use PDO;

/**
 * Sends a gated statement over PDO with a real server-side prepared statement (emulation off, multi statements off), so
 * even a text the gate misread cannot become a second statement on the wire: the server's own protocol takes one.
 */
final class PdoExplainTransport implements ExplainTransport
{
    public function __construct(private readonly PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_MULTI_STATEMENTS, false);
        }
    }

    public function fetchPlanJson(string $sql, array $bindings): string
    {
        $statement = $this->pdo->prepare($sql);
        foreach (array_values($bindings) as $i => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($i + 1, is_bool($value) ? (int) $value : (is_float($value) ? (string) $value : $value), $type);
        }
        $statement->execute();
        $row = $statement->fetch(PDO::FETCH_NUM);
        $statement->closeCursor();
        if ($row === false || !isset($row[0]) || !is_string($row[0])) {
            throw new \RuntimeException('EXPLAIN FORMAT=JSON returned no plan');
        }

        return $row[0];
    }
}
