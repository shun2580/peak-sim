<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Guard;

use PDO;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;

/**
 * Creates and drops measurement databases. These two statements are the only DDL the tool ever sends; the rest
 * of the DDL is the target app's own migrations, run inside a measurement database.
 *
 * Every entry point re-checks the name (DbGuard::assertDbName) before any SQL is built, so a bad name never
 * reaches the server.
 */
final class MeasureDb
{
    /** Seconds a statement of this connection waits for a metadata lock before it fails. */
    public const LOCK_WAIT_SECONDS = 120;

    public function __construct(private readonly DbExecutor $db)
    {
    }

    /** Connects to the DB service server-wide (no database selected) as the `measure` user. */
    public static function connect(MeasureConfig $config): self
    {
        DbGuard::assertHost($config->dbHost);
        DbGuard::assertUser($config->dbUser);
        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config->dbHost, $config->dbPort);
        $pdo = new PDO($dsn, $config->dbUser, $config->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            // A DROP DATABASE that waits for a lock a leftover statement holds gives up after this many seconds, not never.
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION lock_wait_timeout = ' . self::LOCK_WAIT_SECONDS,
        ]);

        return new self(new PdoExecutor($pdo));
    }

    /** @throws MeasureRefused */
    public function create(string $name): void
    {
        DbGuard::assertDbName($name);
        $this->db->exec(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $name));
    }

    /** @throws MeasureRefused */
    public function drop(string $name): void
    {
        DbGuard::assertDbName($name);
        $this->db->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $name));
    }

    /**
     * Measurement databases that exist now (the server-side filter is repeated in PHP: only names that pass the
     * guard are returned).
     *
     * @return list<string>
     */
    public function listMeasureDatabases(): array
    {
        $names = $this->db->column("SHOW DATABASES LIKE 'peak\\_sim\\_measure\\_%'");

        return array_values(array_filter($names, DbGuard::isMeasureDbName(...)));
    }

    /**
     * Drops every measurement database (the cleanup after an abnormal exit).
     *
     * @return list<string> the dropped names
     */
    public function dropAll(): array
    {
        $dropped = [];
        foreach ($this->listMeasureDatabases() as $name) {
            $this->drop($name);
            $dropped[] = $name;
        }

        return $dropped;
    }
}
