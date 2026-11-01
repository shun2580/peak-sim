<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Guard\DbGuard;
use PeakSim\Runtime\Measure\SeederPlanner;
use PeakSim\Runtime\Measure\Sweep;

final class SeederPlannerTest extends PlannerTestCase
{
    private const SCALES = [100, 1000, 10000];

    /** @return list<int> */
    private static function scalesOf(Sweep $sweep): array
    {
        return array_map(static fn ($p) => $p->scale, $sweep->points);
    }

    public function test_an_orders_route_scales_the_orders_and_measures_an_empty_system_first(): void
    {
        $sweeps = (new SeederPlanner())->sweepsFor(self::spec(), self::SCALES);

        $this->assertCount(1, $sweeps);
        $this->assertSame('N', $sweeps[0]->axis);
        $this->assertSame([0, 100, 1000, 10000], self::scalesOf($sweeps[0]));
        $this->assertSame(['customers' => 20, 'products' => 20, 'orders' => 1000, 'items' => 3], $sweeps[0]->points[2]->recipe);
        $this->assertSame(['customers' => 20, 'products' => 20, 'orders' => 0, 'items' => 3], $sweeps[0]->points[0]->recipe);
    }

    public function test_a_customers_route_scales_the_customers_and_keeps_no_orders(): void
    {
        $sweeps = (new SeederPlanner())->sweepsFor(self::spec('/ng/save-in-loop', ['n_table' => 'customers']), self::SCALES);

        $this->assertSame(['customers' => 100, 'products' => 20, 'orders' => 0, 'items' => 3], $sweeps[0]->points[1]->recipe);
        $this->assertNotSame($sweeps[0]->points[1]->tag, (new SeederPlanner())->sweepsFor(self::spec(), self::SCALES)[0]->points[1]->tag);
    }

    public function test_a_products_route_scales_the_products(): void
    {
        $sweeps = (new SeederPlanner())->sweepsFor(self::spec('/ng/first-or-create-in-loop', ['n_table' => 'products']), self::SCALES);

        $this->assertSame(['customers' => 1, 'products' => 100, 'orders' => 0, 'items' => 3], $sweeps[0]->points[1]->recipe);
    }

    public function test_a_route_without_a_candidate_table_scales_every_table_together(): void
    {
        $sweeps = (new SeederPlanner())->sweepsFor(self::spec('/unknown/dynamic', ['n_table' => null]), self::SCALES);

        $this->assertSame(['customers' => 100, 'products' => 100, 'orders' => 100, 'items' => 3], $sweeps[0]->points[1]->recipe);
        $this->assertSame('all', $sweeps[0]->points[1]->tag);
    }

    public function test_a_table_the_seeder_does_not_know_is_treated_as_having_no_candidate(): void
    {
        $sweeps = (new SeederPlanner())->sweepsFor(self::spec('/x', ['n_table' => 'songs']), self::SCALES);

        $this->assertSame('all', $sweeps[0]->points[1]->tag);
    }

    public function test_a_schema_qualified_table_name_is_read_by_its_last_part(): void
    {
        $sweeps = (new SeederPlanner())->sweepsFor(self::spec('/x', ['n_table' => 'app.orders']), self::SCALES);

        $this->assertSame(1000, $sweeps[0]->points[2]->recipe['orders']);
    }

    public function test_the_children_per_order_get_a_second_sweep_with_the_orders_held_fixed(): void
    {
        $sweeps = (new SeederPlanner())->sweepsFor(self::spec('/ng/nested', ['m_table' => 'items', 'm_per' => 'orders']), self::SCALES);

        $this->assertCount(2, $sweeps);
        $this->assertSame('M', $sweeps[1]->axis);
        $this->assertSame([3, 30, 300], self::scalesOf($sweeps[1]));
        $this->assertSame(['customers' => 20, 'products' => 20, 'orders' => 20, 'items' => 30], $sweeps[1]->points[1]->recipe);
        $this->assertNotSame($sweeps[0]->points[1]->tag, $sweeps[1]->points[1]->tag);
    }

    public function test_a_global_route_gets_no_second_sweep(): void
    {
        $sweeps = (new SeederPlanner())->sweepsFor(self::spec('/x', ['n_table' => null, 'm_table' => 'items', 'm_per' => 'orders']), self::SCALES);

        $this->assertCount(1, $sweeps);
    }

    public function test_one_tag_and_scale_always_means_one_recipe(): void
    {
        $planner = new SeederPlanner();
        $seen = [];
        foreach ([self::spec(), self::spec('/a', ['n_table' => 'customers']), self::spec('/b', ['n_table' => null]), self::spec('/c', ['m_table' => 'items', 'm_per' => 'orders']), self::spec('/d')] as $spec) {
            foreach ($planner->sweepsFor($spec, self::SCALES) as $sweep) {
                foreach ($sweep->points as $point) {
                    $seen[$point->key()][] = $point->recipe;
                }
            }
        }
        foreach ($seen as $key => $recipes) {
            $this->assertCount(1, array_unique(array_map('json_encode', $recipes)), $key);
        }
    }

    public function test_every_database_name_it_yields_is_a_valid_measurement_database_name(): void
    {
        $env = ['PEAK_MEASURE_DB_HOST' => 'measure-db', 'PEAK_MEASURE_DB_USER' => 'measure', 'PEAK_MEASURE_DB_PASSWORD' => 'x', 'PEAK_MEASURE_RUN_ID' => str_repeat('a', 24), 'PEAK_MEASURE_APP_ROOT' => '/app'];
        $planner = new SeederPlanner();
        $points = [$planner->inspectionPoint(self::SCALES)];
        foreach ([self::spec(), self::spec('/a', ['n_table' => 'customers']), self::spec('/b', ['n_table' => null]), self::spec('/c', ['m_table' => 'items', 'm_per' => 'orders'])] as $spec) {
            foreach ($planner->sweepsFor($spec, self::SCALES) as $sweep) {
                array_push($points, ...$sweep->points);
            }
        }
        foreach ($points as $point) {
            $config = MeasureConfig::fromEnv($env + ['PEAK_MEASURE_DB_TAG' => $point->tag]);
            $this->assertTrue(DbGuard::isMeasureDbName($config->dbNameForScale($point->scale)), $point->key());
        }
    }

    public function test_the_inspection_database_is_a_small_whole_system(): void
    {
        $point = (new SeederPlanner())->inspectionPoint(self::SCALES);

        $this->assertSame('insp', $point->tag);
        $this->assertSame(100, $point->scale);
        $this->assertSame(['customers' => 100, 'products' => 100, 'orders' => 100, 'items' => 3], $point->recipe);
    }
}
