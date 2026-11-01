<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker\Data\Support;

use PDO;
use PeakSim\Runtime\Tests\Docker\Support\DockerCase;

/**
 * Base of the data generator tests that run inside the sealed container. The target app is whatever is mounted at
 * /app (the verification app by default). Each test starts from a freshly migrated, empty measurement database.
 */
abstract class GenerationCase extends DockerCase
{
    /** Migrates the app into the (re-created) measurement database of one scale and returns the child's report. */
    protected static function fresh(int $scale): array
    {
        [$exit, $out, $err] = self::php(__DIR__ . '/migrate.php', [(string) $scale]);
        if ($exit !== 0) {
            throw new \RuntimeException(sprintf('migrate failed (%d): %s %s', $exit, mb_substr($out, -600), mb_substr($err, -600)));
        }

        return json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
    }

    /** A connection as the generator gets it: the `measure` user, with the measurement database selected. */
    protected static function pdo(int $scale): PDO
    {
        $config = self::config();
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config->dbHost, $config->dbPort, $config->dbNameForScale($scale));

        return new PDO($dsn, $config->dbUser, $config->dbPassword, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
    }

    protected static function rowCount(PDO $pdo, string $table): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
    }

    protected static function scalar(PDO $pdo, string $sql): mixed
    {
        return $pdo->query($sql)->fetchColumn();
    }

    /** @param array<string, mixed> $data */
    protected static function writeOut(string $file, array $data): void
    {
        $dir = self::config()->outDir . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create ' . $dir);
        }
        file_put_contents($dir . '/' . $file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
