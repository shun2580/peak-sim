<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\DatabaseBuilder;
use PeakSim\Runtime\Measure\DatabasePoint;
use PeakSim\Runtime\Measure\Driver;
use PeakSim\Runtime\Measure\MeasureRequest;
use PeakSim\Runtime\Measure\OneResult;
use PeakSim\Runtime\Measure\OneRunner;
use PeakSim\Runtime\Measure\RouteTask;
use PeakSim\Runtime\Measure\SeederPlanner;
use PeakSim\Runtime\Run\Attempt;

/** A 422 is answered by building the body again from the fields it names (up to three rounds), not recorded as final. */
final class DriverValidationRetryTest extends PlannerTestCase
{
    private const SCALES = [100, 1000, 10000];

    /** @param callable(MeasureRequest, int): OneResult $script */
    private static function runner(callable $script): object
    {
        return new class($script) implements OneRunner {
            /** @var list<MeasureRequest> */
            public array $requests = [];

            /** @param callable(MeasureRequest, int): OneResult $script */
            public function __construct(private $script)
            {
            }

            public function run(MeasureRequest $request): OneResult
            {
                $this->requests[] = $request;

                return ($this->script)($request, count($this->requests));
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

    /** @param list<string> $keys */
    private static function refused(MeasureRequest $r, array $keys): OneResult
    {
        return OneResult::ok([new Attempt($r->route, $r->method, $r->scale, $r->axis, Attempt::WARMUP, 422, 2, [], [], 80, 1000, false, 'validation_422', 'fields: ' . implode(', ', $keys), errorKeys: $keys)]);
    }

    private static function accepted(MeasureRequest $r): OneResult
    {
        $out = [];
        foreach ($r->phases as $phase) {
            $out[] = new Attempt($r->route, $r->method, $r->scale, $r->axis, $phase, 200, 4, ['mysql' => 4], ['orders'], 90, 1000, false, 'ok', null);
        }

        return OneResult::ok($out);
    }

    private static function post(array $body = ['title' => 'x']): RouteTask
    {
        return new RouteTask(self::spec('/api/things', method: 'POST'), '/api/things', body: $body);
    }

    public function test_a_422_is_answered_with_a_body_rebuilt_from_the_fields_it_named(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => ($r->body['name'] ?? null) === 'x_1' ? self::accepted($r) : self::refused($r, ['name']));
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::post()], self::SCALES);

        $retry = $runner->requests[1];
        $this->assertSame(['title' => 'x', 'name' => 'x_1'], $retry->body, 'the first body is kept and the named field is added');
        $this->assertSame('ok', $result->report->rows()[0]['class']);
    }

    public function test_the_second_round_tries_a_number_and_the_third_an_array(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => is_array($r->body['tags'] ?? null) ? self::accepted($r) : self::refused($r, ['tags']));
        (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::post()], [100]);

        $this->assertSame('x_1', $runner->requests[1]->body['tags']);
        $this->assertSame(1, $runner->requests[2]->body['tags']);
        $this->assertSame([], $runner->requests[3]->body['tags']);
    }

    public function test_it_gives_up_after_three_rounds_and_records_the_422(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => self::refused($r, ['name']));
        $result = (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::post()], [100]);

        // the first request, then three rounds; the scale 0 point and the scale 100 point each do this once
        $this->assertCount(8, $runner->requests);
        $this->assertSame('validation_422', $result->report->rows()[0]['class']);
    }

    public function test_a_field_named_for_the_first_time_starts_with_a_string_and_the_ones_before_keep_their_value(): void
    {
        $runner = self::runner(static function (MeasureRequest $r, int $n): OneResult {
            if (!isset($r->body['a'])) {
                return self::refused($r, ['a']);
            }
            if (!isset($r->body['b'])) {
                return self::refused($r, ['b']);
            }

            return self::accepted($r);
        });
        (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::post()], [100]);

        $this->assertSame(['title' => 'x', 'a' => 'x_1', 'b' => 'x_1'], $runner->requests[2]->body);
    }

    public function test_a_body_that_worked_is_used_at_the_next_scale_without_asking_again(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => ($r->body['name'] ?? null) === 'x_1' ? self::accepted($r) : self::refused($r, ['name']));
        (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::post()], self::SCALES);

        $refusals = array_filter($runner->requests, static fn (MeasureRequest $r): bool => !isset($r->body['name']));
        $this->assertCount(1, $refusals, 'only the very first request carried the body that was refused');
        // scale 0: the refused request and its retry; the three other scales: one request each, with the body that worked
        $this->assertCount(5, $runner->requests);
    }

    public function test_a_get_is_never_rebuilt(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => self::refused($r, ['q']));
        $task = new RouteTask(self::spec('/api/search'), '/api/search');
        (new Driver(new SeederPlanner(), self::builder(), $runner))->run([$task], [100]);

        $this->assertCount(2, $runner->requests, 'one request for each of scale 0 and scale 100');
    }

    public function test_a_422_that_names_no_field_is_not_retried(): void
    {
        $runner = self::runner(static fn (MeasureRequest $r, int $n): OneResult => self::refused($r, []));
        (new Driver(new SeederPlanner(), self::builder(), $runner))->run([self::post()], [100]);

        $this->assertCount(2, $runner->requests);
    }
}
