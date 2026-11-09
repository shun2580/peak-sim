<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Simulate\Load;

use PeakSim\Runtime\Simulate\Load\InputLoader;
use PeakSim\Runtime\Simulate\Load\LoadFailure;
use PHPUnit\Framework\TestCase;

/** The first stage on the made-up estimate and scenario of {@see Fixtures} (figures worked out by hand in {@see EstimateFormulasTest}). */
final class InputLoaderTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function loaded(?string $scenario = null, ?string $estimate = null): array
    {
        return (new InputLoader(Fixtures::io($scenario, $estimate)))->load('/est', Fixtures::SCENARIO_PATH)->toArray();
    }

    public function test_each_scenario_puts_its_own_table_sizes_into_the_formulas(): void
    {
        $l = self::loaded();
        $this->assertSame('0.1', $l['schema_version']);
        $this->assertSame('demo', $l['app']);
        $this->assertSame(200000, $l['estimate_at']);
        [$calibration, $target] = $l['scenarios'];

        // calibration: pages 1000, books 300. GET /a at 1000: queries 12, rows 250. POST /m at 300: queries 5, rows 307, upper 1,627
        $this->assertSame(['GET /a', 'POST /m'], array_column($calibration['routes'], 'route'));
        [$a, $m] = $calibration['routes'];
        $this->assertSame(5.0, $a['rps']);
        $this->assertSame('pages', $a['table']);
        $this->assertSame(1000, $a['n']);
        $this->assertEqualsWithDelta(12.0, $a['queries'], 1e-9);
        $this->assertEqualsWithDelta(250.0, $a['rows'], 1e-9);
        $this->assertSame(300, $m['n']);
        $this->assertEqualsWithDelta(307.0, $m['rows'], 1e-9);
        $this->assertEqualsWithDelta(1627.0, $m['rows_upper'], 1e-9);

        // target: pages 5000 and no books. GET /a at 5000: queries 52, rows 1170 (not the figures of the calibration scenario)
        $this->assertSame(['GET /a', 'GET /inc', 'POST /m'], array_column($target['routes'], 'route'));
        [$ta, $tinc, $tm] = $target['routes'];
        $this->assertSame(5000, $ta['n']);
        $this->assertEqualsWithDelta(52.0, $ta['queries'], 1e-9);
        $this->assertEqualsWithDelta(1170.0, $ta['rows'], 1e-9);
        $this->assertSame('interpolated', $ta['n_mark']);
        $this->assertSame('unknown', $tinc['cost']);
        $this->assertSame('incomplete:count_formula:insufficient_scales', $tinc['unknown_reason']);
        $this->assertSame('unknown', $tm['cost']);
        $this->assertSame('table_count_missing:books', $tm['unknown_reason']);
        $this->assertNull($tm['n']);
        $this->assertNull($tm['rows']);
        $this->assertSame(['books'], $target['unknown_tables'], 'books is needed by POST /m and not given');
    }

    public function test_the_routes_known_with_no_rps_are_listed_and_only_routes_with_rps_are_carried(): void
    {
        $l = self::loaded();
        $this->assertSame(['GET /axisless', 'GET /inc', 'GET /nopred'], $l['scenarios'][0]['zero_rps_routes']);
        $this->assertSame(['GET /axisless', 'GET /nopred'], $l['scenarios'][1]['zero_rps_routes']);
        $yaml = str_replace('"GET /a": 5.0, "POST /m": 1.0', '"GET /a": 5.0, "POST /m": 0', Fixtures::scenarioYaml());
        $this->assertSame(['GET /a'], array_column(self::loaded($yaml)['scenarios'][0]['routes'], 'route'));
        $this->assertContains('POST /m', self::loaded($yaml)['scenarios'][0]['zero_rps_routes'], 'an rps written as 0 is a route with rps 0');
    }

    public function test_the_target_carries_no_observed_and_the_calibration_does(): void
    {
        $l = self::loaded();
        $this->assertSame(['db_cpu_utilization' => 0.21], $l['scenarios'][0]['observed']);
        $this->assertArrayNotHasKey('observed', $l['scenarios'][1]);
        $this->assertStringNotContainsString(Fixtures::TARGET_OBSERVED, json_encode($l, JSON_THROW_ON_ERROR));
    }

    public function test_a_route_with_no_axis_table_is_unknown_not_zero(): void
    {
        $yaml = str_replace('"GET /a": 5.0, "POST /m": 1.0', '"GET /a": 5.0, "GET /axisless": 1', Fixtures::scenarioYaml());
        $routes = self::loaded($yaml)['scenarios'][0]['routes'];
        $axisless = $routes[1];
        $this->assertSame('GET /axisless', $axisless['route']);
        $this->assertSame('unknown', $axisless['cost']);
        $this->assertSame('no_axis_table', $axisless['unknown_reason']);
        $this->assertNull($axisless['queries']);
        $this->assertSame([], self::loaded($yaml)['scenarios'][0]['unknown_tables'], 'no table is missing: the route has none');
    }

    public function test_a_route_the_static_analysis_knows_without_an_estimate_is_unknown_and_its_table_is_listed_when_missing(): void
    {
        $yaml = str_replace('tables: { pages: 1000, books: 300 }', 'tables: { books: 300 }', str_replace('"POST /m": 1.0', '"GET /nopred": 1.0', Fixtures::scenarioYaml()));
        $c = self::loaded($yaml)['scenarios'][0];
        $this->assertSame(['pages'], $c['unknown_tables']);
        $this->assertSame('GET /a', $c['routes'][0]['route']);
        $this->assertSame('table_count_missing:pages', $c['routes'][0]['unknown_reason']);
        $this->assertSame('GET /nopred', $c['routes'][1]['route']);
        $this->assertSame('unknown', $c['routes'][1]['cost']);
    }

    public function test_a_route_neither_the_estimate_nor_the_static_analysis_has_stops_with_22_and_names_it(): void
    {
        $yaml = str_replace('"GET /a": 5.0', '"GET /a": 5.0, "GET /nowhere": 2', Fixtures::scenarioYaml());
        try {
            self::loaded($yaml);
            $this->fail('the reading should have stopped');
        } catch (LoadFailure $e) {
            $this->assertSame(22, $e->exitCode);
            $this->assertStringContainsString('GET /nowhere', $e->getMessage());
            $this->assertStringContainsString('normal-1', $e->getMessage());
        }
    }

    public function test_an_unreadable_scenario_stops_with_20_and_an_unreadable_estimate_with_25(): void
    {
        try {
            (new InputLoader(Fixtures::io()))->load('/est', '/in/missing.yaml');
            $this->fail('should have stopped');
        } catch (LoadFailure $e) {
            $this->assertSame(20, $e->exitCode);
        }
        try {
            (new InputLoader(Fixtures::io()))->load('/no-est', Fixtures::SCENARIO_PATH);
            $this->fail('should have stopped');
        } catch (LoadFailure $e) {
            $this->assertSame(25, $e->exitCode);
        }
        try {
            self::loaded(null, '{not json');
            $this->fail('should have stopped');
        } catch (LoadFailure $e) {
            $this->assertSame(25, $e->exitCode);
        }
    }

    public function test_the_same_input_gives_the_same_bytes(): void
    {
        $a = (new InputLoader(Fixtures::io()))->load('/est', Fixtures::SCENARIO_PATH)->toJson();
        $b = (new InputLoader(Fixtures::io()))->load('/est', Fixtures::SCENARIO_PATH)->toJson();
        $this->assertSame($a, $b);
    }
}
