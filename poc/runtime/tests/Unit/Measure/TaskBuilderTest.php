<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\AppFacts;
use PeakSim\Runtime\Measure\TaskBuilder;
use PeakSim\Runtime\Route\ArgumentResolver;
use PeakSim\Runtime\Route\BodyBuilder;
use PeakSim\Runtime\Route\RouteSpec;
use PeakSim\Runtime\Route\RowLookup;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\AlbumController;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\InvokableAlbumController;

require_once __DIR__ . '/Stubs/RouteTableStubs.php';

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

            public function loginUser(?string $guard, string|int|null $id = null): ?string
            {
                $this->asked[] = 'user:' . ($guard ?? '-') . ($id === null ? '' : '#' . $id);
                if ($this->user === null) {
                    return null;
                }
                $user = $id === null ? $this->user : substr($this->user, 0, (int) strrpos($this->user, ':')) . ':' . $id;

                return $user . ($guard === null ? '' : ':' . $guard);
            }

            public function bodyRules(?string $action): ?array
            {
                $this->asked[] = 'rules:' . ($action ?? '-');

                return $this->rules;
            }
        };
    }

    /** @param array<string, mixed> $profile */
    private static function builder(?AppFacts $facts = null, array $profile = []): TaskBuilder
    {
        $lookup = self::lookup();

        return new TaskBuilder(new ArgumentResolver($lookup, $profile), new BodyBuilder($lookup), $facts ?? self::facts(), $profile);
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

    private static function withAction(string $action, string $uri = '/albums/{album}', string $method = 'GET'): RouteSpec
    {
        $album = ['optional' => false, 'where' => null, 'model' => 'App\\Models\\Order', 'field' => null, 'type' => null, 'scope' => null];

        return new RouteSpec($uri, $method, [$method], ['web'], str_contains($uri, '{') ? ['album' => $album] : [], $action, ['n_table' => 'orders', 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => []], true);
    }

    public function test_an_operation_the_controller_does_not_have_is_recorded_and_not_hit(): void
    {
        $facts = self::facts();
        $task = self::builder($facts)->build(self::withAction(AlbumController::class . '@destroy', method: 'DELETE'));

        $this->assertSame('action_not_defined', $task->failClass);
        $this->assertStringContainsString('destroy', (string) $task->failDetail);
        $this->assertNull($task->uri);
        $this->assertNull($task->body);
        $this->assertSame([], $facts->asked, 'nothing about the app is asked for a route that is not going to be hit');
    }

    public function test_it_is_told_apart_from_a_parameter_that_cannot_be_filled(): void
    {
        $spec = new RouteSpec('/files/{file}', 'DELETE', ['DELETE'], [], ['file' => ['optional' => false, 'where' => null, 'model' => null, 'field' => null, 'type' => null, 'scope' => null]], AlbumController::class . '@destroy', ['n_table' => null, 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => []], true);

        $this->assertSame('action_not_defined', self::builder()->build($spec)->failClass);
    }

    public function test_an_operation_the_controller_has_is_prepared_as_before(): void
    {
        $task = self::builder()->build(self::withAction(AlbumController::class . '@show'));

        $this->assertNull($task->failClass);
        $this->assertSame('/albums/7', $task->uri);
    }

    public function test_an_invokable_controller_is_prepared_as_before(): void
    {
        $task = self::builder()->build(self::withAction(InvokableAlbumController::class));

        $this->assertNull($task->failClass);
    }

    public function test_the_route_the_booted_app_does_not_have_is_still_not_in_runtime(): void
    {
        $spec = new RouteSpec('/gone', 'DELETE', ['DELETE'], [], [], AlbumController::class . '@destroy', ['n_table' => null, 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => []], false);

        $this->assertSame('not_in_runtime', self::builder()->build($spec)->failClass);
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

    public function test_a_route_behind_a_gate_the_profile_names_is_called_as_the_user_of_the_gates_guard(): void
    {
        $profile = ['user' => ['id' => 3], 'admin' => ['id' => 9], 'auth_gates' => ['g' => ['middleware' => ['CheckLogin'], 'guard' => 'shopper', 'as' => 'user']]];
        $facts = self::facts('App\\Models\\User:1');

        $task = self::builder($facts, $profile)->build(self::spec('/account', [], 'GET', ['web', 'App\\Http\\Middleware\\CheckLogin']));

        $this->assertNull($task->failClass);
        $this->assertSame('App\\Models\\User:3:shopper', $task->actAs);
        $this->assertSame(['user:shopper#3'], $facts->asked);
    }

    public function test_a_gate_that_enters_as_the_admin_asks_for_the_admin_row(): void
    {
        $profile = ['user' => ['id' => 3], 'admin' => ['id' => 9], 'auth_gates' => ['g' => ['middleware' => ['CheckStaff'], 'guard' => 'staff', 'as' => 'admin']]];
        $facts = self::facts('App\\Models\\Admin:1');

        $task = self::builder($facts, $profile)->build(self::spec('/office', [], 'GET', ['web', 'CheckStaff']));

        $this->assertSame('App\\Models\\Admin:9:staff', $task->actAs);
        $this->assertSame(['user:staff#9'], $facts->asked);
    }

    public function test_the_same_route_without_the_gate_in_the_profile_is_not_logged_in(): void
    {
        $facts = self::facts();

        $task = self::builder($facts, ['user' => ['id' => 3]])->build(self::spec('/account', [], 'GET', ['web', 'CheckLogin']));

        $this->assertNull($task->actAs);
        $this->assertSame([], $facts->asked);
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

    // ---- with a profile ---------------------------------------------------------------------------------------

    private const PROFILE = [
        'user' => ['id' => 1, 'role' => 'user'],
        'admin' => ['id' => 2, 'role' => 'admin'],
        'unfillable' => ['dropbox_key' => ['params' => ['key'], 'reason' => 'external_call_blocked', 'why' => 'calls the Dropbox service']],
    ];

    public function test_with_a_profile_the_login_is_the_row_it_names(): void
    {
        $facts = self::facts('App\\Models\\User:1');
        $task = self::builder($facts, self::PROFILE)->build(self::spec('/me', [], 'GET', ['web', 'auth:sanctum']));

        $this->assertSame('App\\Models\\User:1:sanctum', $task->actAs);
        $this->assertSame(['user:sanctum#1'], $facts->asked);
    }

    public function test_with_login_as_admin_the_first_request_is_made_as_the_admin(): void
    {
        $facts = self::facts('App\\Models\\User:1');
        $task = self::builder($facts, ['login_as' => 'admin'] + self::PROFILE)->build(self::spec('/users', [], 'GET', ['web', 'auth']));

        $this->assertSame('App\\Models\\User:2', $task->actAs);
        $this->assertSame(['user:-#2'], $facts->asked);
    }

    public function test_a_profile_with_no_login_rows_leaves_the_first_row_login_alone(): void
    {
        $facts = self::facts('App\\Models\\User:1');
        $task = self::builder($facts, ['skip' => ['roles']])->build(self::spec('/me', [], 'GET', ['auth']));

        $this->assertSame('App\\Models\\User:1', $task->actAs);
        $this->assertSame(['user:-'], $facts->asked);
    }

    public function test_a_parameter_the_profile_explains_is_recorded_with_the_profiles_class(): void
    {
        $task = self::builder(null, self::PROFILE)->build(self::withParameters('/dropbox/{key}', ['key' => ['optional' => false, 'where' => null, 'model' => null, 'field' => null, 'type' => null, 'scope' => null]]));

        $this->assertSame('external_call_blocked', $task->failClass);
        $this->assertStringContainsString('key', (string) $task->failDetail);
        $this->assertStringContainsString('dropbox_key', (string) $task->failDetail);
        $this->assertNull($task->uri);
    }

    public function test_a_parameter_the_profile_does_not_explain_is_still_unfilled_param(): void
    {
        $task = self::builder(null, self::PROFILE)->build(self::withParameters('/files/{file}', ['file' => ['optional' => false, 'where' => null, 'model' => null, 'field' => null, 'type' => null, 'scope' => null]]));

        $this->assertSame('unfilled_param', $task->failClass);
    }

    private const ROUTED = [
        'unfillable' => [
            'subsonic' => ['routes' => ['uri_prefix' => ['rest/']], 'reason' => 'unauthenticated', 'why' => 'a token made from an encrypted key'],
            'signed' => ['routes' => ['middleware' => ['signed']], 'reason' => 'signature_required', 'why' => 'a signed URL'],
            'a_file' => ['routes' => ['uri_prefix' => ['files/']], 'params' => ['file'], 'reason' => 'file_dependency', 'why' => 'x'],
        ],
    ];

    public function test_a_route_the_profile_explains_is_recorded_with_the_profiles_class_and_not_prepared_further(): void
    {
        $facts = self::facts();
        $task = self::builder($facts, self::ROUTED)->build(self::spec('rest/ping', [], 'GET', ['web', 'auth']));

        $this->assertSame('unauthenticated', $task->failClass);
        $this->assertStringContainsString('subsonic', (string) $task->failDetail);
        $this->assertStringContainsString('encrypted key', (string) $task->failDetail);
        $this->assertNull($task->uri);
        $this->assertSame([], $facts->asked, 'nothing is asked of the app for a route that is not going to be hit');
    }

    public function test_a_route_is_explained_by_its_middleware_too(): void
    {
        $task = self::builder(null, self::ROUTED)->build(self::spec('/confirm', [], 'GET', ['web', 'signed']));

        $this->assertSame('signature_required', $task->failClass);
    }

    public function test_an_entry_about_an_argument_in_a_route_does_not_fail_the_whole_route(): void
    {
        $task = self::builder(null, self::ROUTED)->build(self::spec('/files/list', [], 'GET', ['web']));

        $this->assertNull($task->failClass);
    }

    public function test_a_route_the_booted_app_does_not_have_is_not_in_runtime_before_any_profile_reason(): void
    {
        $task = self::builder(null, self::ROUTED)->build(self::withParameters('rest/ping', [], inRuntime: false));

        $this->assertSame('not_in_runtime', $task->failClass);
    }

    public function test_the_arguments_that_were_guessed_travel_with_the_task(): void
    {
        $task = self::builder()->build(self::withParameters('/x/{n}', ['n' => ['optional' => false, 'where' => null, 'model' => null, 'field' => null, 'type' => 'int', 'scope' => null]]));

        $this->assertNull($task->failClass);
        $this->assertSame(['n'], $task->guessed);
        $this->assertSame(['n'], \PeakSim\Runtime\Measure\RouteTask::fromArray($task->toArray())->guessed);
    }

    public function test_no_guess_means_an_empty_list(): void
    {
        $task = self::builder()->build(self::withParameters('/orders/{order}', ['order' => ['optional' => false, 'where' => null, 'model' => 'App\\Models\\Order', 'field' => null, 'type' => null, 'scope' => null]]));

        $this->assertSame([], $task->guessed);
    }

    // ---- credentials in the query of a GET (request_query) ----------------------------------------------------------

    private const QUERIED = [
        'user' => ['id' => 1, 'role' => 'user', 'email' => 'user@measure.invalid'],
        'admin' => ['id' => 2, 'role' => 'admin', 'email' => 'admin@measure.invalid'],
        'request_query' => [
            'subsonic' => ['routes' => ['uri_prefix' => ['rest/']], 'query' => ['u' => '{email}', 'apiKey' => '{key:subsonic}']],
            'admin_feed' => ['routes' => ['uri_prefix' => ['feed/']], 'as' => 'admin', 'query' => ['u' => '{email}', 'apiKey' => '{key:subsonic}']],
        ],
    ];

    public function test_a_get_route_the_profile_names_carries_the_logins_email_and_a_token_for_its_key(): void
    {
        $facts = self::facts();
        $task = self::builder($facts, self::QUERIED)->build(self::spec('rest/ping', [], 'GET', ['web']));

        $this->assertNull($task->failClass);
        $this->assertSame('/rest/ping', '/' . ltrim((string) $task->uri, '/'));
        $this->assertSame(['u' => 'user@measure.invalid', 'apiKey' => '{key:subsonic:1}'], $task->query);
        $this->assertNull($task->actAs, 'the credentials are in the query, not in a session login');
    }

    public function test_the_entry_may_ask_for_the_admin_and_the_default_follows_login_as(): void
    {
        $admin = self::builder(null, self::QUERIED)->build(self::spec('feed/x', [], 'GET', []));
        $this->assertSame(['u' => 'admin@measure.invalid', 'apiKey' => '{key:subsonic:2}'], $admin->query);

        $loginAsAdmin = self::builder(null, ['login_as' => 'admin'] + self::QUERIED)->build(self::spec('rest/ping', [], 'GET', []));
        $this->assertSame(['u' => 'admin@measure.invalid', 'apiKey' => '{key:subsonic:2}'], $loginAsAdmin->query);
    }

    public function test_the_task_says_which_entry_gave_the_credentials_and_what_names_they_have_but_never_a_value(): void
    {
        $task = self::builder(null, self::QUERIED)->build(self::spec('rest/ping', [], 'GET', []));

        $this->assertNotNull($task->credentials);
        $this->assertStringContainsString('subsonic', (string) $task->credentials);
        $this->assertStringContainsString('u', (string) $task->credentials);
        $this->assertStringContainsString('apiKey', (string) $task->credentials);
        $this->assertStringNotContainsString('measure.invalid', (string) $task->credentials);
        $this->assertStringNotContainsString('{key', (string) $task->credentials);
    }

    public function test_only_the_routes_the_entry_names_carry_a_query(): void
    {
        $task = self::builder(null, self::QUERIED)->build(self::spec('/api/songs', [], 'GET', ['web']));

        $this->assertSame([], $task->query);
        $this->assertNull($task->credentials);
    }

    public function test_a_route_that_is_not_a_get_carries_no_query_even_when_its_uri_is_named(): void
    {
        $task = self::builder(null, self::QUERIED)->build(self::spec('rest/ping', [], 'POST', []));

        $this->assertSame([], $task->query);
        $this->assertNull($task->credentials);
    }

    public function test_with_no_request_query_in_the_profile_a_task_is_what_it_was_before(): void
    {
        foreach ([[], self::PROFILE, self::ROUTED] as $profile) {
            $task = self::builder(null, $profile)->build(self::spec('rest/ping', [], 'GET', ['web']));

            $this->assertSame([], $task->query);
            $this->assertNull($task->credentials);
        }
    }

    public function test_an_entry_that_carries_credentials_wins_over_an_unfillable_entry_for_the_same_routes(): void
    {
        $profile = self::QUERIED + ['unfillable' => ['subsonic' => ['routes' => ['uri_prefix' => ['rest/']], 'reason' => 'unauthenticated', 'why' => 'old']]];
        $task = self::builder(null, $profile)->build(self::spec('rest/ping', [], 'GET', []));

        $this->assertNull($task->failClass);
        $this->assertSame('user@measure.invalid', $task->query['u']);
    }

    public function test_a_route_with_arguments_still_has_them_filled_and_carries_the_query(): void
    {
        $format = ['optional' => true, 'where' => null, 'model' => null, 'field' => null, 'type' => null, 'scope' => null];
        $profile = self::QUERIED + ['arguments' => ['format' => ['literal' => 'json', 'why' => 'the response format']]];
        $spec = new RouteSpec('rest/ping{format?}', 'GET', ['GET'], [], ['format' => $format], 'A@b', ['n_table' => null, 'm_table' => null, 'm_per' => null, 'n_table_alternatives' => []], true);
        $task = self::builder(null, $profile)->build($spec);

        $this->assertNull($task->failClass, (string) $task->failDetail);
        $this->assertSame('user@measure.invalid', $task->query['u']);
    }

    public function test_a_task_with_a_query_survives_json_and_an_older_task_without_one_still_loads(): void
    {
        $task = self::builder(null, self::QUERIED)->build(self::spec('rest/ping', [], 'GET', []));
        $copy = \PeakSim\Runtime\Measure\RouteTask::fromArray(json_decode(json_encode($task->toArray(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
        $this->assertEquals($task, $copy);

        $old = $task->toArray();
        unset($old['query'], $old['credentials']);
        $this->assertSame([], \PeakSim\Runtime\Measure\RouteTask::fromArray($old)->query);
    }

    public function test_the_task_holds_the_token_not_the_key(): void
    {
        $task = self::builder(null, self::QUERIED)->build(self::spec('rest/ping', [], 'GET', []));

        $this->assertStringNotContainsString('measure-subsonic', json_encode($task->toArray(), JSON_THROW_ON_ERROR));
    }
}
