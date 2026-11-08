<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Explain;

use PDO;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Guard\DbGuard;

/** One connection to ONE measurement database as the `measure` user (host, user and name are checked before it connects). */
final class ExplainDb
{
    /** @throws MeasureRefused */
    public static function open(MeasureConfig $config, string $database): PDO
    {
        DbGuard::assertHost($config->dbHost);
        DbGuard::assertUser($config->dbUser);
        DbGuard::assertDbName($database);
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->dbHost, $config->dbPort, $database);

        return new PDO($dsn, $config->dbUser, $config->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    }
}
