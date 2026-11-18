<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Ladder;

use PeakSim\Runtime\Simulate\Ladder\LadderStage;
use PeakSim\Runtime\Simulate\Ladder\RouteAllocation;
use PeakSim\Runtime\Simulate\Load\TwoStage;
use PeakSim\Runtime\Simulate\Predict\AppLimitRisk;
use PeakSim\Runtime\Simulate\Predict\AppMemoryRisk;
use PeakSim\Runtime\Simulate\Predict\AppPlaceholderRisk;
use PeakSim\Runtime\Simulate\Predict\Coefficients;
use PeakSim\Runtime\Simulate\SimulateCommand;
use PeakSim\Runtime\Tests\Unit\Simulate\Load\Fixtures;
use PeakSim\Runtime\Tests\Unit\Simulate\Predict\PredictFixtures;
use PHPUnit\Framework\TestCase;

/**
 * Taking the routes that cannot run under B's settings out of a target's mix (simulator-spec.md section 7.4.4), on made-up routes and on the
 * made-up estimate of the first stage's tests. The cases: a route is taken out and its share goes to its group in proportion; both sides of the
 * mark take a route out; a group left empty stops; a request over half of the timeout stops (and exactly half does not); a route that could not be
 * judged stays; and without the flag nothing about the output changes.
 */
final class RouteAllocationTest extends TestCase
{
    private const PLACEHOLDER_LIMIT = 65535;

    /** A route whose one statement carries `$values` values at the table size (all of them in a list). @return array<string, mixed> */
    private static function route(string $name, float $rps, int $values = 10, ?float $peak = null): array
    {
        return PredictFixtures::route($name, $rps, 1.0, 10.0, null, [
            'peak_memory' => $peak,
            'peak_memory_status' => $peak === null ? 'not_given' : 'predicted',
            'types' => [['id' => 's1', 'kind' => 'select', 'pattern' => 'select 1', 'count' => 1.0, 'per_exec' => 1.0, 'per_exec_upper' => null, 'marks' => [], 'rows' => 1.0, 'rows_upper' => 1.0,
                'placeholders' => ['scale' => 1000, 'values' => $values, 'fixed' => 0, 'at' => 1000]]],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $routes
     *
     * @return array{scenario: array<string, mixed>, record: array<string, mixed>, emptied: list<list<string>>}
     */
    private static function allocate(array $routes, int $memoryLimit = 1000000): array
    {
        $scenario = ['id' => 's-x', 'kind' => 'target', 'routes' => $routes];

        return RouteAllocation::apply($scenario, AppPlaceholderRisk::evaluate($routes, self::PLACEHOLDER_LIMIT), AppMemoryRisk::evaluate($routes, $memoryLimit));
    }

    /** @param array<string, mixed> $made @return array<string, float> */
    private static function rps(array $made): array
    {
        return array_column($made['scenario']['routes'], 'rps', 'route');
    }

    public function testAMarkedRouteIsTakenOutAndItsShareGoesToTheOthersOfItsGroupOnly(): void
    {
        // an 80% group of four routes at 0.2 each and a 20% group of four at 0.05 each; the placeholder side marks A
        $routes = [
            self::route('A', 0.2, 70000), self::route('B', 0.2), self::route('C', 0.2), self::route('D', 0.2),
            self::route('E', 0.05), self::route('F', 0.05), self::route('G', 0.05), self::route('H', 0.05),
        ];
        $made = self::allocate($routes);

        $this->assertSame([], $made['emptied']);
        $rps = self::rps($made);
        $this->assertSame(['B', 'C', 'D', 'E', 'F', 'G', 'H'], array_keys($rps), 'A is out, the order of the rest is kept');
        foreach (['B', 'C', 'D'] as $name) {
            $this->assertEqualsWithDelta(0.8 / 3.0, $rps[$name], 1e-12, 'the 0.8 of the group stays with the three that are left');
        }
        foreach (['E', 'F', 'G', 'H'] as $name) {
            $this->assertSame(0.05, $rps[$name], 'the other group is not touched');
        }
        $this->assertEqualsWithDelta(1.0, array_sum($rps), 1e-12, 'the sum of rps is unchanged');
        $this->assertSame([['route' => 'A', 'reasons' => ['placeholders'], 'rps_in_file' => 0.2]], $made['record']['dropped_routes']);
        $this->assertSame(['B', 'C', 'D'], $made['record']['groups_changed'][0]['kept']);
        $this->assertEqualsWithDelta($made['record']['rps_total_in_file'], $made['record']['rps_total_after'], 1e-12);
    }

    public function testTheStayingRoutesKeepTheirProportionsToEachOther(): void
    {
        // the proportion is kept on the rps of the file; the routes of one group carry the same rps there, so the check is on the factor too
        $routes = [self::route('A', 0.25, 70000), self::route('B', 0.25), self::route('C', 0.25), self::route('D', 0.25)];
        $made = self::allocate($routes);

        $this->assertEqualsWithDelta(4.0 / 3.0, $made['record']['groups_changed'][0]['factor'], 1e-12);
        $rps = self::rps($made);
        $this->assertEqualsWithDelta($rps['B'], $rps['C'], 1e-15);
        $this->assertEqualsWithDelta(1.0, array_sum($rps), 1e-12);
    }

    public function testTheMemorySideTakesARouteOutToo_AndARouteBothSidesMarkSaysBoth(): void
    {
        // peak memory 1.51 x 700,000 = 1,057,000 >= 1,000,000 marks the memory side of B and of C; A is marked by the placeholders only
        $routes = [self::route('A', 0.25, 70000), self::route('B', 0.25, 10, 700000.0), self::route('C', 0.25, 70000, 700000.0), self::route('D', 0.25)];
        $made = self::allocate($routes);

        $reasons = array_column($made['record']['dropped_routes'], 'reasons', 'route');
        $this->assertSame(['A' => ['placeholders'], 'B' => ['memory'], 'C' => ['placeholders', 'memory']], $reasons);
        $this->assertSame(['D' => 1.0], self::rps($made), 'the one route left carries the whole group');
    }

    public function testAnEvenMixSharesTheDroppedRouteAmongAllTheOthers(): void
    {
        $routes = [];
        foreach (['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'] as $i => $name) {
            $routes[] = self::route($name, 0.125, $i === 3 ? 70000 : 10);
        }
        $made = self::allocate($routes);

        $this->assertCount(7, $made['scenario']['routes']);
        foreach (self::rps($made) as $value) {
            $this->assertEqualsWithDelta(1.0 / 7.0, $value, 1e-12);
        }
    }

    public function testAGroupLeftWithNoRouteIsReportedAndTheScenarioIsNotChanged(): void
    {
        $routes = [self::route('A', 0.4, 70000), self::route('B', 0.1), self::route('C', 0.1), self::route('D', 0.4, 70000)];
        $made = self::allocate($routes);

        $this->assertSame([['A', 'D']], $made['emptied']);
        $this->assertCount(4, $made['scenario']['routes']);
    }

    public function testARouteAloneAtItsRpsIsAGroupOfItsOwn(): void
    {
        $routes = [self::route('A', 0.6, 70000), self::route('B', 0.2), self::route('C', 0.2)];

        $this->assertSame([['A']], self::allocate($routes)['emptied']);
    }

    public function testARouteTheMarksCouldNotJudgeStays(): void
    {
        $routes = [self::route('A', 0.5), PredictFixtures::unknown('B', 0.5)];
        $made = self::allocate($routes);

        $this->assertSame([], $made['record']['dropped_routes']);
        $this->assertSame(['B'], $made['record']['not_judged']['placeholders']);
        $this->assertSame(['A', 'B'], $made['record']['not_judged']['memory'], 'neither has a peak-memory figure');
        $this->assertSame(['A' => 0.5, 'B' => 0.5], self::rps($made));
    }

    public function testNothingMarkedChangesNothing(): void
    {
        $routes = [self::route('A', 0.7), self::route('B', 0.3)];
        $made = self::allocate($routes);

        $this->assertSame($routes, $made['scenario']['routes']);
        $this->assertSame([], $made['record']['groups_changed']);
    }

    public function testThePlaceholderLimitItselfIsNotOverTheLimit(): void
    {
        // the mark is "more than the limit": exactly 65,535 values runs, 65,536 does not
        $routes = [self::route('A', 0.5, self::PLACEHOLDER_LIMIT), self::route('B', 0.25, self::PLACEHOLDER_LIMIT + 1), self::route('C', 0.25)];
        $made = self::allocate($routes);

        $this->assertSame(['B'], array_column($made['record']['dropped_routes'], 'route'));
        $this->assertSame(['A' => 0.5, 'C' => 0.5], self::rps($made));
    }

    public function testARequestOverHalfOfTheTimeoutIsSlowAndExactlyHalfIsNot(): void
    {
        $c = new Coefficients('test', 0.5, 0.01);
        $routes = [self::route('A', 0.5), self::route('B', 0.5)];
        $bound = AppLimitRisk::evaluate($routes, $c, 1.0)['routes'][0]['php_cpu_bound_s'];

        $this->assertSame([], RouteAllocation::slowRoutes($routes, $c, $bound), 'exactly the half passes');
        $slow = RouteAllocation::slowRoutes($routes, $c, $bound - 1e-9);
        $this->assertSame(['A', 'B'], array_column($slow, 'route'));
        $this->assertSame([], RouteAllocation::slowRoutes([PredictFixtures::unknown('U', 1.0)], $c, 0.001), 'a route with no cost cannot be judged');
    }

    /* the flag in the whole step, on the made-up estimate ------------------------------------------------------------------------------ */

    private const CALIBRATIONS = [
        ['cal-1', '{ "GET /a": 5.0, "POST /m": 5.0 }', '0.4515', '{ pages: 1000, books: 1000 }'],
        ['cal-2', '{ "GET /a": 8.0, "POST /m": 2.0 }', '0.4694', '{ pages: 1000, books: 1000 }'],
        ['cal-3', '{ "GET /a": 2.0, "POST /m": 8.0 }', '0.4256', '{ pages: 1000, books: 1000 }'],
        ['cal-4', '{ "GET /a": 5.0, "POST /m": 5.0 }', '0.194', '{ pages: 300, books: 300 }'],
    ];

    /** @param array<string, string> $targets id => rps map as YAML text; every target is at 3,000 rows */
    private static function yaml(array $targets, string $limits = "  app_memory_limit: 10000\n  db_placeholder_limit: 65535\n"): string
    {
        $y = "schema_version: \"0.1\"\nresources:\n  db_cpu:\n    cores: 2\n    threshold: 0.8\n" . $limits . "scenarios:\n";
        foreach (self::CALIBRATIONS as [$id, $rps, $observed, $tables]) {
            $y .= "  - id: {$id}\n    kind: calibration\n    tables: {$tables}\n    rps: {$rps}\n    observed: { db_cpu_utilization: {$observed} }\n";
        }
        foreach ($targets as $id => $rps) {
            $y .= "  - id: {$id}\n    kind: target\n    tables: { pages: 3000, books: 3000 }\n    rps: {$rps}\n";
        }

        return $y;
    }

    /** @return array{int, string, string, \PeakSim\Runtime\Tests\Unit\Simulate\Load\RecordingIo} */
    private static function runStep(string $yaml, ?float $timeout): array
    {
        $io = Fixtures::io($yaml);
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $next = new LadderStage('/est', Fixtures::SCENARIO_PATH, $out, $err, 'abc123', 'argument', $io, [], false, null, 1000, $timeout);
        $code = (new SimulateCommand($out, $err, $io, $next))->run(['--estimate=/est', '--scenario=' . Fixtures::SCENARIO_PATH, '--out=/out']);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err), $io];
    }

    /** @return array<string, mixed> */
    private static function report(string $yaml, ?float $timeout): array
    {
        [$code, , $stderr, $io] = self::runStep($yaml, $timeout);
        self::assertSame(0, $code, $stderr);

        return json_decode($io->files['/out/ladder-prediction.json'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testWithoutTheFlagTheOutputHasNoTraceOfTheAllocationAndIsTheSameWhateverTheLimits(): void
    {
        $targets = ['t-1' => '{ "GET /a": 4.0, "GET /axisless": 4.0, "POST /m": 2.0 }'];
        [, , , $plain] = self::runStep(self::yaml($targets, "  app_memory_limit: 10000\n  db_placeholder_limit: 65535\n"), null);
        $report = json_decode($plain->files['/out/ladder-prediction.json'], true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('allocation', $report);
        $this->assertArrayNotHasKey('allocation', $report['scenarios'][0]);
        $this->assertSame(['GET /a'], $report['scenarios'][0]['app_limit_risk_memory']['marked_routes'], 'the route is marked, and still in the mix');
        $this->assertArrayHasKey('GET /a', $report['scenarios'][0]['stages'][0]['rps']);
    }

    public function testWithTheFlagAMarkedRouteLeavesTheMixAndTheStagesAreThoseOfTheMixItLeaves(): void
    {
        $dropped = self::report(self::yaml(['t-1' => '{ "GET /a": 4.0, "GET /axisless": 4.0, "POST /m": 2.0 }']), 1800.0);
        // the same scenario written with the mix the allocation gives: GET /a out, GET /axisless at 8.0 (4.0 + 4.0)
        $written = self::report(self::yaml(['t-1' => '{ "GET /axisless": 8.0, "POST /m": 2.0 }']), null);

        $s = $dropped['scenarios'][0];
        $this->assertSame(['GET /a'], array_column($s['allocation']['dropped_routes'], 'route'));
        $this->assertSame(['memory'], $s['allocation']['dropped_routes'][0]['reasons']);
        $this->assertSame(['GET /axisless' => 8.0, 'POST /m' => 2.0], $s['allocation']['rps_after']);
        $this->assertEquals(['GET /a' => 4.0, 'GET /axisless' => 4.0, 'POST /m' => 2.0], $s['allocation']['rps_in_file'], 'the file is recorded as it was');
        $this->assertSame(['GET /axisless', 'POST /m'], array_keys($s['stages'][0]['rps']));
        $this->assertSame($written['scenarios'][0]['stages'], $s['stages'], 'every stage is the stage of the mix it leaves');
        $this->assertSame($written['scenarios'][0]['limit_rps'], $s['limit_rps']);
        $this->assertSame(['GET /a'], $s['app_limit_risk_memory']['marked_routes'], 'the marks are still shown for every route of the file');
        // the naive estimate depends on the sum of rps only, which is the same
        $plain = self::report(self::yaml(['t-1' => '{ "GET /a": 4.0, "GET /axisless": 4.0, "POST /m": 2.0 }']), null);
        $this->assertSame($plain['scenarios'][0]['limit_rps']['naive'], $s['limit_rps']['naive']);
        $this->assertSame(array_column($plain['scenarios'][0]['stages'], 'naive'), array_column($s['stages'], 'naive'));
        $this->assertEqualsWithDelta($plain['scenarios'][0]['rps_total_in_file'], $s['rps_total_in_file'], 1e-12);
        $this->assertSame(1800.0, $dropped['allocation']['request_timeout_s']);
        $this->assertSame(900.0, $dropped['allocation']['half_request_timeout_s']);
    }

    public function testWithTheFlagAndNothingMarkedTheOutputIsThePlainOneWithTheAllocationBlocksAdded(): void
    {
        $yaml = self::yaml(['t-1' => '{ "GET /a": 4.0, "GET /axisless": 4.0, "POST /m": 2.0 }'], "  app_memory_limit: 100000000\n  db_placeholder_limit: 65535\n");
        $plain = self::report($yaml, null);
        $flagged = self::report($yaml, 1800.0);

        $this->assertSame([], $flagged['scenarios'][0]['allocation']['dropped_routes']);
        unset($flagged['allocation'], $flagged['scenarios'][0]['allocation']);
        $this->assertSame($plain, $flagged);
    }

    public function testWithTheFlagAGroupLeftEmptyStopsWithNoPredictionAndExit38(): void
    {
        // GET /a is alone at 0.6 of the total and the memory side marks it
        [$code, , $stderr, $io] = self::runStep(self::yaml(['t-1' => '{ "GET /a": 6.0, "GET /axisless": 2.0, "POST /m": 2.0 }']), 1800.0);

        $this->assertSame(RouteAllocation::EXIT_EMPTY_GROUP, $code);
        $this->assertSame(38, $code);
        $this->assertStringContainsString('t-1: every route of the group (GET /a) is taken out', $stderr);
        $this->assertArrayNotHasKey('/out/ladder-prediction.json', $io->files);
    }

    public function testWithTheFlagARequestOverHalfOfTheTimeoutStopsWithExit39AndNoPrediction(): void
    {
        // the made-up routes take about 0.04 s of php CPU time or more per request; a timeout of 0.02 s makes the half 0.01 s
        $yaml = self::yaml(['t-1' => '{ "GET /a": 4.0, "GET /axisless": 4.0, "POST /m": 2.0 }']);
        [$code, , $stderr, $io] = self::runStep($yaml, 0.02);

        $this->assertSame(RouteAllocation::EXIT_SLOW_REQUEST, $code);
        $this->assertSame(39, $code);
        $this->assertStringContainsString('t-1: GET /a', $stderr, 'the route that is taken out is checked too');
        $this->assertArrayNotHasKey('/out/ladder-prediction.json', $io->files);
        // a timeout long enough passes
        [$ok] = self::runStep($yaml, 1800.0);
        $this->assertSame(0, $ok);
    }

    public function testWithTheFlagALimitItNeedsIsRequiredAndMissingOneStopsWithExit40(): void
    {
        $targets = ['t-1' => '{ "GET /a": 4.0, "GET /axisless": 4.0, "POST /m": 2.0 }'];
        foreach (["  app_memory_limit: 10000\n", "  db_placeholder_limit: 65535\n", ''] as $limits) {
            [$code, , $stderr, $io] = self::runStep(self::yaml($targets, $limits), 1800.0);
            $this->assertSame(40, $code, $limits);
            $this->assertArrayNotHasKey('/out/ladder-prediction.json', $io->files);
            $this->assertStringContainsString('the scenario file gives', $stderr);
        }
    }

    public function testTheLoadedInputIsNotTouchedByTheAllocation(): void
    {
        $io = Fixtures::io(self::yaml(['t-1' => '{ "GET /a": 4.0, "GET /axisless": 4.0, "POST /m": 2.0 }']));
        $loaded = (new TwoStage($io))->stageOne('/est', Fixtures::SCENARIO_PATH, '/out')->toArray();
        $before = json_encode($loaded, JSON_THROW_ON_ERROR);
        $target = array_values(array_filter($loaded['scenarios'], static fn (array $s): bool => $s['kind'] === 'target'))[0];
        RouteAllocation::apply($target, AppPlaceholderRisk::evaluate($target['routes'], 65535), AppMemoryRisk::evaluate($target['routes'], 10000));

        $this->assertSame($before, json_encode($loaded, JSON_THROW_ON_ERROR));
    }
}
