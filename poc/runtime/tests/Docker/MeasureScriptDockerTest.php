<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

/**
 * bin/measure, the host-side entry point, against real Docker: it brings a sealed stack up, runs the driver inside,
 * tears the stack down; and it refuses (exit 11, nothing measured) when the container could leave the network.
 *
 * run.sh mounts the repository and the run directory at their host paths too, so the paths given to bin/measure are
 * valid both for this process and for the Docker daemon.
 */
final class MeasureScriptDockerTest extends StackTestCase
{
    private static string $repo = '';

    private static string $work = '';

    public static function setUpBeforeClass(): void
    {
        self::requireDocker();
        static::$stackEnv = []; // the base class keeps one environment for all its tests; start from none
        self::$repo = (string) getenv('PEAK_REPO_ROOT');
        self::$work = rtrim((string) getenv('PEAK_OUT_DIR'), '/') . '/measure-script-' . self::randomHex(3);
        mkdir(self::$work, 0775, true);
    }

    public static function tearDownAfterClass(): void
    {
        if (!str_contains(basename(self::$work), 'measure-script-') || !is_dir(self::$work)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$work, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            ($item->isDir() && !$item->isLink()) ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir(self::$work);
    }

    /** @return list<string> */
    private static function analysis(): array
    {
        $file = self::$work . '/analysis.json';
        if (!is_file($file)) {
            // The static analysis comes from the repository's own tool, run here (no app code is run).
            [$code, $out, $err] = self::exec([PHP_BINARY, self::$repo . '/bin/peak-sim', 'analyze', self::$repo . '/fixtures/laravel-app', '--export-dir=' . self::$repo . '/fixtures/artisan-export', '--format=json']);
            self::assertSame(0, $code, $err);
            file_put_contents($file, $out);
        }

        return ['--app=' . self::$repo . '/fixtures/laravel-app', '--static=' . $file];
    }

    public function testItMeasuresInsideTheSealedStackAndTearsItDown(): void
    {
        $project = self::devProject(((string) getenv('PEAK_COMPOSE_PROJECT') ?: self::DEV_PROJECT_PREFIX) . '-script');
        $out = self::$work . '/run';
        $result = self::exec(
            ['bash', self::runtimeDir() . '/bin/measure', ...self::analysis(), '--out=' . $out, '--data=seeder', '--scales=5,10,20', '--only=/ok/eager,/ng/n-plus-one', '--purge'],
            ['PEAK_COMPOSE_PROJECT' => $project, 'PEAK_OUT_DIR' => $out, 'PEAK_COMPOSE_FILE' => self::runtimeDir() . '/compose/measure.compose.yaml'],
        );
        self::evidence('measure', 'bin-measure-run.txt', self::transcript('bin/measure --data=seeder --scales=5,10,20 --only=/ok/eager,/ng/n-plus-one --purge', [$result[0], substr($result[1], -3000), substr($result[2], -1500)]));

        $this->assertSame(0, $result[0], $result[1] . $result[2]);
        $this->assertStringContainsString('PREFLIGHT OK', $result[1]);
        $this->assertStringContainsString('measured_O1', $result[1]);
        $routes = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($out . '/routes.jsonl'))) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $routes[$row['route']] = $row['verdict'];
        }
        $this->assertSame(['/ng/n-plus-one' => 'measured_ON', '/ok/eager' => 'measured_O1'], $routes);

        [, $left] = self::exec(['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project=' . $project]);
        $this->assertSame('', trim($left), 'the stack is torn down after the run');
        $this->assertSame('', self::volumesOf($project), '--purge removes the database volume');
    }

    /** The names of the volumes that belong to a compose project, one per line. */
    private static function volumesOf(string $project): string
    {
        [, $volumes] = self::exec(['docker', 'volume', 'ls', '-q', '--filter', 'label=com.docker.compose.project=' . $project]);

        return trim($volumes);
    }

    public function testWithoutPurgeTheDatabaseVolumeStaysAndOnlyPurgeRemovesIt(): void
    {
        $project = self::devProject(((string) getenv('PEAK_COMPOSE_PROJECT') ?: self::DEV_PROJECT_PREFIX) . '-script-keep');
        $out = self::$work . '/keep';
        $env = ['PEAK_COMPOSE_PROJECT' => $project, 'PEAK_OUT_DIR' => $out, 'PEAK_COMPOSE_FILE' => self::runtimeDir() . '/compose/measure.compose.yaml'];
        try {
            $result = self::exec(
                ['bash', self::runtimeDir() . '/bin/measure', ...self::analysis(), '--out=' . $out, '--data=seeder', '--scales=5,10,20', '--only=/ok/eager'],
                $env,
            );
            self::evidence('measure', 'bin-measure-keeps-volume.txt', self::transcript('bin/measure (no --purge)', [$result[0], substr($result[1], -1500), substr($result[2], -1500)]));
            $this->assertSame(0, $result[0], $result[1] . $result[2]);

            [, $left] = self::exec(['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project=' . $project]);
            $this->assertSame('', trim($left), 'the containers are removed');
            $this->assertSame($project . '_measure-dbdata', self::volumesOf($project), 'the database volume is kept, under a name that tells its project');

            $again = self::exec(['bash', self::runtimeDir() . '/bin/measure', ...self::analysis(), '--out=' . $out . '-again', '--data=seeder', '--scales=5,10,20', '--only=/ok/eager'], $env);
            $this->assertSame(2, $again[0], 'a project whose volume is still there is not started again');
            $this->assertStringContainsString('--purge', $again[2]);
            $this->assertSame($project . '_measure-dbdata', self::volumesOf($project), 'the refused run left the volume alone');
        } finally {
            self::devProject($project);
            self::exec(['bash', self::runtimeDir() . '/bin/stack', 'down', '--purge'], $env);
        }
        $this->assertSame('', self::volumesOf($project), 'bin/stack down --purge removes the volume');
    }

    public function testItRefusesToMeasureWhenTheContainerCanLeaveTheNetwork(): void
    {
        $project = self::devProject(((string) getenv('PEAK_COMPOSE_PROJECT') ?: self::DEV_PROJECT_PREFIX) . '-leakyrun');
        $out = self::$work . '/leaky';
        $result = self::exec(
            ['bash', self::runtimeDir() . '/bin/measure', ...self::analysis(), '--out=' . $out, '--data=seeder', '--scales=5,10,20', '--only=/ok/eager', '--purge'],
            ['PEAK_COMPOSE_PROJECT' => $project, 'PEAK_OUT_DIR' => $out, 'PEAK_COMPOSE_FILE' => self::runtimeDir() . '/compose/measure.leaky.compose.yaml'],
        );
        self::evidence('measure', 'bin-measure-refused-leaky.txt', self::transcript('bin/measure (leaky stack)', [$result[0], substr($result[1], -3000), substr($result[2], -1500)]));

        $this->assertSame(11, $result[0], $result[1] . $result[2]);
        $this->assertStringContainsString('PREFLIGHT FAIL', $result[1] . $result[2]);
        $this->assertStringContainsString('nothing was measured', $result[2]);
        $this->assertFileDoesNotExist($out . '/routes.jsonl', 'nothing was measured');

        [, $left] = self::exec(['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project=' . $project]);
        $this->assertSame('', trim($left), 'the leaky stack is torn down too');
    }
}
