<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Compose;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A measured database is kept unless the run is given `--purge`.
 *
 * `StackTeardownTest` keeps a clean-up from removing the database *volume*. This keeps the run from dropping the *databases in
 * it*: the driver used to drop each database as soon as its routes were measured, and `bin/inner/cleanup.php` ran at the end of
 * every run, so a volume could be kept with no database in it. Now only `--purge` does either.
 *
 * No Docker and no database: `docker` is a stand-in that records what it was asked to do and never passes anything on, so
 * nothing here can drop a database or a volume, whichever flag is given.
 */
final class MeasuredDatabaseKeptTest extends TestCase
{
    private const PROJECT = 'peak-measure-ut';

    private string $dir = '';

    private string $log = '';

    private int $runs = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-dbkept-test-' . bin2hex(random_bytes(4));
        foreach (['fakebin', 'app/vendor', 'out'] as $d) {
            mkdir($this->dir . '/' . $d, 0777, true);
        }
        touch($this->dir . '/app/vendor/autoload.php');
        file_put_contents($this->dir . '/static.json', '{}');
        $this->log = $this->dir . '/docker.log';
        // Records every call. Answers what bin/preflight asks about the network (one internal network), and succeeds at the rest.
        file_put_contents($this->dir . '/fakebin/docker', <<<'SH'
            #!/usr/bin/env bash
            printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"
            case "$*" in
              "volume ls"*) exit 0 ;;
              "network ls"*) echo net1; exit 0 ;;
              "network inspect"*Internal*) echo true; exit 0 ;;
              "network inspect"*) echo net-name; exit 0 ;;
            esac
            exit 0
            SH);
        chmod($this->dir . '/fakebin/docker', 0755);

        $which = $this->runProcess(['bash', '-c', 'command -v docker'], $this->env());
        $this->assertSame($this->dir . '/fakebin/docker', trim($which[1]), 'the stand-in docker must be the one the scripts find');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->dir);
    }

    private static function runtimeDir(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function env(array $extra = []): array
    {
        return array_merge([
            'PATH' => $this->dir . '/fakebin:/usr/bin:/bin',
            'HOME' => $this->dir,
            'PEAK_OUT_DIR' => $this->dir . '/out',
            'PEAK_COMPOSE_PROJECT' => self::PROJECT,
            'FAKE_DOCKER_LOG' => $this->log,
        ], $extra);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function runProcess(array $command, array $env): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }

    /**
     * @param list<string>          $extraArgs
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function runScript(string $script, array $extraArgs = [], array $env = []): array
    {
        $this->runs++;
        $args = ['--app=' . $this->dir . '/app', '--static=' . $this->dir . '/static.json', '--out=' . $this->dir . '/rec-' . $this->runs];

        return $this->runProcess(['bash', self::runtimeDir() . '/bin/' . $script, ...$args, ...$extraArgs], $this->env($env));
    }

    /** @return list<string> */
    private function calls(): array
    {
        return is_file($this->log) ? array_values(array_filter(explode("\n", (string) file_get_contents($this->log)))) : [];
    }

    /** @return list<string> the calls that ran a PHP script of the runtime inside the container, in order */
    private function execCalls(): array
    {
        return array_values(array_filter($this->calls(), static fn (string $c): bool => preg_match('/^compose .* exec .*\/poc\/runtime\/bin\/inner\//', $c) === 1));
    }

    /** @return list<string> */
    private function callsOf(string $innerScript): array
    {
        return array_values(array_filter($this->execCalls(), static fn (string $c): bool => str_contains($c, '/bin/inner/' . $innerScript)));
    }

    /** @return iterable<string, array{string}> */
    public static function scriptsThatRunTheDriver(): iterable
    {
        yield 'bin/measure' => ['measure'];
        yield 'bin/capture' => ['capture'];
    }

    // 1-2: the host scripts

    #[DataProvider('scriptsThatRunTheDriver')]
    public function testWithoutPurgeTheDriverIsNotToldToDropAndNoCleanupRuns(string $script): void
    {
        [$code, , $err] = $this->runScript($script);

        $this->assertSame(0, $code, $err);
        $driver = $this->callsOf('measure.php');
        $this->assertCount(1, $driver, 'the driver is run once');
        $this->assertStringNotContainsString('drop-db', $driver[0], 'no --drop-db at all, so the driver keeps its default: keep');
        $this->assertSame([], $this->callsOf('cleanup.php'), 'bin/inner/cleanup.php is not called: it would drop every measurement database');
    }

    #[DataProvider('scriptsThatRunTheDriver')]
    public function testWithPurgeTheDriverDropsAndTheCleanupRunsAfterIt(string $script): void
    {
        [$code, , $err] = $this->runScript($script, ['--purge']);

        $this->assertSame(0, $code, $err);
        $driver = $this->callsOf('measure.php');
        $this->assertCount(1, $driver);
        $this->assertStringContainsString(' --drop-db=on', $driver[0]);
        $cleanup = $this->callsOf('cleanup.php');
        $this->assertCount(1, $cleanup);
        $this->assertSame(
            [$driver[0], $cleanup[0]],
            array_values(array_filter($this->execCalls(), static fn (string $c): bool => str_contains($c, 'measure.php') || str_contains($c, 'cleanup.php'))),
            'the driver first, the cleanup after it',
        );
        $down = array_values(array_filter($this->calls(), static fn (string $c): bool => preg_match('/^compose .* down( |$)/', $c) === 1));
        $this->assertCount(1, $down);
        $this->assertStringContainsString('--volumes', $down[0], 'and the volume goes with them');
    }

    #[DataProvider('scriptsThatRunTheDriver')]
    public function testNoEnvironmentVariableAndNoOtherFlagMakesTheRunDrop(string $script): void
    {
        $env = [];
        foreach (['PEAK_PURGE', 'PEAK_DROP_DB', 'PEAK_MEASURE_DROP_DB', 'DROP_DB', 'PURGE', 'PEAK_KEEP_DB'] as $name) {
            $env[$name] = '1';
        }
        [$code, , $err] = $this->runScript($script, [], $env);

        $this->assertSame(0, $code, $err);
        $this->assertStringNotContainsString('drop-db', $this->callsOf('measure.php')[0]);
        $this->assertSame([], $this->callsOf('cleanup.php'));
    }

    #[DataProvider('scriptsThatRunTheDriver')]
    public function testTheInnerFlagCannotBeGivenToTheHostScript(string $script): void
    {
        foreach (['--drop-db=on', '--drop-db', '--keep-db=off'] as $flag) {
            [$code, , $err] = $this->runScript($script, [$flag]);

            $this->assertSame(2, $code, $err);
            $this->assertStringContainsString('unknown argument', $err);
        }
        $this->assertSame([], $this->calls(), 'docker is not called for a wrong usage');
    }

    #[DataProvider('scriptsThatRunTheDriver')]
    public function testPurgeStillCannotBeCombinedWithKeepStack(string $script): void
    {
        [$code, , $err] = $this->runScript($script, ['--purge', '--keep-stack']);

        $this->assertSame(2, $code, $err);
        $this->assertSame([], $this->calls());
    }

    #[DataProvider('scriptsThatRunTheDriver')]
    public function testWithKeepStackTheDatabasesAreKeptAndNothingIsCleanedUp(string $script): void
    {
        [$code, , $err] = $this->runScript($script, ['--keep-stack']);

        $this->assertSame(0, $code, $err);
        $this->assertStringNotContainsString('drop-db', $this->callsOf('measure.php')[0]);
        // The cleanup used to run here too, and dropped the databases of a stack that was meant to be looked at.
        $this->assertSame([], $this->callsOf('cleanup.php'));
    }

    // 3: the usage text says the same as the code

    #[DataProvider('scriptsThatRunTheDriver')]
    public function testTheUsageSaysPurgeDropsTheDatabasesAndWithoutItBothAreKept(string $script): void
    {
        [$code, $out] = $this->runScript($script, ['--help']);

        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/--purge\s+also drop the databases the run measured and remove the database volume/', $out);
        $this->assertStringContainsString('kept', $out);
    }

    // 4: the inner flag is read, its default is "keep", and nothing else spells it

    public function testTheDriverIsGivenTheFlagAndNothingElseTurnsDroppingOn(): void
    {
        $inner = (string) file_get_contents(self::runtimeDir() . '/bin/inner/measure.php');
        $this->assertStringContainsString('$options->tapDir, $lastRoutes, $options->dropDb);', $inner, 'the driver gets the parsed flag, and no literal true');
        $this->assertStringNotContainsString('dropDatabases: true', $inner);

        $driver = (string) file_get_contents(self::runtimeDir() . '/src/Measure/Driver.php');
        $this->assertStringContainsString('private readonly bool $dropDatabases = false,', $driver, 'the driver keeps by default');
        // The one call of dropQuietly (the point's end) is inside the `if`; the other `drop` is the rebuild of a changed database.
        $this->assertSame(1, preg_match_all('/\$this->dropQuietly\(/', $driver));
        $this->assertSame(1, preg_match('/if \(\$this->dropDatabases\) \{\n\s*\$this->dropQuietly\(\$point, \$built\);\n\s*\}/', $driver), 'the end of a point drops only when told to');

        $options = (string) file_get_contents(self::runtimeDir() . '/src/Measure/Options.php');
        $this->assertStringContainsString("\$values['drop-db'] ?? 'off'", $options);
        $this->assertStringContainsString('public readonly bool $dropDb = false,', $options);
    }

    // 5: nothing else drops a database

    /**
     * The lines of the tools that drop a measurement database, or call what does: `DROP DATABASE`, a call of a method whose
     * name starts with `drop`, `dropAll`, and the callers of `bin/cleanup` and of `bin/inner/cleanup.php`. As "<path relative to
     * the repository>: <the line, trimmed>", one entry per occurrence. A new one has to be added here on purpose, so a way to
     * drop a measured database cannot come in unseen, and one that goes away has to be taken off.
     *
     * Not a way to lose a measured database without `--purge`:
     * - the inspection database (`bin/inner/measure.php`): a small one the driver builds to read the routes; no route is measured on it.
     * - the driver's rebuild of a database a request changed, and the build's own drop of a database of the same name before it
     *   makes it (the names carry the run id, so it is never a database of an earlier run).
     * - `bin/explain-at-scale` (its own databases for the plans, kept with `--keep-db`) and `bin/cleanup` (called by hand): separate
     *   commands, named here because they drop.
     *
     * The ones that are `--purge`'s: the call of `dropQuietly` (inside `if ($this->dropDatabases)`) and the `cleanup.php` lines of
     * `bin/measure` and `bin/capture` (inside `if [ "$purge" = 1 ]`); the tests above show both.
     */
    private const ALLOWED = [
        // the one DROP DATABASE, and what reaches it
        'poc/runtime/src/Guard/MeasureDb.php: $this->db->exec(sprintf(\'DROP DATABASE IF EXISTS `%s`\', $name));',
        'poc/runtime/src/Guard/MeasureDb.php: public function dropAll(): array',
        'poc/runtime/src/Guard/MeasureDb.php: $this->drop($name);',
        'poc/runtime/src/Measure/ProcessDatabaseBuilder.php: MeasureDb::connect($config)->drop($config->dbNameForScale($point->scale));',
        'poc/runtime/src/Measure/AnalyzingDatabaseBuilder.php: $this->inner->drop($point);',
        'poc/runtime/src/Measure/DatabaseBuild.php: $measureDb->drop($name);',
        // the driver: the end of a point (--purge), the rebuild of a changed database, and dropQuietly's own drop
        'poc/runtime/src/Measure/Driver.php: $this->dropQuietly($point, $built);',
        'poc/runtime/src/Measure/Driver.php: $this->builder->drop($point);',
        'poc/runtime/src/Measure/Driver.php: $this->builder->drop($point);',
        // the inspection database
        'poc/runtime/bin/inner/measure.php: $builder->drop($inspection);',
        'poc/runtime/bin/inner/measure.php: $builder->drop($inspection);',
        // cleanup, called by hand and by the --purge branch of the two scripts
        'poc/runtime/bin/inner/cleanup.php: $dropped = $db->dropAll();',
        'poc/runtime/bin/cleanup: in_php /peak-sim/poc/runtime/bin/inner/cleanup.php',
        'poc/runtime/bin/measure: compose exec -T measure-php php /peak-sim/poc/runtime/bin/inner/cleanup.php >/dev/null 2>&1 || true',
        'poc/runtime/bin/capture: compose exec -T measure-php php /peak-sim/poc/runtime/bin/inner/cleanup.php >/dev/null 2>&1 || true',
        // bin/explain-at-scale: its own databases
        'poc/runtime/bin/explain-at-scale: step --step=drop "--scale=$gscale" || echo "[$(now_iso)] g$g: the database could not be dropped (the stack\'s teardown removes it)" >&2',
        'poc/runtime/bin/inner/explain-at-scale.php: MeasureDb::connect($config)->drop($name);',
    ];

    /** @return list<string> the patterns of a line that drops a database or calls what does */
    private static function patterns(): array
    {
        return [
            '/DROP\s+DATABASE/i',
            '/->drop\w*\(/',          // ->drop( ->dropAll( ->dropQuietly( ->dropIfExists(
            '/\bdropAll\b/',
            '/inner\/cleanup\.php/',
            '/\bbin\/cleanup\b/',
            '/--step=drop\b/',
        ];
    }

    private static function dropsADatabase(string $line): bool
    {
        foreach (self::patterns() as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function isComment(string $line): bool
    {
        return $line === '' || $line[0] === '#' || str_starts_with($line, '//') || str_starts_with($line, '*') || str_starts_with($line, '/*');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function dropFormsAndOthers(): iterable
    {
        yield 'DROP DATABASE' => ['$this->db->exec("DROP DATABASE `x`");', true];
        yield 'lower case' => ['drop database x;', true];
        yield 'a method call' => ['$builder->drop($point);', true];
        yield 'dropAll' => ['$dropped = $db->dropAll();', true];
        yield 'a call of dropQuietly' => ['$this->dropQuietly($point, $built);', true];
        yield 'dropIfExists is a call too' => ['$schema->dropIfExists("t");', true];
        yield 'the cleanup script' => ['compose exec -T measure-php php /peak-sim/poc/runtime/bin/inner/cleanup.php', true];
        yield 'bin/cleanup' => ['bash "$here/../bin/cleanup"', true];
        yield 'the explain step' => ['step --step=drop "--scale=$s"', true];
        yield 'a definition is not a call' => ['public function drop(DatabasePoint $point): void;', false];
        yield 'a variable named drop' => ['$drop = 1;', false];
        yield 'a word in a sentence' => ['echo "drop the volume"', false];
    }

    #[DataProvider('dropFormsAndOthers')]
    public function testTheWatchRecognisesTheFormsThatDropADatabase(string $line, bool $drops): void
    {
        $this->assertSame($drops, self::dropsADatabase($line), $line);
    }

    public function testNoToolDropsADatabaseExceptTheListedOnes(): void
    {
        $repo = dirname(self::runtimeDir(), 2);
        $files = [];
        foreach (['poc/runtime/src', 'poc/runtime/bin', 'poc/runtime/profiles', 'poc/lab', 'scripts'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($repo . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = $file->getPathname();
                $relative = substr($path, strlen($repo) + 1);
                if (!$file->isFile() || preg_match('#(^|/)(tests|predictions|vendor)/#', $relative) === 1) {
                    continue;
                }
                $extension = pathinfo($path, PATHINFO_EXTENSION);
                if ($extension === '' || in_array($extension, ['php', 'sh', 'py'], true)) {
                    $files[] = $path;
                }
            }
        }
        $this->assertGreaterThan(100, count($files), 'the scan reaches the tools');

        $found = [];
        foreach ($files as $path) {
            $relative = substr($path, strlen($repo) + 1);
            foreach (explode("\n", (string) file_get_contents($path)) as $line) {
                $line = trim($line);
                if (self::isComment($line)) {
                    continue; // a comment runs nothing
                }
                if (self::dropsADatabase($line)) {
                    $found[] = $relative . ': ' . $line;
                }
            }
        }

        $expected = self::ALLOWED;
        sort($found);
        sort($expected);
        // One entry per occurrence, both ways: a way that is not listed fails, and a listed way that is gone fails.
        $this->assertSame($expected, $found, 'the lines that drop a database, or call what does, are not the listed ones');
    }
}
