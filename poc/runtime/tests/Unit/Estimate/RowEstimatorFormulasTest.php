<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Estimate;

use PeakSim\Runtime\Estimate\RowEstimator;
use PHPUnit\Framework\TestCase;

/**
 * `--with-formulas`: the formulas behind the figures are added and nothing else changes. Made-up capture: one marked type whose upper side
 * is the ratio (measured 1,000 rows per run at 10,000; a full scan of 2,000 then 8,000 rows reads 2,002 then 8,002 by PlanReads, so the upper side is
 * 1,000 x 8,002 / 2,002 per run at N = 100,000), one unmarked type.
 */
final class RowEstimatorFormulasTest extends TestCase
{
    private const N = 100000;

    /** @return array<string, mixed> */
    private static function plan(int $rows): array
    {
        return ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 't', 'access_type' => 'ALL', 'rows_examined_per_scan' => $rows, 'rows_produced_per_join' => $rows, 'filtered' => '100.00']]];
    }

    /**
     * @param list<int|float> $perExec rows per run at 100 / 1,000 / 10,000
     *
     * @return array<string, mixed>
     */
    private static function captureType(string $id, float $a, float $b, float $k, array $perExec): array
    {
        $perScale = [];
        foreach ([100, 1000, 10000] as $i => $scale) {
            $perScale[(string) $scale] = ['count' => 10, 'texts' => 1, 'rows_sum' => $perExec[$i] * 10, 'rows_min' => 0, 'rows_max' => 0, 'unread' => 0];
        }

        return [
            'id' => $id, 'kind' => 'select', 'pattern' => 'select ' . $id,
            'representative' => ['scale' => 10000, 'sql' => 'select ' . $id, 'bindings' => []],
            'per_scale' => $perScale,
            'count_fit' => ['fit_status' => 'ok', 'a' => $a, 'b' => $b, 'k' => $k, 'sse' => 0.0, 'scales' => [100, 1000, 10000]],
        ];
    }

    /** @return array{array<string, mixed>, array<string, mixed>, array<string, mixed>} capture, explain, predictions */
    private static function fixture(): array
    {
        $capture = ['capture_version' => 1, 'app' => 'demo', 'scales' => [100, 1000, 10000], 'representative_scale' => 10000, 'routes' => [[
            'route' => 'GET /r', 'status' => 'captured',
            'statement_types' => [
                self::captureType('sM', 2.0, 0.0, 0.0, [1000, 1000, 1000]),
                self::captureType('sU', 0.0, 0.01, 1.0, [3, 3, 3]),
            ],
        ]]];
        $marks = static fn (bool $changes): array => ['plan_unstable' => false, 'plan_changes_at_scale' => $changes, 'explain_unavailable' => null, 'unavailable_states' => [], 'unstable_states' => []];
        $state = static fn (int $rows): array => ['summary' => [], 'raw' => self::plan($rows)];
        $explain = ['explain_version' => 1, 'scale' => self::N, 'small_scale' => 10000, 'routes' => [[
            'route' => 'GET /r',
            'statements' => [
                ['id' => 'sM', 'marks' => $marks(true), 'small_scale' => ['default' => $state(2000)], 'target_scale' => ['default' => $state(8000)]],
                ['id' => 'sU', 'marks' => $marks(false), 'small_scale' => ['default' => $state(5)], 'target_scale' => ['default' => $state(5)]],
            ],
        ]]];
        $predictions = ['schema_version' => '0.1', 'routes' => [
            ['route' => 'GET /r', 'axis' => 'N', 'table' => 'pages', 'quantities' => [
                'queries' => ['status' => 'predicted', 'value' => 1.0, 'a' => 1.0, 'b' => 0.0, 'k' => 0.0],
                'peak_memory' => ['status' => 'predicted', 'value' => 5.0, 'a' => 1000.0, 'b' => 2.0, 'k' => 1.0],
            ]],
            ['route' => 'GET /only-static', 'axis' => 'N', 'table' => 'books', 'quantities' => []],
        ]];

        return [$capture, $explain, $predictions];
    }

    public function test_without_the_flag_nothing_is_added(): void
    {
        [$capture, $explain, $predictions] = self::fixture();
        $plain = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions);
        $this->assertArrayNotHasKey('route_index', $plain);
        $this->assertArrayNotHasKey('capture_scales', $plain);
        $this->assertArrayNotHasKey('table', $plain['routes'][0]);
        $this->assertArrayNotHasKey('formula', $plain['routes'][0]['types'][0]);
        $this->assertSame($plain, RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, false));
    }

    public function test_the_flag_adds_the_formulas_and_leaves_every_other_figure_as_it_was(): void
    {
        [$capture, $explain, $predictions] = self::fixture();
        $plain = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions);
        $with = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, true);
        $stripped = $with;
        unset($stripped['capture_scales'], $stripped['route_index']);
        foreach ($stripped['routes'] as &$route) {
            unset($route['table'], $route['axis'], $route['peak_memory_formula']);
            foreach ($route['types'] as &$type) {
                unset($type['formula']);
            }
        }
        unset($route, $type);
        $this->assertSame($plain, $stripped);
    }

    public function test_the_formulas_are_the_ones_the_figures_came_from(): void
    {
        [$capture, $explain, $predictions] = self::fixture();
        $with = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, true);
        $route = $with['routes'][0];
        $this->assertSame('pages', $route['table']);
        $this->assertSame('N', $route['axis']);
        $this->assertSame(['a' => 1000.0, 'b' => 2.0, 'k' => 1.0], $route['peak_memory_formula']);
        [$sM, $sU] = $route['types'];

        // sM: count 2, rows per run 1,000 (constant), upper 1,000 x 8,002 / 2,002: the ratio is 8,002 / 2,002
        $upper = 1000.0 * 8002 / 2002;
        $this->assertSame(['a' => 2.0, 'b' => 0.0, 'k' => 0.0], $sM['formula']['count']);
        $this->assertEqualsWithDelta(1000.0, $sM['per_exec'], 1e-9);
        $this->assertEqualsWithDelta($upper, $sM['per_exec_upper'], 1e-9);
        $this->assertEqualsWithDelta($upper, $sM['formula']['upper']['at_n'], 1e-9);
        $this->assertEqualsWithDelta(1000.0, $sM['formula']['upper']['h1_at_n'], 1e-9);
        $this->assertEqualsWithDelta(8002.0 / 2002.0, $sM['formula']['upper']['ratio'], 1e-9);
        $this->assertSame([100, 1000, 10000], $sM['formula']['scales']);
        // sU: count 0.01 x N, rows per run 3, no upper side
        $this->assertSame(['a' => 0.0, 'b' => 0.01, 'k' => 1.0], $sU['formula']['count']);
        $this->assertNull($sU['formula']['upper']);
        $this->assertSame('fit', $sU['formula']['per_exec']['basis']);
    }

    public function test_the_route_index_lists_every_route_of_the_static_analysis_and_every_route_of_the_estimate(): void
    {
        [$capture, $explain, $predictions] = self::fixture();
        $with = RowEstimator::estimate($capture, $explain, RowEstimator::H1_RANGE, self::N, $predictions, true);
        $this->assertSame([100, 1000, 10000], $with['capture_scales']);
        $this->assertSame([
            ['route' => 'GET /only-static', 'axis' => 'N', 'table' => 'books', 'in_estimate' => false],
            ['route' => 'GET /r', 'axis' => 'N', 'table' => 'pages', 'in_estimate' => true],
        ], $with['route_index']);
    }
}
