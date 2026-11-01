<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker;

use PeakSim\Runtime\Tests\Docker\Support\DockerCase;
use PeakSim\Runtime\Tests\Docker\Support\ProbeApp;
use PHPUnit\Framework\Attributes\Group;

/**
 * The whole driver (bin/inner/measure.php) inside the sealed container, against the verification app and a scratch
 * copy with a few extra routes: databases built and dropped per scale, the empty system and the second sweep
 * measured, rollbacks that fail repaired by a rebuild, the record written.
 */
#[Group('in-stack')]
final class MeasureDriverDockerTest extends DockerCase
{
    private const SCALES = '5,10,20';

    private static string $analysis = '';

    /** @var list<string> */
    private static array $dirs = [];

    public static function setUpBeforeClass(): void
    {
        self::requireInsideStack();
        // The static analysis of the verification app, made inside the container from the exports beside it.
        [$exit, $out, $err] = self::php('/peak-sim/bin/peak-sim', ['analyze', '/app', '--export-dir=/peak-sim/fixtures/artisan-export', '--format=json']);
        self::assertSame(0, $exit, $err);
        self::$analysis = self::scratch('analysis') . '/analysis.json';
        file_put_contents(self::$analysis, $out);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (self::$dirs as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private static function scratch(string $name): string
    {
        $dir = sys_get_temp_dir() . '/peak-driver-test-' . $name . '-' . bin2hex(random_bytes(3));
        mkdir($dir, 0775, true);
        self::$dirs[] = $dir;

        return $dir;
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{int, string, string, string} [exit, stdout, stderr, output dir]
     */
    private static function drive(array $args, array $env = []): array
    {
        $out = self::scratch('out');
        [$exit, $stdout, $stderr] = self::php(self::RUNTIME . '/bin/inner/measure.php', $args, $env + [
            'PEAK_MEASURE_STATIC_JSON' => self::$analysis,
            'PEAK_MEASURE_SCALES' => self::SCALES,
            'PEAK_MEASURE_OUT_DIR' => $out,
        ]);

        return [$exit, $stdout, $stderr, $out];
    }

    /** @return array<string, array<string, mixed>> routes.jsonl keyed by route */
    private static function routes(string $dir): array
    {
        $rows = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($dir . '/routes.jsonl'))) as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $rows[$row['route']] = $row;
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private static function attempts(string $dir): array
    {
        return array_map(
            static fn (string $l): array => json_decode($l, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents($dir . '/attempts.jsonl')))),
        );
    }

    private static function leftovers(): array
    {
        $prefix = 'peak_sim_measure_' . self::config()->runId . '_';

        return array_values(array_filter(self::db()->listMeasureDatabases(), static fn (string $n): bool => str_starts_with($n, $prefix)));
    }

    public function testTheVerificationAppWithItsOwnSeeder(): void
    {
        [$exit, $stdout, $stderr, $dir] = self::drive([
            '--data=seeder',
            '--only=/ok/eager,/ng/n-plus-one,/ng/nested,/ng/save-in-loop,/hidden/cache,/unknown/dynamic',
            '--expected=/peak-sim/fixtures/expected.json',
        ]);

        self::assertSame(0, $exit, $stdout . $stderr);
        $routes = self::routes($dir);
        self::assertCount(6, $routes);
        self::assertSame('measured_O1', $routes['/ok/eager']['verdict']);
        self::assertSame('measured_ON', $routes['/ng/n-plus-one']['verdict']);
        self::assertSame('measured_ONM', $routes['/ng/nested']['verdict']);
        self::assertSame('measured_ON', $routes['/ng/save-in-loop']['verdict']);
        self::assertSame('measured_ON', $routes['/hidden/cache']['verdict'], 'the cold attempt is the main value');
        self::assertSame('measured_O1', $routes['/unknown/dynamic']['verdict']);
        self::assertSame('global', $routes['/unknown/dynamic']['scope'], 'no candidate table: the whole database is scaled');
        self::assertSame('runtime_only', $routes['/unknown/dynamic']['relation']);
        foreach (['/ok/eager', '/ng/n-plus-one', '/ng/nested', '/ng/save-in-loop', '/hidden/cache'] as $route) {
            self::assertSame('agree', $routes[$route]['relation'], $route);
        }

        $attempts = self::attempts($dir);
        $zero = array_filter($attempts, static fn (array $a): bool => $a['scale'] === 0 && $a['axis'] === 'N');
        self::assertNotEmpty($zero, 'the empty system is measured on the N axis');
        $m = array_filter($attempts, static fn (array $a): bool => $a['axis'] === 'M');
        self::assertSame([3, 30, 300], array_values(array_unique(array_column($m, 'scale'))));
        self::assertSame(['/ng/nested'], array_values(array_unique(array_column($m, 'route'))));
        $warm = array_values(array_filter($attempts, static fn (array $a): bool => $a['route'] === '/hidden/cache' && $a['attempt'] === 3 && $a['axis'] === 'N'));
        self::assertSame([0, 0, 0], array_column($warm, 'queries'), 'the warm attempt hits the cache');

        $comparison = json_decode((string) file_get_contents($dir . '/comparison.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(24, $comparison, 'one row per expected route; the routes not asked for are not_measured');
        self::assertSame([], self::leftovers(), 'every database the run made is dropped');
        $summary = json_decode((string) file_get_contents($dir . '/summary.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('seeder', $summary['meta']['data']);
        $ord20 = array_values(array_filter($summary['builds'], static fn (array $b): bool => $b['point'] === 'ord:20'))[0];
        self::assertSame(['customers' => 20, 'items' => 60, 'orders' => 20, 'products' => 20], array_intersect_key($ord20['rows'], array_flip(['customers', 'items', 'orders', 'products'])));
    }

    public function testTheVerificationAppWithTheGenerator(): void
    {
        [$exit, $stdout, $stderr, $dir] = self::drive(['--data=generator', '--only=/ok/eager,/ng/n-plus-one']);

        self::assertSame(0, $exit, $stdout . $stderr);
        $routes = self::routes($dir);
        self::assertSame('measured_O1', $routes['/ok/eager']['verdict']);
        self::assertSame('measured_ON', $routes['/ng/n-plus-one']['verdict']);
        self::assertSame('targeted:orders', $routes['/ng/n-plus-one']['scope']);
        $summary = json_decode((string) file_get_contents($dir . '/summary.json'), true, 512, JSON_THROW_ON_ERROR);
        $generated = array_filter($summary['builds'], static fn (array $b): bool => isset($b['generation']));
        self::assertNotEmpty($generated);
        foreach ($generated as $build) {
            self::assertSame(0, $build['generation']['failed'], $build['point'] . ' ' . json_encode($build['generation']['failed_tables']));
        }
        self::assertSame([], self::leftovers());
    }

    public function testAProfileMakesTheFilterRouteSeeItsDataAndIsNamedInTheRecord(): void
    {
        // /ng/collection-filter keeps the orders with status 'paid'; the generator alone writes a placeholder there.
        [$exit, $stdout, $stderr, $plain] = self::drive(['--data=generator', '--only=/ng/collection-filter']);
        self::assertSame(0, $exit, $stdout . $stderr);
        self::assertSame('no_info', self::routes($plain)['/ng/collection-filter']['verdict'], 'without the profile the filter sees no data');
        self::assertStringContainsString("\nprofile: none\n", (string) file_get_contents($plain . '/report.md'));

        [$exit, $stdout, $stderr, $dir] = self::drive(['--data=generator', '--profile=fixtures', '--only=/ng/collection-filter']);
        self::assertSame(0, $exit, $stdout . $stderr);
        $row = self::routes($dir)['/ng/collection-filter'];
        self::assertSame('measured_O1', $row['verdict']);
        self::assertStringEndsWith(' (with profile: fixtures)', $row['statement']);
        self::assertStringContainsString("\nprofile: fixtures\n", (string) file_get_contents($dir . '/report.md'));
        $summary = json_decode((string) file_get_contents($dir . '/summary.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('fixtures', $summary['meta']['profile']);
        self::assertSame([], self::leftovers());
    }

    public function testARouteThatChangesTheDatabaseIsRecordedAndTheDatabaseRebuilt(): void
    {
        $probe = ProbeApp::create('/app', 'driver');
        try {
            $static = self::scratch('static') . '/static.json';
            file_put_contents($static, json_encode(['routes' => [
                self::staticRoute('/probe/ddl', 'POST'),
                self::staticRoute('/probe/commit', 'POST'),
                self::staticRoute('/probe/boom', 'GET'),
                self::staticRoute('/probe/missing', 'GET'),
                self::staticRoute('/probe/forbidden', 'GET'),
                self::staticRoute('/probe/auth-only', 'GET'),
                self::staticRoute('/ok/eager', 'GET', 'App\\Http\\Controllers\\Ok\\OrderController@eager', 'orders'),
            ]], JSON_THROW_ON_ERROR));
            [$exit, $stdout, $stderr, $dir] = self::drive(['--data=seeder'], ['PEAK_MEASURE_APP_ROOT' => $probe->root, 'PEAK_MEASURE_STATIC_JSON' => $static]);
        } finally {
            $probe->remove();
        }

        self::assertSame(0, $exit, $stdout . $stderr);
        $routes = self::routes($dir);
        self::assertSame('not_rollbackable', $routes['/probe/ddl']['class']);
        self::assertSame('not_rollbackable', $routes['/probe/commit']['class']);
        self::assertSame('not_measured', $routes['/probe/ddl']['verdict']);
        self::assertSame('exception', $routes['/probe/boom']['class']);
        self::assertSame('not_found', $routes['/probe/missing']['class']);
        self::assertSame('forbidden', $routes['/probe/forbidden']['class']);
        self::assertSame('unauthenticated', $routes['/probe/auth-only']['class']);
        self::assertSame('measured_O1', $routes['/ok/eager']['verdict'], 'the route after the two that changed the database is measured on a clean one');

        $attempts = self::attempts($dir);
        $bypassed = array_filter($attempts, static fn (array $a): bool => $a['route'] === '/probe/forbidden' && $a['gate_bypass']);
        self::assertNotEmpty($bypassed, 'a 403 gets one more try with the gate bypassed');
        $summary = json_decode((string) file_get_contents($dir . '/summary.json'), true, 512, JSON_THROW_ON_ERROR);
        $rebuilt = array_filter($summary['builds'], static fn (array $b): bool => isset($b['rebuilt_after']));
        self::assertNotEmpty($rebuilt, 'a database a request changed is dropped and built again');
        self::assertSame([], self::leftovers());
    }

    public function testAnAppThatIsNotThereStopsWithTheCodeOfTheFailure(): void
    {
        [$exit, , $stderr] = self::drive(['--data=seeder', '--only=/ok/eager'], ['PEAK_MEASURE_APP_ROOT' => '/nonexistent']);

        self::assertSame(13, $exit, 'the inspection database cannot be built without an app');
        self::assertStringContainsString('could not build the inspection database', $stderr);
        self::assertSame([], self::leftovers());
    }

    public function testADatabaseTagFromOutsideIsRefused(): void
    {
        [$exit, , $stderr] = self::drive(['--data=seeder'], ['PEAK_MEASURE_DB_TAG' => 'orders']);

        self::assertSame(10, $exit);
        self::assertStringContainsString('refused', $stderr);
    }

    public function testAWrongOptionIsAUsageError(): void
    {
        [$exit] = self::drive(['--data=dump']);

        self::assertSame(2, $exit);
    }

    /** @return array<string, mixed> */
    private static function staticRoute(string $uri, string $method, string $action = 'Closure', ?string $nTable = null): array
    {
        return [
            'route' => $uri, 'methods' => $method === 'GET' ? ['GET', 'HEAD'] : [$method], 'action' => $action, 'kind' => 'controller',
            'query' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'memory' => ['growth' => 'unknown', 'lower_bound' => null, 'confidence' => null],
            'n_table' => $nTable, 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => [],
        ];
    }
}
