<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\DatabaseBuilder;
use PeakSim\Runtime\Measure\DatabasePoint;
use PeakSim\Runtime\Measure\DataPlanner;
use PeakSim\Runtime\Measure\Driver;
use PeakSim\Runtime\Measure\MeasureExit;
use PeakSim\Runtime\Measure\MeasureRequest;
use PeakSim\Runtime\Measure\OneResult;
use PeakSim\Runtime\Measure\OneRunner;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Measure\SeederPlanner;
use PeakSim\Runtime\Run\Attempt;

final class DriverTest extends PlannerTestCase
{
    private const SCALES = [100, 1000, 10000];

    /**
     * @param callable(MeasureRequest, int): OneResult|null $script
     */
    private static function runner(?callable $script = null): object
    {
        return new class($script) implements OneRunner {
            /** @var list<MeasureRequest> */
            public array $requests = [];

            /** @param callable(MeasureRequest, int): OneResult|null $script */
            public function __construct(private $script)
            {
            }

            public function run(MeasureRequest $request): OneResult
            {
                $this->requests[] = $request;
                $script = $this->script ?? static fn (MeasureRequest $r, int $n): OneResult => OneResult::ok(self::linear($r, 1, 0));

                return $script($request, count($this->requests));
            }

            /** @return list<Attempt> queries = base + per * scale for the three phases */
            public static function linear(MeasureRequest $r, int $base, int $per): array
            {
                $out = [];
                foreach ($r->phases as $phase) {
                    $out[] = new Attempt($r->route, $r->method, $r->scale, $r->axis, $phase, 200, $base + $per * $r->scale, ['mysql' => $base + $per * $r->scale], ['orders'], 50 + $r->scale, 1000, $r->gateBypass, 'ok', null);
                }

                return $out;
            }
        };
    }

    private static function builder(?\Closure $onBuild = null): object
    {
        return new class($onBuild) implements DatabaseBuilder {
            /** @var list<string> */
            public array $log = [];

            public function __construct(private readonly ?\Closure $onBuild)
            {
            }

            public function build(DatabasePoint $point): array
            {
                $this->log[] = 'build ' . $point->key();
                if ($this->onBuild !== null) {
                    ($this->onBuild)($point, count(array_filter($this->log, static fn (string $l): bool => str_starts_with($l, 'build '))));
                }

                return ['key' => $point->key()];
            }

            public function drop(DatabasePoint $point): void
            {
                $this->log[] = 'drop ' . $point->key();
            }
        };
    }

    private static function task(string $uri = '/ng/n-plus-one', array $static = [], array $extra = []): RouteTask
    {
        return new RouteTask(self::spec($uri, $static), $uri, ...$extra);
    }

    public function test_it_builds_each_database_once_measures_every_route_that_needs_it_and_drops_it(): void
    {
        $builder = self::builder();
        $runner = self::runner();
        $result = (new Driver(new SeederPlanner(), $builder, $runner))->run([self::task('/a'), self::task('/b')], self::SCALES);

        $this->assertSame(MeasureExit::OK, $result->exit);
        $this->assertSame(
            ['build ord:0', 'drop ord:0', 'build ord:100', 'drop ord:100', 'build ord:1000', 'drop ord:1000', 'build ord:10000', 'drop ord:10000'],
            $builder->log,
        );
        $this->assertCount(8, $runner->requests);
        $this->assertSame(['/a', '/b', '/a', '/b'], array_slice(array_map(static fn (MeasureRequest $r) => $r->route, $runner->requests), 0, 4));
    }

    public function test_scale_zero_is_measured_on_the_n_axis_and_reaches_the_judge(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => OneResult::ok(
            // a page that fills to 900 bytes at every non-empty scale, 40 bytes when empty
            array_map(static fn (Attempt $a): Attempt => new Attempt($a->route, $a->method, $a->scale, $a->axis, $a->attempt, 200, 5, ['mysql' => 5], ['orders'], $r->scale === 0 ? 40 : 900, 1000, false, 'ok', null), self::linearOf($r)),
        ));
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::task('/ok/paginate', ['query' => ['growth' => 'O(1)', 'lower_bound' => null, 'confidence' => 'high']])], self::SCALES);

        $zero = array_values(array_filter($runner->requests, static fn (MeasureRequest $r): bool => $r->scale === 0));
        $this->assertCount(1, $zero);
        $this->assertSame('N', $zero[0]->axis);
        $row = $result->report->rows()[0];
        $this->assertSame('measured_O1', $row['verdict'], 'a full page is larger than the empty response, so it is a real O(1)');
        $this->assertSame([100, 1000, 10000], $row['scales']);
    }

    public function test_an_empty_response_at_the_largest_scale_is_no_info_not_o1(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => OneResult::ok(
            array_map(static fn (Attempt $a): Attempt => new Attempt($a->route, $a->method, $a->scale, $a->axis, $a->attempt, 200, 1, ['mysql' => 1], ['orders'], 2, 1000, false, 'ok', null), self::linearOf($r)),
        ));
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::task()], self::SCALES);

        $this->assertSame('no_info', $result->report->rows()[0]['verdict']);
    }

    public function test_the_empty_system_runs_without_the_warm_attempt(): void
    {
        $runner = self::runner();
        (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::task()], self::SCALES);

        $this->assertSame([Attempt::WARMUP, Attempt::COLD], $runner->requests[0]->phases);
        $this->assertSame([Attempt::WARMUP, Attempt::COLD, Attempt::WARM], $runner->requests[1]->phases);
    }

    public function test_the_children_per_parent_sweep_goes_to_the_judge_as_the_m_axis(): void
    {
        $runner = self::runner(static function (MeasureRequest $r, int $n): OneResult {
            // N sweep: 1 + orders (n plus one); M sweep: 21 + 20*M (nested)
            return OneResult::ok(array_map(
                static fn (Attempt $a): Attempt => new Attempt($a->route, $a->method, $a->scale, $a->axis, $a->attempt, 200, $r->axis === 'M' ? 1 + 20 + 20 * $r->scale : 1 + $r->scale * 3, [], ['orders', 'items'], 100 + $r->scale, 1000, false, 'ok', null),
                self::linearOf($r),
            ));
        });
        $task = self::task('/ng/nested', ['query' => ['growth' => 'O(N*M)', 'lower_bound' => null, 'confidence' => 'high'], 'm_table' => 'items', 'm_per' => 'orders']);
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner))->run([$task], self::SCALES);

        $m = array_values(array_filter($runner->requests, static fn (MeasureRequest $r): bool => $r->axis === 'M'));
        $this->assertSame([3, 30, 300], array_map(static fn (MeasureRequest $r) => $r->scale, $m));
        $row = $result->report->rows()[0];
        $this->assertSame('measured_ONM', $row['verdict']);
        $this->assertSame('agree', $row['relation']);
    }

    public function test_a_route_that_could_not_be_prepared_is_recorded_with_its_reason_and_costs_no_database(): void
    {
        $builder = self::builder();
        $runner = self::runner();
        $task = new RouteTask(self::spec('/orders/{order}'), null, 'unfilled_param', 'order: no model row');
        $result = (new Driver(new SeederPlanner(), $builder, $runner))->run([$task], self::SCALES);

        $this->assertSame(MeasureExit::OK, $result->exit);
        $this->assertSame([], $builder->log);
        $this->assertSame([], $runner->requests);
        $row = $result->report->rows()[0];
        $this->assertSame('unfilled_param', $row['class']);
        $this->assertSame('order: no model row', $row['reason_detail']);
        $this->assertSame('not_measured', $row['verdict']);
    }

    public function test_the_request_carries_what_the_task_decided(): void
    {
        $runner = self::runner();
        $task = new RouteTask(self::spec('/orders/{order}', [], 'POST', ['web', 'auth']), '/orders/7', null, null, 'App\\Models\\User:1:web', ['name' => 'x'], false, true, ['X-A' => '1']);
        (new Driver(new SeederPlanner(), self::builder(), $runner))->run([$task], self::SCALES);

        $r = $runner->requests[1];
        $this->assertSame('/orders/7', $r->uri);
        $this->assertSame('/orders/{order}', $r->route);
        $this->assertSame('POST', $r->method);
        $this->assertSame('App\\Models\\User:1:web', $r->actAs);
        $this->assertSame(['name' => 'x'], $r->body);
        $this->assertTrue($r->csrf);
        $this->assertSame(['X-A' => '1'], $r->headers);
        $this->assertFalse($r->gateBypass);
        $this->assertSame('ord', $r->tag);
    }

    public function test_a_403_gets_exactly_one_more_try_with_the_gate_bypassed(): void
    {
        $runner = self::runner(static function (MeasureRequest $r, int $n): OneResult {
            if (!$r->gateBypass) {
                return OneResult::ok([new Attempt($r->route, $r->method, $r->scale, $r->axis, Attempt::WARMUP, 403, 3, [], [], 10, 1000, false, 'forbidden', null)]);
            }

            return OneResult::ok(self::linearOf($r));
        });
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::task()], self::SCALES);

        $bypassed = array_filter($runner->requests, static fn (MeasureRequest $r): bool => $r->gateBypass);
        $this->assertCount(4, $bypassed, 'one retry per scale, and no retry of the retry');
        $this->assertCount(8, $runner->requests);
        $this->assertTrue($result->report->rows()[0]['gate_bypass']);
    }

    public function test_with_the_gate_bypass_off_a_403_is_recorded_as_it_is_and_never_retried(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => OneResult::ok([new Attempt($r->route, $r->method, $r->scale, $r->axis, Attempt::WARMUP, 403, 3, [], [], 10, 1000, false, 'forbidden', null)]));
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner, gateBypass: false))->run([self::task()], self::SCALES);

        $this->assertCount(4, $runner->requests, 'one try per scale');
        $this->assertSame([], array_filter($runner->requests, static fn (MeasureRequest $r): bool => $r->gateBypass));
        $this->assertFalse($result->report->rows()[0]['gate_bypass']);
        $this->assertSame('forbidden', $result->report->rows()[0]['class']);
    }

    public function test_a_failed_database_build_stops_the_run_with_13_and_still_drops_it(): void
    {
        $builder = self::builder(static function (DatabasePoint $p, int $count): void {
            if ($p->scale === 1000) {
                throw new BuildFailure('seeder failed');
            }
        });
        $result = (new Driver(new SeederPlanner(), $builder, self::runner()))->run([self::task()], self::SCALES);

        $this->assertSame(MeasureExit::DATA, $result->exit);
        $this->assertStringContainsString('seeder failed', (string) $result->message);
        $this->assertContains('drop ord:1000', $builder->log);
        $this->assertNotContains('build ord:10000', $builder->log);
        $this->assertCount(1, $result->report->rows());
    }

    public function test_an_app_that_does_not_start_stops_the_run_with_12(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => new OneResult(12, [], 'cannot start the app'));
        $builder = self::builder();
        $result = (new Driver(new SeederPlanner(), $builder, $runner))->run([self::task()], self::SCALES);

        $this->assertSame(MeasureExit::APP_START, $result->exit);
        $this->assertCount(1, $runner->requests);
        $this->assertSame(['build ord:0', 'drop ord:0'], $builder->log);
    }

    public function test_a_refusal_stops_the_run_with_10(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => new OneResult(10, [], 'refused: a statement referred to another schema'));
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::task()], self::SCALES);

        $this->assertSame(MeasureExit::REFUSED, $result->exit);
        $this->assertStringContainsString('refused', (string) $result->message);
    }

    public function test_a_contaminated_database_is_rebuilt_and_the_run_goes_on(): void
    {
        $runner = self::runner(static function (MeasureRequest $r, int $n): OneResult {
            if ($r->route === '/bad') {
                return new OneResult(14, [new Attempt($r->route, $r->method, $r->scale, $r->axis, Attempt::WARMUP, 200, 4, [], [], 10, 1000, false, 'not_rollbackable', 'row_count_changed: orders')], 'the database can no longer be trusted');
            }

            return OneResult::ok(self::linearOf($r));
        });
        $builder = self::builder();
        $result = (new Driver(new SeederPlanner(), $builder, $runner))->run([self::task('/bad'), self::task('/good')], self::SCALES);

        $this->assertSame(MeasureExit::OK, $result->exit);
        $this->assertSame(['build ord:100', 'drop ord:100', 'build ord:100', 'drop ord:100'], array_values(array_filter($builder->log, static fn (string $l): bool => str_ends_with($l, ' ord:100'))));
        $rebuilt = array_filter($result->builds, static fn (array $b): bool => isset($b['rebuilt_after']));
        $this->assertCount(4, $rebuilt, 'the route changes the database on every scale, and every one is rebuilt');
        $this->assertSame(['/bad'], array_values(array_unique(array_column($rebuilt, 'rebuilt_after'))));
        $goodAt100 = array_filter($runner->requests, static fn (MeasureRequest $r): bool => $r->route === '/good' && $r->scale === 100);
        $this->assertCount(1, $goodAt100, 'the route after the contaminating one is measured on the rebuilt database');
        $rows = array_column($result->report->rows(), null, 'route');
        $this->assertSame('not_rollbackable', $rows['/bad']['class']);
        $this->assertSame('measured_ON', $rows['/good']['verdict']);
    }

    public function test_a_database_that_cannot_be_rebuilt_after_contamination_stops_the_run_with_14(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => new OneResult(14, [new Attempt($r->route, $r->method, $r->scale, $r->axis, Attempt::WARMUP, 200, 4, [], [], 10, 1000, false, 'not_rollbackable', 'ddl')], 'contaminated'));
        $builder = self::builder(static function (DatabasePoint $p, int $count): void {
            if ($count >= 2) {
                throw new BuildFailure('cannot rebuild');
            }
        });
        $result = (new Driver(new SeederPlanner(), $builder, $runner))->run([self::task()], self::SCALES);

        $this->assertSame(MeasureExit::CONTAMINATED, $result->exit);
    }

    public function test_a_crashed_measurement_is_recorded_and_the_run_goes_on(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => $r->route === '/crash' ? new OneResult(255, [], "PHP Fatal error: out of memory\n") : OneResult::ok(self::linearOf($r)));
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::task('/crash'), self::task('/fine')], self::SCALES);

        $this->assertSame(MeasureExit::OK, $result->exit);
        $rows = array_column($result->report->rows(), null, 'route');
        $this->assertSame('runner_failed', $rows['/crash']['class']);
        $this->assertStringContainsString('out of memory', (string) $rows['/crash']['reason_detail']);
        $this->assertSame('measured_ON', $rows['/fine']['verdict']);
    }

    public function test_a_measurement_that_ran_out_of_time_is_recorded_as_a_timeout(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => new OneResult(124, [], "timed out after 150 seconds"));
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::task('/slow')], self::SCALES);

        $this->assertSame(MeasureExit::OK, $result->exit);
        $this->assertSame('timeout', $result->report->rows()[0]['class']);
    }

    public function test_a_scale_that_failed_is_named_when_the_sweep_is_left_too_short_to_judge(): void
    {
        $runner = self::runner(static function (MeasureRequest $r, int $n): OneResult {
            if ($r->scale === 10000) {
                return OneResult::ok([new Attempt($r->route, $r->method, $r->scale, $r->axis, Attempt::WARMUP, 500, null, [], [], null, null, false, 'exception', 'QueryException: the request ran longer than 20 seconds')]);
            }

            return OneResult::ok(self::linearOf($r));
        });
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::task('/heavy')], self::SCALES);

        $row = $result->report->rows()[0];
        $this->assertSame('not_measured', $row['verdict']);
        $this->assertSame('exception', $row['class'], 'the row says why the largest scale is missing, not that all went well');
        $this->assertStringContainsString('scale 10000', (string) $row['reason_detail']);
        $this->assertStringContainsString('longer than 20 seconds', (string) $row['reason_detail']);
    }

    public function test_a_failure_on_the_second_sweep_does_not_hide_a_verdict_of_the_first(): void
    {
        $runner = self::runner(static function (MeasureRequest $r, int $n): OneResult {
            if ($r->axis === 'M') {
                return OneResult::ok([new Attempt($r->route, $r->method, $r->scale, $r->axis, Attempt::WARMUP, 500, null, [], [], null, null, false, 'exception', 'boom')]);
            }

            return OneResult::ok(self::linearOf($r));
        });
        $task = self::task('/ng/nested', ['m_table' => 'items', 'm_per' => 'orders']);
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner))->run([$task], self::SCALES);

        $row = $result->report->rows()[0];
        $this->assertSame('measured_ON', $row['verdict']);
        $this->assertSame('ok', $row['class']);
    }

    public function test_an_attempt_log_keeps_every_attempt_of_every_route(): void
    {
        $result = (new Driver(new SeederPlanner(), self::builder(), self::runner()))->run([self::task('/a')], self::SCALES);

        $this->assertCount(2 + 3 * 3, $result->attempts);
    }

    public function test_timings_name_each_database_and_how_long_it_took(): void
    {
        $result = (new Driver(new SeederPlanner(), self::builder(), self::runner()))->run([self::task('/a')], self::SCALES);

        $this->assertSame(['ord:0', 'ord:100', 'ord:1000', 'ord:10000'], array_column($result->timings, 'point'));
        $this->assertArrayHasKey('build_seconds', $result->timings[0]);
        $this->assertArrayHasKey('measure_seconds', $result->timings[0]);
    }

    public function test_two_planners_recipes_for_one_database_must_agree(): void
    {
        $planner = new class implements DataPlanner {
            public function sweepsFor(\PeakSim\Runtime\Route\RouteSpec $spec, array $scales): array
            {
                $n = $spec->uri === '/a' ? 1 : 2;

                return [new \PeakSim\Runtime\Measure\Sweep('N', [new DatabasePoint('same', 10, ['n' => $n])])];
            }

            public function inspectionPoint(array $scales): DatabasePoint
            {
                return new DatabasePoint('insp', 10, []);
            }
        };
        $this->expectException(\LogicException::class);
        (new Driver($planner, self::builder(), self::runner()))->run([self::task('/a'), self::task('/b')], self::SCALES);
    }

    /** @return list<Attempt> */
    private static function linearOf(MeasureRequest $r): array
    {
        return self::runner()::linear($r, 1, 1);
    }

    /** @return callable(MeasureRequest, int): OneResult a runner answer: every attempt is the same non-ok one */
    private static function notFound(): callable
    {
        return static fn (MeasureRequest $r, int $n): OneResult => OneResult::ok(array_map(
            static fn (Attempt $a): Attempt => new Attempt($a->route, $a->method, $a->scale, $a->axis, $a->attempt, 404, null, [], [], null, null, false, 'not_found', 'HTTP 404'),
            self::linearOf($r),
        ));
    }

    public function test_a_404_on_a_value_the_tool_made_up_is_not_counted_as_a_route_that_was_hit(): void
    {
        $task = new RouteTask(self::spec('/presets/{id}'), '/presets/1', guessed: ['id']);

        $result = (new Driver(new SeederPlanner(), self::builder(), self::runner(self::notFound())))->run([$task], self::SCALES);

        $row = $result->report->rows()[0];
        $this->assertSame('unfilled_param', $row['class']);
        $this->assertFalse($row['tried']);
        $this->assertStringContainsString('guess', (string) $row['reason_detail']);
        $this->assertStringContainsString('id', (string) $row['reason_detail']);
        $this->assertStringContainsString('not_found', (string) $row['reason_detail']);
        $this->assertNotSame([], $result->attempts, 'what was seen is still in the record of attempts');
    }

    public function test_a_404_on_a_value_taken_from_a_row_stays_a_route_that_was_hit_and_not_found(): void
    {
        $task = new RouteTask(self::spec('/presets/{id}'), '/presets/7');

        $result = (new Driver(new SeederPlanner(), self::builder(), self::runner(self::notFound())))->run([$task], self::SCALES);

        $row = $result->report->rows()[0];
        $this->assertSame('not_found', $row['class']);
        $this->assertTrue($row['tried']);
    }

    public function test_a_guessed_value_that_the_app_accepted_is_measured_as_before(): void
    {
        $task = new RouteTask(self::spec('/page/{n}'), '/page/1', guessed: ['n']);

        $result = (new Driver(new SeederPlanner(), self::builder(), self::runner()))->run([$task], self::SCALES);

        $row = $result->report->rows()[0];
        $this->assertTrue($row['tried']);
        $this->assertNotSame('unfilled_param', $row['class']);
    }

    public function test_the_request_carries_the_tasks_query_and_the_row_says_credentials_were_carried(): void
    {
        $runner = self::runner();
        $task = new RouteTask(self::spec('rest/ping'), '/rest/ping', query: ['u' => 'user@measure.invalid', 'apiKey' => '{key:subsonic:1}'], credentials: 'profile:subsonic: credentials (u, apiKey) carried in the query');
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner))->run([$task], self::SCALES);

        $this->assertSame(['u' => 'user@measure.invalid', 'apiKey' => '{key:subsonic:1}'], $runner->requests[1]->query);
        $row = $result->report->rows()[0];
        $this->assertSame('profile:subsonic: credentials (u, apiKey) carried in the query', $row['request_credentials']);
    }

    public function test_a_row_of_a_route_that_carried_no_credentials_has_no_such_key(): void
    {
        $result = (new Driver(new SeederPlanner(), self::builder(), self::runner()))->run([self::task('/a')], self::SCALES);

        $this->assertArrayNotHasKey('request_credentials', $result->report->rows()[0]);
    }
}
