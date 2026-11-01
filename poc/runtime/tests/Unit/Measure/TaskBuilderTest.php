<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\AppFacts;
use PeakSim\Runtime\Measure\TaskBuilder;
use PeakSim\Runtime\Route\ArgumentResolver;
use PeakSim\Runtime\Route\BodyBuilder;
use PeakSim\Runtime\Route\RouteSpec;
use PeakSim\Runtime\Route\RowLookup;

final class TaskBuilderTest extends PlannerTestCase
{
    private static function lookup(): RowLookup
    {
        return new class implements RowLookup {
            public function tableForModel(string $modelClass): ?string
            {
                return $modelClass === 'App\\Models\\Order' ? 'orders' : null;
            }

            public function routeKeyColumn(string $modelClass): string
            {
                return 'id';
            }

            public function hasTable(string $table): bool
            {
                return in_array($table, ['orders', 'customers'], true);
            }

            public function primaryKey(string $table): ?string
            {
                return 'id';
            }

            public function firstValue(string $table, string $column, array $where = []): string|int|null
            {
                return $table === 'orders' ? 7 : null;
            }
        };
    }

    /** @param array<string, mixed>|null $rules */
    private static function facts(?string $user = 'App\\Models\\User:1', ?array $rules = null): AppFacts
    {
        return new class($user, $rules) implements AppFacts {
            /** @var list<string> */
            public array $asked = [];

            /** @param array<string, mixed>|null $rules */
            public function __construct(private readonly ?string $user, private readonly ?array $rules)
            {
            }

            public function loginUser(?string $guard): ?string
            {
                $this->asked[] = 'user:' . ($guard ?? '-');

                return $this->user === null ? null : $this->user . ($guard === null ? '' : ':' . $guard);
            }

            public function bodyRules(?string $action): ?array
            {
                $this->asked[] = 'rules:' . ($action ?? '-');

                return $this->rules;
            }
        };
    }

    private static function builder(?AppFacts $facts = null): TaskBuilder
    {
        $lookup = self::lookup();

        return new TaskBuilder(new ArgumentResolver($lookup), new BodyBuilder($lookup), $facts ?? self::facts());
    }

    /** @param array<string, mixed> $parameters */
    private static function withParameters(string $uri, array $parameters, string $method = 'GET', array $middleware = [], bool $inRuntime = true): RouteSpec
    {
        return new RouteSpec($uri, $method, [$method], $middleware, $parameters, 'A@b', ['n_table' => 'orders', 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => []], $inRuntime);
    }

    public function test_a_plain_get_route_needs_nothing(): void
    {
        $task = self::builder()->build(self::spec('/ok/eager', [], 'GET', ['web']));

        $this->assertNull($task->failClass);
        $this->assertSame('/ok/eager', $task->uri);
        $this->assertNull($task->actAs);
        $this->assertNull($task->body);
        $this->assertFalse($task->csrf);
    }

    public function test_a_route_the_booted_app_does_not_have_is_not_measured(): void
    {
        $task = self::builder()->build(self::withParameters('/gone', [], inRuntime: false));

        $this->assertSame('not_in_runtime', $task->failClass);
        $this->assertNull($task->uri);
    }

    public function test_parameters_are_filled_from_the_first_row(): void
    {
        $task = self::builder()->build(self::withParameters('/orders/{order}', ['order' => ['optional' => false, 'where' => null, 'model' => 'App\\Models\\Order', 'field' => null, 'type' => null, 'scope' => null]]));

        $this->assertNull($task->failClass);
        $this->assertSame('/orders/7', $task->uri);
    }

    public function test_an_unfillable_parameter_is_a_reason_not_a_guess(): void
    {
        $task = self::builder()->build(self::withParameters('/files/{file}', ['file' => ['optional' => false, 'where' => null, 'model' => null, 'field' => null, 'type' => null, 'scope' => null]]));

        $this->assertSame('unfilled_param', $task->failClass);
        $this->assertStringContainsString('file', (string) $task->failDetail);
        $this->assertNull($task->uri);
    }

    public function test_a_route_behind_auth_is_called_as_the_first_user_of_its_guard(): void
    {
        $facts = self::facts('App\\Models\\User:1');
        $task = self::builder($facts)->build(self::spec('/me', [], 'GET', ['web', 'auth:sanctum']));

        $this->assertSame('App\\Models\\User:1:sanctum', $task->actAs);
        $this->assertSame(['user:sanctum'], $facts->asked);
    }

    public function test_a_route_behind_auth_with_no_user_row_is_a_reason(): void
    {
        $task = self::builder(self::facts(null))->build(self::spec('/me', [], 'GET', ['auth']));

        $this->assertSame('no_login_user', $task->failClass);
    }

    public function test_a_route_that_needs_no_login_does_not_ask_for_a_user(): void
    {
        $facts = self::facts();
        self::builder($facts)->build(self::spec('/open', [], 'GET', ['web']));

        $this->assertSame([], $facts->asked);
    }

    public function test_a_write_through_the_web_group_sends_the_csrf_token(): void
    {
        $task = self::builder()->build(self::spec('/orders', [], 'POST', ['web']));

        $this->assertTrue($task->csrf);
    }

    public function test_a_write_outside_the_web_group_does_not(): void
    {
        $task = self::builder()->build(self::spec('/api/orders', [], 'POST', ['api']));

        $this->assertFalse($task->csrf);
    }

    public function test_a_write_without_a_form_request_is_tried_with_an_empty_body(): void
    {
        $task = self::builder(self::facts('App\\Models\\User:1', null))->build(self::spec('/orders', [], 'POST', ['web']));

        $this->assertNull($task->failClass);
        $this->assertSame([], $task->body);
    }

    public function test_the_body_comes_from_the_form_request_rules(): void
    {
        $task = self::builder(self::facts('App\\Models\\User:1', ['name' => 'required|string', 'email' => 'required|email']))->build(self::spec('/orders', [], 'POST', ['web']));

        $this->assertNull($task->failClass);
        $this->assertSame(['name', 'email'], array_keys((array) $task->body));
        $this->assertStringContainsString('@', (string) $task->body['email']);
    }

    public function test_a_body_that_needs_a_file_is_not_measured(): void
    {
        $task = self::builder(self::facts('App\\Models\\User:1', ['avatar' => 'required|file']))->build(self::spec('/orders', [], 'POST', ['web']));

        $this->assertSame('file_dependency', $task->failClass);
    }

    public function test_a_body_field_that_cannot_be_filled_is_a_reason(): void
    {
        $task = self::builder(self::facts('App\\Models\\User:1', ['customer_id' => 'required|exists:nowhere,id']))->build(self::spec('/orders', [], 'POST', ['web']));

        $this->assertSame('unfilled_param', $task->failClass);
        $this->assertStringContainsString('customer_id', (string) $task->failDetail);
    }

    public function test_a_get_route_never_asks_for_rules(): void
    {
        $facts = self::facts();
        self::builder($facts)->build(self::spec('/ok/eager', [], 'GET', ['web']));

        $this->assertNotContains('rules:A@b', $facts->asked);
    }

    public function test_a_task_survives_json(): void
    {
        $task = self::builder()->build(self::withParameters('/orders/{order}', ['order' => ['optional' => false, 'where' => null, 'model' => 'App\\Models\\Order', 'field' => null, 'type' => null, 'scope' => null]], 'POST', ['web', 'auth']));
        $copy = \PeakSim\Runtime\Measure\RouteTask::fromArray(json_decode(json_encode($task->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));

        $this->assertEquals($task, $copy);
    }
}
