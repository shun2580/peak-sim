<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker\Support;

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Guard\MeasureDb;
use PHPUnit\Framework\TestCase;

/**
 * Base of the tests that need the sealed container and the measurement database. Run them INSIDE the container:
 *   bin/stack exec /peak-sim/vendor/bin/phpunit -c /peak-sim/poc/runtime/phpunit.runtime.xml --testsuite RuntimeDocker
 * A test that cannot reach its stack FAILS (it never skips): the answer is to bring the stack up.
 */
abstract class DockerCase extends TestCase
{
    protected const RUNTIME = '/peak-sim/poc/runtime';

    protected static function config(): MeasureConfig
    {
        return MeasureConfig::fromEnv(getenv());
    }

    protected static function db(): MeasureDb
    {
        return MeasureDb::connect(self::config());
    }

    /**
     * Runs a PHP script in a fresh process.
     *
     * @param list<string>          $args
     * @param array<string, string> $env   added to (and overriding) this process's environment
     *
     * @return array{int, string, string} [exit code, stdout, stderr]
     */
    protected static function php(string $script, array $args = [], array $env = []): array
    {
        $command = array_merge([PHP_BINARY, $script], $args);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start ' . $script);
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    /**
     * Makes, migrates and seeds the measurement database of one scale.
     *
     * @param array{int, int, int, int} $sizes customers, products, orders, items per order
     */
    protected static function prepare(int $scale, array $sizes = [20, 20, 20, 3], ?string $appRoot = null): void
    {
        [$exit, , $err] = self::php(__DIR__ . '/prepare.php', [(string) $scale, ...array_map('strval', $sizes)], $appRoot === null ? [] : ['PEAK_MEASURE_APP_ROOT' => $appRoot]);
        if ($exit !== 0) {
            throw new \RuntimeException("prepare failed ($exit): " . $err);
        }
    }

    protected static function drop(int $scale): void
    {
        self::db()->drop(self::config()->dbNameForScale($scale));
    }

    /**
     * Runs bin/measure-one.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{int, list<array<string, mixed>>, string} [exit code, attempts, stderr]
     */
    protected static function measure(array $args, array $env = []): array
    {
        [$exit, $out, $err] = self::php(self::RUNTIME . '/bin/measure-one', $args, $env);
        $attempts = [];
        foreach (explode("\n", trim($out)) as $line) {
            if ($line !== '') {
                $attempts[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return [$exit, $attempts, $err];
    }

    /** One line per file: path, size and mtime; the content hash for everything outside vendor. */
    protected static function snapshot(string $root): string
    {
        $lines = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($items as $item) {
            $path = $item->getPathname();
            $relative = substr($path, strlen($root));
            $hash = str_starts_with($relative, '/vendor/') || !$item->isFile() ? '-' : md5_file($path);
            $lines[] = $relative . ' ' . $item->getSize() . ' ' . $item->getMTime() . ' ' . $hash;
        }
        sort($lines);

        return md5(implode("\n", $lines)) . ' ' . count($lines);
    }
}
