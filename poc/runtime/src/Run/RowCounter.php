<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Run;

use PDO;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Guard\DbGuard;

/**
 * The last line of defence of condition 6: the row count of every table, read over the tool's own connection (so
 * its queries are never mixed into the app's count). Before and after an attempt they must be equal.
 */
final class RowCounter
{
    public function __construct(private readonly PDO $pdo, private readonly string $dbName)
    {
        DbGuard::assertDbName($dbName);
    }

    public static function connect(MeasureConfig $config, string $dbName): self
    {
        DbGuard::assertHost($config->dbHost);
        DbGuard::assertUser($config->dbUser);
        DbGuard::assertDbName($dbName);
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->dbHost, $config->dbPort, $dbName),
            $config->dbUser,
            $config->dbPassword,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5],
        );

        return new self($pdo, $dbName);
    }

    /** @return array<string, int> table => rows */
    public function snapshot(): array
    {
        $statement = $this->pdo->prepare("select table_name from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE' order by table_name");
        $statement->execute([$this->dbName]);
        $counts = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $table = (string) $table;
            $quoted = '`' . str_replace('`', '``', $this->dbName) . '`.`' . str_replace('`', '``', $table) . '`';
            $counts[$table] = (int) $this->pdo->query('select count(*) from ' . $quoted)->fetchColumn();
        }

        return $counts;
    }
}
