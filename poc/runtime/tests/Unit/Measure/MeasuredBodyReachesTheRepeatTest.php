<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\DatabaseBuilder;
use PeakSim\Runtime\Measure\DatabasePoint;
use PeakSim\Runtime\Measure\Driver;
use PeakSim\Runtime\Measure\MeasureRequest;
use PeakSim\Runtime\Measure\OneResult;
use PeakSim\Runtime\Measure\OneRunner;
use PeakSim\Runtime\Measure\OutputWriter;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Measure\SeederPlanner;
use PeakSim\Runtime\Run\Attempt;
use PeakSim\Runtime\Variance\TrialPlan;

/**
 * The extra cold tries of a marked route (bin/variance-run) take their requests from the tasks.json of the measurement. When the
 * measurement had to build the body again after a 422, that file must hold the body that got past validation, not the one the
 * task started with: otherwise the extra tries are all refused with a 422 and nothing is measured.
 */
final class MeasuredBodyReachesTheRepeatTest extends PlannerTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/peak-measured-body-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public static function answer(MeasureRequest $r, bool $accepted): OneResult
    {
        $out = [];
        foreach ($accepted ? $r->phases : [Attempt::WARMUP] as $phase) {
            $out[] = $accepted
                ? new Attempt($r->route, $r->method, $r->scale, $r->axis, $phase, 200, 4, ['mysql' => 4], ['orders'], 90, 1000, false, 'ok', null)
                : new Attempt($r->route, $r->method, $r->scale, $r->axis, $phase, 422, 2, [], [], 80, 1000, false, 'validation_422', 'fields: name', errorKeys: ['name']);
        }

        return OneResult::ok($out);
    }

    /** A runner that refuses every body without a "name" field, as a route with a required field does. */
    private static function runner(): OneRunner
    {
        return new class() implements OneRunner {
            public function run(MeasureRequest $request): OneResult
            {
                return MeasuredBodyReachesTheRepeatTest::answer($request, isset($request->body['name']));
            }
        };
    }

    private static function builder(): DatabaseBuilder
    {
        return new class() implements DatabaseBuilder {
            public function build(DatabasePoint $point): array
            {
                return [];
            }

            public function drop(DatabasePoint $point): void
            {
            }
        };
    }

    /** @return list<RouteTask> */
    private static function tasks(): array
    {
        return [
            new RouteTask(self::spec('/api/things/{id}', method: 'PUT'), '/api/things/1', actAs: 'App\\Models\\User:1', body: ['title' => 'x'], csrf: true),
            new RouteTask(self::spec('/api/other', method: 'POST'), '/api/other', actAs: 'App\\Models\\User:1', body: ['name' => 'given'], csrf: true),
        ];
    }

    /** @return list<RouteTask> the tasks as tasks.json says them after a measurement */
    private function recordedTasks(): array
    {
        $tasks = self::tasks();
        $result = (new Driver(new SeederPlanner(), self::builder(), self::runner()))->run($tasks, [100]);
        (new OutputWriter($this->dir))->write($result, ['data' => 'seeder'], $tasks, null);
        $list = json_decode((string) file_get_contents($this->dir . '/tasks.json'), true, 512, JSON_THROW_ON_ERROR);

        return array_map(RouteTask::fromArray(...), $list);
    }

    /** @return list<string> the measure-one arguments the repeat runner would pass for the route */
    private function repeatArguments(string $key): array
    {
        $plan = TrialPlan::build($this->recordedTasks(), [$key], 100, new SeederPlanner(), 'seeder');

        return $plan[0]['routes'][0]['measure_args'];
    }

    public function test_the_repeat_of_a_route_that_needed_a_new_body_sends_the_body_that_got_past_validation(): void
    {
        $args = $this->repeatArguments('PUT /api/things/{id}');

        $this->assertContains('--body-json=' . json_encode(['title' => 'x', 'name' => 'x_1']), $args);
    }

    public function test_a_body_the_measurement_did_not_have_to_change_is_recorded_as_it_was(): void
    {
        $args = $this->repeatArguments('POST /api/other');

        $this->assertContains('--body-json=' . json_encode(['name' => 'given']), $args);
    }

    public function test_the_task_is_recorded_unchanged_apart_from_the_body(): void
    {
        $recorded = $this->recordedTasks();
        $before = self::tasks()[0]->toArray();
        $after = $recorded[0]->toArray();
        unset($before['body'], $after['body']);

        $this->assertSame($before, $after);
    }
}
