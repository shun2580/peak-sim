<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\GeneratorPlanner;

final class GeneratorPlannerTest extends PlannerTestCase
{
    private const SCALES = [100, 1000, 10000];

    public function test_a_route_with_a_candidate_table_gets_a_targeted_plan_for_that_table(): void
    {
        $sweeps = (new GeneratorPlanner())->sweepsFor(self::spec(), self::SCALES);

        $this->assertCount(1, $sweeps);
        $this->assertSame([0, 100, 1000, 10000], array_map(static fn ($p) => $p->scale, $sweeps[0]->points));
        $this->assertSame(['mode' => 'targeted', 'n_tables' => ['orders'], 'm_table' => null, 'm_per' => null, 'n' => 1000, 'm' => 0, 'base' => 5], $sweeps[0]->points[2]->recipe);
    }

    public function test_equally_good_candidates_are_scaled_together_in_one_plan(): void
    {
        $sweeps = (new GeneratorPlanner())->sweepsFor(self::spec('/x', ['n_table' => 'orders', 'n_table_alternatives' => ['customers', 'orders']]), self::SCALES);

        $this->assertSame(['customers', 'orders'], $sweeps[0]->points[1]->recipe['n_tables']);
    }

    public function test_a_route_without_a_candidate_gets_a_global_plan(): void
    {
        $sweeps = (new GeneratorPlanner())->sweepsFor(self::spec('/x', ['n_table' => null]), self::SCALES);

        $this->assertSame(['mode' => 'global', 'n' => 100, 'base' => 5], $sweeps[0]->points[1]->recipe);
        $this->assertSame('glob', $sweeps[0]->points[1]->tag);
    }

    public function test_global_has_no_empty_system_point_because_its_n_cannot_be_zero(): void
    {
        $sweeps = (new GeneratorPlanner())->sweepsFor(self::spec('/x', ['n_table' => null]), self::SCALES);

        $this->assertSame([100, 1000, 10000], array_map(static fn ($p) => $p->scale, $sweeps[0]->points));
    }

    public function test_the_children_per_parent_row_get_a_second_sweep_with_the_parents_held_fixed(): void
    {
        $sweeps = (new GeneratorPlanner())->sweepsFor(self::spec('/ng/nested', ['m_table' => 'items', 'm_per' => 'orders']), self::SCALES);

        $this->assertCount(2, $sweeps);
        $this->assertSame('M', $sweeps[1]->axis);
        $this->assertSame([3, 30, 300], array_map(static fn ($p) => $p->scale, $sweeps[1]->points));
        $this->assertSame(['mode' => 'targeted', 'n_tables' => ['orders'], 'm_table' => 'items', 'm_per' => 'orders', 'n' => 20, 'm' => 30, 'base' => 5], $sweeps[1]->points[1]->recipe);
    }

    public function test_the_tag_depends_on_what_is_scaled_so_two_plans_never_share_a_database(): void
    {
        $planner = new GeneratorPlanner();
        $a = $planner->sweepsFor(self::spec('/a', ['n_table' => 'orders']), self::SCALES)[0]->points[1];
        $b = $planner->sweepsFor(self::spec('/b', ['n_table' => 'customers']), self::SCALES)[0]->points[1];
        $again = $planner->sweepsFor(self::spec('/c', ['n_table' => 'orders']), self::SCALES)[0]->points[1];

        $this->assertNotSame($a->tag, $b->tag);
        $this->assertSame($a->tag, $again->tag);
        $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]{0,7}$/D', $a->tag);
    }

    public function test_the_tag_of_the_second_sweep_differs_from_the_first(): void
    {
        $sweeps = (new GeneratorPlanner())->sweepsFor(self::spec('/ng/nested', ['m_table' => 'items', 'm_per' => 'orders']), self::SCALES);

        $this->assertNotSame($sweeps[0]->points[1]->tag, $sweeps[1]->points[1]->tag);
    }

    public function test_the_inspection_database_is_a_global_system_at_the_smallest_scale(): void
    {
        $point = (new GeneratorPlanner())->inspectionPoint(self::SCALES);

        $this->assertSame('insp', $point->tag);
        $this->assertSame(['mode' => 'global', 'n' => 100, 'base' => 5], $point->recipe);
    }
}
