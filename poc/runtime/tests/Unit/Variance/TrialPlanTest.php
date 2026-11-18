<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Variance;

use PeakSim\Runtime\Measure\DatabaseBuilder;
use PeakSim\Runtime\Measure\DatabasePoint;
use PeakSim\Runtime\Measure\Driver;
use PeakSim\Runtime\Measure\GeneratorPlanner;
use PeakSim\Runtime\Measure\MeasureRequest;
use PeakSim\Runtime\Measure\OneResult;
use PeakSim\Runtime\Measure\OneRunner;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Measure\SeederPlanner;
use PeakSim\Runtime\Run\Attempt;
use PeakSim\Runtime\Tests\Unit\Measure\PlannerTestCase;
use PeakSim\Runtime\Variance\TrialPlan;

final class TrialPlanTest extends PlannerTestCase
{
    private const SCALE = 100000;

    /** A route task the way a recorded run keeps it: the filled uri, a login, a CSRF token, an empty body. */
    private static function task(string $uri, string $method, string $filled, string $table = 'deletions', ?string $failClass = null): RouteTask
    {
        return new RouteTask(
            self::spec($uri, ['n_table' => $table], $method),
            $filled,
            $failClass,
            $failClass === null ? null : 'could not be prepared',
            'App\\Models\\User:1',
            [],
            false,
            true,
        );
    }

    /** @return list<RouteTask> */
    private static function tasks(): array
    {
        return [
            self::task('/books/{id}', 'DELETE', '/books/1'),
            self::task('/shelves/{slug}', 'DELETE', '/shelves/1'),
            self::task('/books/{id}', 'PUT', '/books/1', 'references'),
            self::task('/settings/recycle-bin/empty', 'POST', '/settings/recycle-bin/empty'),
        ];
    }

    public function test_the_routes_come_out_in_the_order_of_the_task_list_whatever_the_order_they_were_named_in(): void
    {
        $plan = TrialPlan::build(self::tasks(), ['POST /settings/recycle-bin/empty', 'DELETE /shelves/{slug}', 'DELETE /books/{id}'], self::SCALE, new GeneratorPlanner(), 'generator');

        $this->assertCount(1, $plan);
        $this->assertSame(['DELETE /books/{id}', 'DELETE /shelves/{slug}', 'POST /settings/recycle-bin/empty'], array_map(static fn (array $r): string => $r['method'] . ' ' . $r['route'], $plan[0]['routes']));
    }

    public function test_routes_that_share_a_data_plan_share_one_database_and_others_get_their_own(): void
    {
        $plan = TrialPlan::build(self::tasks(), ['DELETE /books/{id}', 'PUT /books/{id}', 'DELETE /shelves/{slug}'], self::SCALE, new GeneratorPlanner(), 'generator');

        $this->assertCount(2, $plan);
        $this->assertSame(self::SCALE, $plan[0]['scale']);
        $this->assertCount(2, $plan[0]['routes'], 'the two routes on the deletions table share a database');
        $this->assertCount(1, $plan[1]['routes']);
        $this->assertNotSame($plan[0]['key'], $plan[1]['key']);
    }

    public function test_the_build_arguments_are_the_ones_the_driver_builder_passes(): void
    {
        $plan = TrialPlan::build(self::tasks(), ['DELETE /books/{id}'], self::SCALE, new GeneratorPlanner(), 'generator', 'bookstack');
        $recipe = ['mode' => 'targeted', 'n_tables' => ['deletions'], 'm_table' => null, 'm_per' => null, 'n' => self::SCALE, 'm' => 0, 'base' => 5];

        $this->assertSame(['--scale=100000', '--mode=generator', '--recipe-json=' . json_encode($recipe), '--profile=bookstack'], $plan[0]['build_args']);
    }

    public function test_without_a_profile_the_build_has_no_profile_argument(): void
    {
        $plan = TrialPlan::build(self::tasks(), ['DELETE /books/{id}'], self::SCALE, new GeneratorPlanner(), 'generator');

        $this->assertSame(3, count($plan[0]['build_args']));
    }

    public function test_the_measure_arguments_are_warmup_and_cold_with_the_recorded_request(): void
    {
        $plan = TrialPlan::build(self::tasks(), ['DELETE /books/{id}'], self::SCALE, new GeneratorPlanner(), 'generator');
        $args = $plan[0]['routes'][0]['measure_args'];

        $this->assertContains('--uri=/books/1', $args);
        $this->assertContains('--method=DELETE', $args);
        $this->assertContains('--route=/books/{id}', $args);
        $this->assertContains('--axis=N', $args);
        $this->assertContains('--csrf', $args);
        $this->assertContains('--act-as=App\\Models\\User:1', $args);
        $this->assertContains('--body-json={}', $args);
        $this->assertContains('--phases=warmup,cold', $args);
        $this->assertNotContains('--gate-bypass', $args);
    }

    public function test_the_arguments_are_what_the_driver_itself_passes_to_measure_one(): void
    {
        $driverRequests = new \ArrayObject();
        $runner = new class($driverRequests) implements OneRunner {
            /** @param \ArrayObject<int, MeasureRequest> $seen */
            public function __construct(private \ArrayObject $seen)
            {
            }

            public function run(MeasureRequest $request): OneResult
            {
                $this->seen->append($request);
                $attempts = [];
                foreach ($request->phases as $phase) {
                    $attempts[] = new Attempt($request->route, $request->method, $request->scale, $request->axis, $phase, 200, 5, ['mysql' => 5], ['orders'], 50, 1000, $request->gateBypass, 'ok', null);
                }

                return OneResult::ok($attempts);
            }
        };
        $builder = new class implements DatabaseBuilder {
            public function build(DatabasePoint $point): array
            {
                return [];
            }

            public function drop(DatabasePoint $point): void
            {
            }
        };
        $names = ['DELETE /books/{id}', 'DELETE /shelves/{slug}', 'POST /settings/recycle-bin/empty'];

        (new Driver(new GeneratorPlanner(), $builder, $runner, true, false))->run(self::tasks(), [self::SCALE]);
        $fromDriver = [];
        foreach ($driverRequests->getArrayCopy() as $request) {
            if ($request->scale === self::SCALE && in_array($request->method . ' ' . $request->route, $names, true)) {
                $fromDriver[$request->method . ' ' . $request->route] = $request->toArguments();
            }
        }
        $plan = TrialPlan::build(self::tasks(), $names, self::SCALE, new GeneratorPlanner(), 'generator');
        $fromPlan = [];
        foreach ($plan[0]['routes'] as $route) {
            $fromPlan[$route['method'] . ' ' . $route['route']] = $route['measure_args'];
        }

        $this->assertCount(3, $fromDriver);
        $this->assertSame($fromDriver, $fromPlan);
    }

    public function test_the_seeder_data_plan_is_used_when_asked_for(): void
    {
        $tasks = [self::task('/ok/eager', 'GET', '/ok/eager', 'orders')];

        $plan = TrialPlan::build($tasks, ['GET /ok/eager'], 50, new SeederPlanner(), 'seeder');

        $this->assertSame('--mode=seeder', $plan[0]['build_args'][1]);
        $this->assertSame(50, $plan[0]['scale']);
    }

    public function test_a_plan_is_written_as_plain_files_a_shell_script_can_read(): void
    {
        $plan = TrialPlan::build(self::tasks(), ['DELETE /books/{id}', 'PUT /books/{id}', 'DELETE /shelves/{slug}'], self::SCALE, new GeneratorPlanner(), 'generator', 'bookstack');
        $dir = sys_get_temp_dir() . '/peak-trial-plan-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            TrialPlan::writeFiles($plan, $dir);

            $this->assertSame("2\n", file_get_contents($dir . '/plan.groups'));
            $this->assertSame($plan, json_decode((string) file_get_contents($dir . '/plan.json'), true));
            $this->assertSame($plan[0]['key'] . "\t" . $plan[0]['tag'] . "\t100000\n", file_get_contents($dir . '/g1/plan.meta'));
            $this->assertSame(implode("\n", $plan[0]['build_args']) . "\n", file_get_contents($dir . '/g1/plan.build_args'));
            $this->assertSame(implode("\n", $plan[0]['routes'][1]['measure_args']) . "\n", file_get_contents($dir . '/g1/plan.r2.args'));
            $this->assertSame(
                "1\tDELETE\t/books/{id}\t/books/1\tDELETE__books__id_\n2\tDELETE\t/shelves/{slug}\t/shelves/1\tDELETE__shelves__slug_\n",
                file_get_contents($dir . '/g1/plan.routes'),
            );
            $this->assertFileExists($dir . '/g2/plan.r1.args');
        } finally {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($dir);
        }
    }

    public function test_a_route_that_is_not_in_the_task_list_is_refused_by_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('DELETE /nope is not in the task list');

        TrialPlan::build(self::tasks(), ['DELETE /nope'], self::SCALE, new GeneratorPlanner(), 'generator');
    }

    public function test_a_route_that_was_not_prepared_in_the_recorded_run_is_refused(): void
    {
        $tasks = [self::task('/x', 'GET', '/x', 'deletions', 'not_found')];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not_found');

        TrialPlan::build($tasks, ['GET /x'], self::SCALE, new GeneratorPlanner(), 'generator');
    }

    public function test_a_route_named_twice_or_no_route_at_all_is_refused(): void
    {
        foreach ([['DELETE /books/{id}', 'DELETE /books/{id}'], []] as $names) {
            try {
                TrialPlan::build(self::tasks(), $names, self::SCALE, new GeneratorPlanner(), 'generator');
                $this->fail('a bad list of routes was accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_route_that_is_in_the_task_list_twice_is_refused(): void
    {
        $tasks = [self::task('/x', 'GET', '/x'), self::task('/x', 'GET', '/x')];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('twice');

        TrialPlan::build($tasks, ['GET /x'], self::SCALE, new GeneratorPlanner(), 'generator');
    }

    public function test_the_state_changing_routes_go_last_in_their_database_and_the_group_names_the_tables_to_restore(): void
    {
        $plan = TrialPlan::build(
            self::tasks(),
            ['DELETE /books/{id}', 'DELETE /shelves/{slug}', 'POST /settings/recycle-bin/empty'],
            self::SCALE,
            new GeneratorPlanner(),
            'generator',
            null,
            ['DELETE /books/{id}' => ['entities'], 'DELETE /shelves/{slug}' => ['references']],
        );

        $this->assertSame(
            ['POST /settings/recycle-bin/empty', 'DELETE /books/{id}', 'DELETE /shelves/{slug}'],
            array_map(static fn (array $r): string => $r['method'] . ' ' . $r['route'], $plan[0]['routes']),
            'the others first, then the two in the order the profile lists them',
        );
        $this->assertSame(['entities', 'references'], $plan[0]['restore_tables']);
    }

    public function test_with_no_state_changing_route_the_order_is_the_task_list_and_nothing_is_restored(): void
    {
        $plan = TrialPlan::build(self::tasks(), ['DELETE /books/{id}', 'DELETE /shelves/{slug}', 'POST /settings/recycle-bin/empty'], self::SCALE, new GeneratorPlanner(), 'generator');

        $this->assertSame(['DELETE /books/{id}', 'DELETE /shelves/{slug}', 'POST /settings/recycle-bin/empty'], array_map(static fn (array $r): string => $r['method'] . ' ' . $r['route'], $plan[0]['routes']));
        $this->assertSame([], $plan[0]['restore_tables']);
    }

    public function test_the_restore_tables_are_written_to_the_plan_directory_and_the_runner_reads_them_after_each_lap(): void
    {
        $plan = TrialPlan::build(self::tasks(), ['DELETE /books/{id}'], self::SCALE, new GeneratorPlanner(), 'generator', null, ['DELETE /books/{id}' => ['entities']]);
        $dir = sys_get_temp_dir() . '/peak-trial-plan-' . bin2hex(random_bytes(4));
        mkdir($dir);
        TrialPlan::writeFiles($plan, $dir);

        $this->assertSame("entities\n", file_get_contents($dir . '/g1/plan.restore'));
        $script = (string) file_get_contents(dirname(__DIR__, 3) . '/bin/variance-run');
        $this->assertSame(2, substr_count($script, 'restore_stats "$k" "$g" "$gd" "$dbname"'), 'after the laps of both loops');

        array_map('unlink', glob($dir . '/g1/*') ?: []);
        array_map('unlink', glob($dir . '/*.*') ?: []);
        rmdir($dir . '/g1');
        foreach (glob($dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($dir);
    }
}
