<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Compose;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a run leaves behind. The database of a measurement can take hours to build, so a clean-up must never remove it
 * unless it is told to: `bin/stack down` and the end of `bin/measure`, `bin/capture`, `bin/explain-at-scale` and
 * `bin/variance-run` keep the database volume, and only `--purge` removes it.
 *
 * No Docker needed: `docker` is a stand-in that records what it was asked to do (and never passes anything on). The first
 * assertion of every test run is that the stand-in is the `docker` the scripts find.
 */
final class StackTeardownTest extends TestCase
{
    private const PROJECT = 'peak-measure-ut';

    private string $dir = '';

    private string $log = '';

    private int $runs = 0;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-teardown-test-' . bin2hex(random_bytes(4));
        foreach (['fakebin', 'app/vendor', 'out'] as $d) {
            mkdir($this->dir . '/' . $d, 0777, true);
        }
        touch($this->dir . '/app/vendor/autoload.php');
        foreach (['static.json', 'capture.json'] as $file) {
            file_put_contents($this->dir . '/' . $file, '{}');
        }
        file_put_contents($this->dir . '/tasks.json', '[]');
        $this->log = $this->dir . '/docker.log';
        // Records every call. `volume ls` answers with FAKE_VOLUMES (one name per line), a `compose ... up` with FAKE_UP_EXIT.
        file_put_contents($this->dir . '/fakebin/docker', <<<'SH'
            #!/usr/bin/env bash
            printf '%s\n' "$*" >> "$FAKE_DOCKER_LOG"
            case "$*" in
              "volume ls"*) [ -n "${FAKE_VOLUMES:-}" ] && printf '%s\n' "$FAKE_VOLUMES"; exit 0 ;;
              *" up "*) exit "${FAKE_UP_EXIT:-0}" ;;
            esac
            exit 0
            SH);
        chmod($this->dir . '/fakebin/docker', 0755);

        // A wrong PATH would run the real docker. Make sure the scripts see the stand-in first.
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
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function bin(string $script, array $args, array $env = []): array
    {
        return $this->runProcess(['bash', self::runtimeDir() . '/bin/' . $script, ...$args], $this->env($env));
    }

    /** @return list<string> */
    private function calls(): array
    {
        return is_file($this->log) ? array_values(array_filter(explode("\n", (string) file_get_contents($this->log)))) : [];
    }

    /** @return list<string> the compose calls that take the stack down */
    private function downCalls(): array
    {
        return array_values(array_filter($this->calls(), static fn (string $c): bool => preg_match('/^compose .* down( |$)/', $c) === 1));
    }

    /** @return list<string> the compose calls that bring the stack up */
    private function upCalls(): array
    {
        return array_values(array_filter($this->calls(), static fn (string $c): bool => preg_match('/^compose .* up /', $c) === 1));
    }

    private function removesVolumes(string $call): bool
    {
        return preg_match('/(?<![-\w])-[A-Za-z]*v[A-Za-z]*(?=\s|$)|--volumes/', $call) === 1;
    }

    /**
     * The four scripts that measure, each with the smallest arguments that get it to `compose up`.
     *
     * @return array{list<string>, array<string, string>} arguments and extra environment
     */
    private function measuringArgs(string $script): array
    {
        $this->runs++;
        $d = $this->dir;
        $out = $d . '/rec-' . $this->runs;
        $label = 'l' . $this->runs;

        return match ($script) {
            'measure' => [['--app=' . $d . '/app', '--static=' . $d . '/static.json', '--out=' . $out], []],
            'capture' => [['--app=' . $d . '/app', '--static=' . $d . '/static.json', '--out=' . $out], []],
            'explain-at-scale' => [['--capture=' . $d . '/capture.json', '--tasks=' . $d . '/tasks.json', '--app=' . $d . '/app', '--scale=100', '--small-scale=10', '--out=' . $out, '--label=' . $label], []],
            'variance-run' => [['--app=' . $d . '/app', '--tasks=' . $d . '/tasks.json', '--scale=100', '--out=' . $out, '--label=' . $label, '--source-commit=abc', '--dbs=1', '--project-prefix=' . self::PROJECT], []],
            default => throw new \InvalidArgumentException($script),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function measuringScripts(): iterable
    {
        yield 'bin/measure' => ['measure'];
        yield 'bin/capture' => ['capture'];
        yield 'bin/explain-at-scale' => ['explain-at-scale'];
        yield 'bin/variance-run' => ['variance-run'];
    }

    // 1-3: bin/stack

    public function testStackDownKeepsTheDatabaseVolume(): void
    {
        [$code] = $this->bin('stack', ['down']);

        $this->assertSame(0, $code);
        $down = $this->downCalls();
        $this->assertCount(1, $down);
        $this->assertStringContainsString('down --remove-orphans', $down[0]);
        $this->assertFalse($this->removesVolumes($down[0]), 'no -v and no --volumes: ' . $down[0]);
    }

    public function testStackDownPurgeRemovesTheDatabaseVolume(): void
    {
        [$code] = $this->bin('stack', ['down', '--purge']);

        $this->assertSame(0, $code);
        $down = $this->downCalls();
        $this->assertCount(1, $down);
        $this->assertStringContainsString('--volumes', $down[0]);
        $this->assertStringContainsString('--remove-orphans', $down[0]);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function badDownArguments(): iterable
    {
        yield 'an unknown flag' => [['--bogus']];
        yield 'the short form of the old flag' => [['-v']];
        yield 'purge with something else' => [['--purge', '--keep']];
        yield 'a word' => [['purge']];
    }

    /** @param list<string> $args */
    #[DataProvider('badDownArguments')]
    public function testStackDownRefusesAnythingButPurge(array $args): void
    {
        [$code, , $err] = $this->bin('stack', ['down', ...$args]);

        $this->assertSame(2, $code, $err);
        $this->assertSame([], $this->calls(), 'compose is not called for a wrong usage');
    }

    // 4-6: the end of a run, on the failure path (the stack cannot be brought up) that used to remove the volume too

    #[DataProvider('measuringScripts')]
    public function testWhenTheStackCannotBeBroughtUpTheRunEndsWithoutRemovingTheVolume(string $script): void
    {
        [$args, $env] = $this->measuringArgs($script);
        [$code, , $err] = $this->bin($script, $args, $env + ['FAKE_UP_EXIT' => '1']);

        $this->assertSame(11, $code, $err);
        $down = $this->downCalls();
        $this->assertCount(1, $down, 'the containers and the network are taken down');
        $this->assertFalse($this->removesVolumes($down[0]), 'but not the volume: ' . $down[0]);
    }

    #[DataProvider('measuringScripts')]
    public function testWithPurgeTheEndOfTheRunRemovesTheVolume(string $script): void
    {
        [$args, $env] = $this->measuringArgs($script);
        [$code, , $err] = $this->bin($script, [...$args, '--purge'], $env + ['FAKE_UP_EXIT' => '1']);

        $this->assertSame(11, $code, $err);
        $down = $this->downCalls();
        $this->assertCount(1, $down);
        $this->assertStringContainsString('--volumes', $down[0]);
    }

    #[DataProvider('measuringScripts')]
    public function testWithKeepStackTheStackIsNotTakenDownAtAll(string $script): void
    {
        [$args, $env] = $this->measuringArgs($script);
        [$code, , $err] = $this->bin($script, [...$args, '--keep-stack'], $env + ['FAKE_UP_EXIT' => '1']);

        $this->assertSame(11, $code, $err);
        $this->assertSame([], $this->downCalls());
    }

    // 7: --purge and --keep-stack contradict each other

    #[DataProvider('measuringScripts')]
    public function testPurgeCannotBeCombinedWithKeepStack(string $script): void
    {
        [$args, $env] = $this->measuringArgs($script);
        foreach ([['--purge', '--keep-stack'], ['--keep-stack', '--purge']] as $flags) {
            [$code, , $err] = $this->bin($script, [...$args, ...$flags], $env);

            $this->assertSame(2, $code, $err);
            $this->assertStringContainsString('--purge', $err);
            $this->assertStringContainsString('--keep-stack', $err);
        }
        $this->assertSame([], $this->calls(), 'docker is not called');
    }

    // 8: a kept volume is not started on again

    public function testStackUpRefusesWhileTheVolumeOfThatProjectIsStillThere(): void
    {
        [$code, , $err] = $this->bin('stack', ['up'], ['FAKE_VOLUMES' => self::PROJECT . '_measure-dbdata']);

        $this->assertSame(2, $code);
        $this->assertSame([], $this->upCalls(), 'nothing is started on a kept database');
        $this->assertStringContainsString(self::PROJECT, $err);
        $this->assertStringContainsString('bin/stack down --purge', $err);
        $lookups = array_values(array_filter($this->calls(), static fn (string $c): bool => str_starts_with($c, 'volume ls')));
        $this->assertCount(1, $lookups);
        $this->assertStringContainsString('label=com.docker.compose.project=' . self::PROJECT, $lookups[0]);
        $this->assertStringContainsString('label=com.docker.compose.volume=measure-dbdata', $lookups[0]);
    }

    public function testStackUpStartsWhenNoVolumeIsLeft(): void
    {
        [$code] = $this->bin('stack', ['up']);

        $this->assertSame(0, $code);
        $this->assertCount(1, $this->upCalls());
    }

    #[DataProvider('measuringScripts')]
    public function testARunRefusedForAKeptVolumeStartsNothingAndRemovesNothing(string $script): void
    {
        [$args, $env] = $this->measuringArgs($script);
        // Even with --purge: the refusal comes first, so the volume it refused to build on is not removed by its own clean-up.
        [$code, , $err] = $this->bin($script, [...$args, '--purge'], $env + ['FAKE_VOLUMES' => self::PROJECT . '_measure-dbdata']);

        $this->assertSame(2, $code, $err);
        $this->assertSame([], $this->upCalls());
        $this->assertSame([], $this->downCalls(), 'a refused run must not take anything down');
        // bin/variance-run keeps each database's whole story in its own run.log, so that is where its refusal is written.
        $said = $err . implode("\n", array_map('file_get_contents', glob($this->dir . '/rec-*/*/db1/run.log') ?: []));
        $this->assertStringContainsString('bin/stack down --purge', $said);
    }

    // 9: nothing else removes a volume

    /**
     * The lines that may remove a volume, as "<path relative to the repository>: <the line, trimmed>". Everything else
     * that does would be a way to lose a database without saying so.
     *
     * - _stack.sh: stack_down(), the one place of the measuring tools, reached only through --purge.
     * - the lab's bin/down: its own --purge branch.
     * - the three scripts of the repository's own development and test stack (docker/compose.yaml): that stack holds
     *   only throwaway test data and never a measurement's database, and each tears down only its own project; they are
     *   named here so that a fourth one has to be added on purpose.
     */
    private const ALLOWED = [
        'poc/runtime/bin/_stack.sh: compose down --volumes --remove-orphans',
        'poc/lab/bin/down: --purge) compose down --remove-orphans -v ;;',
        'scripts/e2e-live.sh: dc down -v >/dev/null 2>&1 || true',
        'scripts/measure-timing.sh: dc down -v >/dev/null 2>&1 || true',
        'scripts/run-tests-logged.sh: dc down -v >/dev/null 2>&1 || true',
    ];

    private static function removesAVolume(string $line): bool
    {
        $short = '(?<![-\w])-[A-Za-z]*v[A-Za-z]*(?=\s|$)';
        $patterns = [
            '/\bdown\b.*(' . $short . '|--volumes)/',            // compose down -v, down --volumes
            '/\bvolume\s+(rm|remove|prune)\b/',                  // docker volume rm / prune
            '/\bprune\b/',                                       // system prune, container prune, ...
            '/--volumes\b/',                                     // system prune --volumes, rm --volumes
            '/(docker|compose|\bdc\b).*(?<![-\w])rm\s.*' . $short . '/', // docker rm -v, compose rm -v
            '/\bup\b.*((?<![-\w])-[A-Za-z]*V[A-Za-z]*(?=\s|$)|--renew-anon-volumes)/', // up -V
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return iterable<string, array{string, bool}> */
    public static function volumeRemovingForms(): iterable
    {
        yield 'down -v' => ['compose down -v --remove-orphans', true];
        yield 'down with -v last' => ['compose down --remove-orphans -v', true];
        yield 'down --volumes' => ['docker compose -p x down --volumes', true];
        yield 'dc down -v' => ['dc down -v >/dev/null 2>&1 || true', true];
        yield 'volume rm' => ['docker volume rm peak-x', true];
        yield 'volume prune' => ['docker volume prune -f', true];
        yield 'system prune' => ['docker system prune -af', true];
        yield 'system prune --volumes' => ['docker system prune --volumes', true];
        yield 'docker rm -v' => ['docker rm -v peak-x-db-1', true];
        yield 'compose rm -v' => ['docker compose rm -sfv', true];
        yield 'up -V' => ['compose up -d -V', true];
        yield 'up --renew-anon-volumes' => ['compose up --renew-anon-volumes', true];
        yield 'down without a volume flag' => ['compose down --remove-orphans', false];
        yield 'a bind mount' => ['docker run --rm -v "$runtime:/runtime:ro" image', false];
        yield 'a test of the command' => ['command -v docker >/dev/null 2>&1', false];
        yield 'an awk variable' => ['awk -v c="$c" -v r="$r" BEGIN', false];
        yield 'rm of a file' => ['rm -rf "$dir"', false];
    }

    #[DataProvider('volumeRemovingForms')]
    public function testTheWatchRecognisesTheFormsThatRemoveAVolume(string $line, bool $removes): void
    {
        $this->assertSame($removes, self::removesAVolume($line), $line);
    }

    public function testNoScriptRemovesAVolumeExceptTheListedOnes(): void
    {
        $repo = dirname(self::runtimeDir(), 2);
        $files = [];
        foreach (['poc/runtime/bin', 'poc/lab/bin', 'scripts'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($repo . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && !str_ends_with($file->getFilename(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }
        $this->assertNotSame([], $files);

        $found = [];
        foreach ($files as $path) {
            $relative = substr($path, strlen($repo) + 1);
            foreach (explode("\n", (string) file_get_contents($path)) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue; // a comment runs nothing
                }
                if (self::removesAVolume($line)) {
                    $found[] = $relative . ': ' . $line;
                }
            }
        }

        $this->assertSame([], array_values(array_diff($found, self::ALLOWED)), 'a script removes a volume without being on the list');
        $this->assertSame([], array_values(array_diff(self::ALLOWED, $found)), 'the list names a line that no longer exists: shorten it');
    }

    // 10: the volume is a named one

    /** @return iterable<string, array{string}> */
    public static function composeFiles(): iterable
    {
        yield 'sealed' => ['measure.compose.yaml'];
        yield 'leaky (test only)' => ['measure.leaky.compose.yaml'];
    }

    #[DataProvider('composeFiles')]
    public function testTheDatabaseKeepsItsDataInANamedVolume(string $file): void
    {
        $text = (string) file_get_contents(self::runtimeDir() . '/compose/' . $file);
        $lines = array_map('trim', explode("\n", $text));

        $this->assertContains('- measure-dbdata:/var/lib/mysql', $lines, 'measure-db mounts a named volume on its data directory');
        $this->assertSame(1, preg_match('/^volumes:\n  measure-dbdata: \{\}$/m', $text), 'the volume is declared at the top, so compose names it <project>_measure-dbdata');
        $mounts = array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, ':/var/lib/mysql')));
        $this->assertSame(['- measure-dbdata:/var/lib/mysql'], $mounts, 'nothing else is mounted there (a bind mount or an anonymous volume)');
    }
}
