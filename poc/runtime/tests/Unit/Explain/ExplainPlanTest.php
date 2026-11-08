<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Explain;

use PeakSim\Runtime\Explain\ExplainPlan;
use PeakSim\Runtime\Measure\GeneratorPlanner;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Tests\Unit\Measure\PlannerTestCase;

final class ExplainPlanTest extends PlannerTestCase
{
    private static function task(string $uri, string $method, string $filled, string $table = 'orders', ?string $failClass = null): RouteTask
    {
        return new RouteTask(self::spec($uri, ['n_table' => $table], $method), $filled, $failClass, $failClass === null ? null : 'could not be prepared', 'App\\Models\\User:1', [], false, true);
    }

    /** @return list<RouteTask> */
    private static function tasks(): array
    {
        return [
            self::task('/orders/{id}', 'GET', '/orders/1'),
            self::task('/orders/{id}', 'DELETE', '/orders/1'),
            self::task('/items/{id}', 'GET', '/items/1', 'items'),
            self::task('/broken', 'GET', '/broken', 'orders', 'no_user'),
        ];
    }

    public function testEachRouteIsOnTheDatabaseOfItsDataPlanAtBothScales(): void
    {
        $plan = ExplainPlan::build(self::tasks(), ['GET /orders/{id}', 'DELETE /orders/{id}', 'GET /items/{id}'], 100000, 10000, new GeneratorPlanner(), 'generator', null);

        $this->assertCount(4, $plan['groups']);
        $this->assertSame(['target', 'target', 'small', 'small'], array_column($plan['groups'], 'role'));
        $this->assertSame([100000, 100000, 10000, 10000], array_column($plan['groups'], 'scale'));
        $this->assertSame(['GET /orders/{id}', 'DELETE /orders/{id}'], $plan['groups'][0]['routes']);
        $this->assertSame(['GET /items/{id}'], $plan['groups'][1]['routes']);
        $this->assertSame([], $plan['unplanned']);
    }

    public function testARouteThatIsNotInTheTaskListOrWasNotPreparedIsUnplannedWithItsReason(): void
    {
        $plan = ExplainPlan::build(self::tasks(), ['GET /orders/{id}', 'GET /ghost', 'GET /broken'], 100000, 10000, new GeneratorPlanner(), 'generator', null);

        $this->assertSame(['GET /ghost' => 'route_not_in_the_task_list', 'GET /broken' => 'route_not_prepared_in_the_recorded_run: no_user'], $plan['unplanned']);
        $this->assertCount(2, $plan['groups']);
    }

    public function testNothingPlannableGivesNoDatabase(): void
    {
        $plan = ExplainPlan::build(self::tasks(), ['GET /ghost'], 100000, 10000, new GeneratorPlanner(), 'generator', null);

        $this->assertSame([], $plan['groups']);
    }

    public function testTheBuildArgumentsCarryTheScaleAndTheProfile(): void
    {
        $plan = ExplainPlan::build(self::tasks(), ['GET /orders/{id}'], 100000, 10000, new GeneratorPlanner(), 'generator', 'bookstack');

        $this->assertSame('--scale=100000', $plan['groups'][0]['build_args'][0]);
        $this->assertSame('--scale=10000', $plan['groups'][1]['build_args'][0]);
        $this->assertContains('--profile=bookstack', $plan['groups'][0]['build_args']);
    }

    public function testThePlanIsWrittenAsFilesAShellCanRead(): void
    {
        $dir = sys_get_temp_dir() . '/peak-explain-plan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $plan = ExplainPlan::build(self::tasks(), ['GET /orders/{id}', 'DELETE /orders/{id}'], 100000, 10000, new GeneratorPlanner(), 'generator', null);
            ExplainPlan::writeFiles($plan, $dir);

            $this->assertSame("2\n", file_get_contents($dir . '/plan.groups'));
            $this->assertMatchesRegularExpression('/^target\t[a-z][a-z0-9]{0,7}\t100000\n$/D', (string) file_get_contents($dir . '/g1/plan.meta'));
            $this->assertMatchesRegularExpression('/^small\t[a-z][a-z0-9]{0,7}\t10000\n$/D', (string) file_get_contents($dir . '/g2/plan.meta'));
            $this->assertSame("GET /orders/{id}\nDELETE /orders/{id}\n", file_get_contents($dir . '/g1/plan.routes'));
            $this->assertStringStartsWith("--scale=100000\n--mode=generator\n", (string) file_get_contents($dir . '/g1/plan.build_args'));
            $this->assertJson((string) file_get_contents($dir . '/plan.json'));
        } finally {
            foreach (glob($dir . '/g*/*') ?: [] as $f) {
                @unlink($f);
            }
            foreach (glob($dir . '/g*') ?: [] as $d) {
                @rmdir($d);
            }
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
