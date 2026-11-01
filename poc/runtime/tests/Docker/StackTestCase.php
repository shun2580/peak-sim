<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

use PHPUnit\Framework\TestCase;

/**
 * Base of the RuntimeDocker suite. These tests drive real Docker: the measurement compose, the sealed network,
 * the MySQL service. They run in a driver container (tests/Docker/run.sh) that has the Docker CLI and socket, or on a
 * host that has PHP and Docker. When Docker is missing they FAIL, they never skip.
 *
 * Environment (set by run.sh):
 *   PEAK_REPO_ROOT          this repository, as the Docker daemon sees it
 *   PEAK_OUT_DIR            writable run directory, as the Docker daemon sees it
 *   PEAK_EVIDENCE_DIR       where evidence files are written (optional)
 *   PEAK_COMPOSE_PROJECT    compose project name prefix
 */
abstract class StackTestCase extends TestCase
{
    /** @var array<string, string> */
    protected static array $stackEnv = [];

    protected static string $composeFile = 'measure.compose.yaml';

    protected static string $project = '';

    public static function runtimeDir(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    protected static function exec(array $command, array $env = []): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            array_merge(self::inheritedEnv(), static::$stackEnv, $env),
        );
        if (!is_resource($process)) {
            return [127, '', 'could not start ' . $command[0]];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /** @return array<string, string> */
    private static function inheritedEnv(): array
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            $env[(string) $key] = (string) $value;
        }

        return $env;
    }

    /**
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    protected static function bin(string $script, array $args = [], array $env = []): array
    {
        return self::exec(['bash', self::runtimeDir() . '/bin/' . $script, ...$args], $env);
    }

    /**
     * A PHP script of poc/runtime run inside the sealed PHP container.
     *
     * @param list<string>          $extraEnvFlags docker compose exec -e flags, e.g. ['-e', 'KEY=value']
     *
     * @return array{0: int, 1: string, 2: string}
     */
    protected static function inPhp(string $script, array $extraEnvFlags = []): array
    {
        return self::exec([
            'docker', 'compose', '-p', static::$project, '-f', self::runtimeDir() . '/compose/' . static::$composeFile,
            'exec', '-T', ...$extraEnvFlags, 'measure-php', 'php', $script,
        ]);
    }

    /**
     * PHP code run inside the sealed PHP container (php -r), after loading the PoC autoloader.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    protected static function phpCode(string $code): array
    {
        return self::exec([
            'docker', 'compose', '-p', static::$project, '-f', self::runtimeDir() . '/compose/' . static::$composeFile,
            'exec', '-T', 'measure-php', 'php', '-r', 'require "/peak-sim/poc/runtime/bootstrap.php"; ' . $code,
        ]);
    }

    /**
     * SQL as root, from inside the DB container. Only the tests do this; the PHP container never has the root password.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    protected static function rootSql(string $sql): array
    {
        return self::exec([
            'docker', 'compose', '-p', static::$project, '-f', self::runtimeDir() . '/compose/' . static::$composeFile,
            'exec', '-T', '-e', 'MYSQL_PWD=' . static::$stackEnv['PEAK_MEASURE_DB_ROOT_PASSWORD'],
            'measure-db', 'mysql', '-uroot', '-N', '-B', '-e', $sql,
        ]);
    }

    protected static function randomHex(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /** Fails (never skips) when Docker is not usable. */
    protected static function requireDocker(): void
    {
        [$code, , $err] = self::exec(['docker', 'version', '--format', '{{.Server.Version}}']);
        self::assertSame(0, $code, 'Docker is required for the RuntimeDocker suite: ' . trim($err));
        self::assertNotSame('', (string) getenv('PEAK_REPO_ROOT'), 'PEAK_REPO_ROOT (the repository path the Docker daemon sees) must be set; use tests/Docker/run.sh');
        self::assertNotSame('', (string) getenv('PEAK_OUT_DIR'), 'PEAK_OUT_DIR must be set; use tests/Docker/run.sh');
    }

    /** @param array<string, string> $extraEnv set for the stack's commands too, e.g. PEAK_PHP_IMAGE */
    protected static function bringUp(string $projectSuffix, string $composeFile, array $extraEnv = []): void
    {
        self::requireDocker();
        static::$composeFile = $composeFile;
        static::$project = ((string) (getenv('PEAK_COMPOSE_PROJECT') ?: 'peak-measure-dev')) . $projectSuffix;
        static::$stackEnv = [
            'PEAK_COMPOSE_PROJECT' => static::$project,
            'PEAK_COMPOSE_FILE' => self::runtimeDir() . '/compose/' . $composeFile,
            'PEAK_MEASURE_DB_ROOT_PASSWORD' => self::randomHex(12),
            'PEAK_MEASURE_DB_PASSWORD' => self::randomHex(12),
            'PEAK_MEASURE_RUN_ID' => self::randomHex(6),
            ...$extraEnv,
        ];
        [$code, $out, $err] = self::bin('stack', ['up']);
        self::assertSame(0, $code, "stack up failed:\n" . $out . $err);
    }

    protected static function tearDownStack(): void
    {
        if (static::$project !== '') {
            self::bin('stack', ['down']);
        }
    }

    /** Writes evidence under PEAK_EVIDENCE_DIR/<dir>/<name>. A no-op without that variable (the test still asserts). */
    protected static function evidence(string $dir, string $name, string $content): void
    {
        $base = (string) getenv('PEAK_EVIDENCE_DIR');
        if ($base === '') {
            return;
        }
        $target = $base . '/' . $dir;
        if (!is_dir($target)) {
            mkdir($target, 0775, true);
        }
        file_put_contents($target . '/' . $name, $content);
    }

    /**
     * @param array{0: int, 1: string, 2: string} $result
     */
    protected static function transcript(string $command, array $result): string
    {
        return "$ $command\n" . $result[1] . ($result[2] !== '' ? "[stderr]\n" . $result[2] : '') . "[exit code {$result[0]}]\n";
    }
}
