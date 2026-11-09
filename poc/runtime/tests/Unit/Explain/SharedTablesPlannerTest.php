<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PeakSim\Runtime\Explain\ExplainPlan;
use PeakSim\Runtime\Explain\SharedTablesPlanner;
use PeakSim\Runtime\Measure\GeneratorPlanner;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Tests\Unit\Measure\PlannerTestCase;

final class SharedTablesPlannerTest extends PlannerTestCase
{
    private static function task(string $uri, string $table): RouteTask
    {
        return new RouteTask(self::spec($uri, ['n_table' => $table], 'GET'), $uri, null, null, 'App\\Models\\User:1', [], false, true);
    }

    /** @return list<RouteTask> */
    private static function tasks(): array
    {
        return [self::task('/orders/{id}', 'orders'), self::task('/items/{id}', 'items'), self::task('/ghosts/{id}', 'ghosts')];
    }

    private const ROUTES = ['GET /orders/{id}', 'GET /items/{id}', 'GET /ghosts/{id}'];

    public function testEveryRouteIsOnOneDatabaseWhereTheNamedTablesAllGrowTogether(): void
    {
        $plan = ExplainPlan::build(self::tasks(), self::ROUTES, 100000, 10000, new SharedTablesPlanner(['orders', 'items'], 5), 'generator', 'bookstack');

        $this->assertCount(2, $plan['groups']);
        $this->assertSame(['target', 'small'], array_column($plan['groups'], 'role'));
        $this->assertSame([100000, 10000], array_column($plan['groups'], 'scale'));
        $this->assertSame(self::ROUTES, $plan['groups'][0]['routes']);
        $this->assertSame(self::ROUTES, $plan['groups'][1]['routes']);
    }

    public function testTheRecipeNamesTheTablesSortedAndTheBaseAndNoChildrenAxis(): void
    {
        $plan = ExplainPlan::build(self::tasks(), self::ROUTES, 100000, 10000, new SharedTablesPlanner(['items', 'orders', 'items'], 7), 'generator', null);

        $args = $plan['groups'][0]['build_args'];
        $this->assertSame('--scale=100000', $args[0]);
        $recipe = json_decode(substr($args[2], strlen('--recipe-json=')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['mode' => 'targeted', 'n_tables' => ['items', 'orders'], 'm_table' => null, 'm_per' => null, 'n' => 100000, 'm' => 0, 'base' => 7], $recipe);
    }

    public function testTheTableNamesAreCheckedBeforeAnythingIsBuilt(): void
    {
        foreach ([[], ['Orders'], ['orders;drop'], ['a.b'], ['']] as $bad) {
            try {
                new SharedTablesPlanner($bad, 5);
                $this->fail('a name list ' . json_encode($bad) . ' was accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheDefaultPlannerIsNotChanged(): void
    {
        $plan = ExplainPlan::build(self::tasks(), self::ROUTES, 100000, 10000, new GeneratorPlanner(), 'generator', null);

        $this->assertCount(6, $plan['groups']);
    }

    public function testTheInspectionPointIsTheGeneratorsOwn(): void
    {
        $this->assertEquals((new GeneratorPlanner(5))->inspectionPoint([100]), (new SharedTablesPlanner(['orders'], 5))->inspectionPoint([100]));
    }
}
