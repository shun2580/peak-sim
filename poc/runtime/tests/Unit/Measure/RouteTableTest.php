<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\RouteTable;
use PeakSim\Runtime\Route\UserProvider;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\Album;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\AlbumController;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\EmptyController;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\FakeApp;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\FakeRoute;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\FakeRouterMiddleware;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\InvokableAlbumController;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\InvokableWithoutDestroyController;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/RouteTableStubs.php';

/** What RouteTable says about each `{parameter}` of a route: a model (typed binding), a scalar, or nothing known. */
final class RouteTableTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private static function parametersOf(FakeRoute $route): array
    {
        $rows = RouteTable::rows(new FakeApp([$route]));
        self::assertCount(1, $rows);

        return $rows[0]['parameters'];
    }

    public function test_an_invokable_controller_gives_its_typed_parameters(): void
    {
        $route = new FakeRoute('albums/{album}/{page}', InvokableAlbumController::class, ['album', 'page']);

        $parameters = self::parametersOf($route);

        $this->assertSame(Album::class, $parameters['album']['model']);
        $this->assertNull($parameters['album']['type']);
        $this->assertNull($parameters['page']['model']);
        $this->assertSame('int', $parameters['page']['type']);
    }

    public function test_a_route_that_names_its_method_is_unchanged(): void
    {
        $route = new FakeRoute('albums/{album}', AlbumController::class . '@show', ['album']);

        $this->assertSame(Album::class, self::parametersOf($route)['album']['model']);
    }

    public function test_a_named_method_that_does_not_exist_is_not_taken_for_the_invoke_method(): void
    {
        // apiResource registers destroy even when the controller has no destroy: the route must not borrow __invoke's types.
        $route = new FakeRoute('albums/{album}', InvokableWithoutDestroyController::class . '@destroy', ['album']);

        $parameters = self::parametersOf($route);

        $this->assertNull($parameters['album']['model']);
        $this->assertNull($parameters['album']['type']);
    }

    public function test_a_controller_with_no_such_method_and_no_invoke_gives_nothing(): void
    {
        $bare = new FakeRoute('x/{id}', EmptyController::class . '@show', ['id']);
        $invokedBare = new FakeRoute('y/{id}', EmptyController::class, ['id']);

        $this->assertNull(self::parametersOf($bare)['id']['model']);
        $this->assertNull(self::parametersOf($invokedBare)['id']['model']);
    }

    public function test_a_row_keeps_its_action_name_as_the_app_reports_it(): void
    {
        $rows = RouteTable::rows(new FakeApp([new FakeRoute('albums/{album}', InvokableAlbumController::class, ['album'])]));

        $this->assertSame(InvokableAlbumController::class, $rows[0]['action']);
    }

    private const AUTHENTICATE = 'Illuminate\\Auth\\Middleware\\Authenticate';

    /** @return list<string> */
    private static function middlewareOf(FakeRoute $route, ?FakeRouterMiddleware $definitions): array
    {
        $rows = RouteTable::rows(new FakeApp([$route], $definitions));
        self::assertCount(1, $rows);

        return $rows[0]['middleware'];
    }

    public function test_a_group_the_app_defined_is_read_as_the_middleware_it_holds(): void
    {
        // The app's own group (Firefly III's `user-full-auth`) is not a name the auth rules know; its members are.
        $definitions = new FakeRouterMiddleware(['user-full-auth' => ['App\\Http\\Middleware\\Authenticate', 'App\\Http\\Middleware\\Range']]);
        $route = new FakeRoute('transactions/edit/{id}', AlbumController::class . '@show', [], ['web', 'user-full-auth']);

        $middleware = self::middlewareOf($route, $definitions);

        $this->assertContains('App\\Http\\Middleware\\Authenticate', $middleware);
        $this->assertTrue(UserProvider::requirement($middleware)->required, 'the login is planned once the group is opened');
    }

    public function test_the_same_route_read_without_the_expansion_is_the_old_blind_spot(): void
    {
        // What the table said before: the group name alone. Kept as a test so the reason for the change is on record.
        $route = new FakeRoute('transactions/edit/{id}', AlbumController::class . '@show', [], ['web', 'user-full-auth']);

        $middleware = self::middlewareOf($route, null);

        $this->assertSame(['web', 'user-full-auth'], $middleware);
        $this->assertFalse(UserProvider::requirement($middleware)->required);
    }

    public function test_a_group_inside_a_group_is_opened_all_the_way(): void
    {
        $definitions = new FakeRouterMiddleware(['outer' => ['inner', 'throttle'], 'inner' => [self::AUTHENTICATE . ':sanctum']]);
        $route = new FakeRoute('me', AlbumController::class . '@show', [], ['api', 'outer']);

        $requirement = UserProvider::requirement(self::middlewareOf($route, $definitions));

        $this->assertTrue($requirement->required);
        $this->assertSame('sanctum', $requirement->guard);
    }

    public function test_an_alias_with_a_parameter_is_expanded_and_keeps_its_parameter(): void
    {
        $definitions = new FakeRouterMiddleware([], ['auth' => self::AUTHENTICATE]);
        $route = new FakeRoute('me', AlbumController::class . '@show', [], ['auth:api']);

        $middleware = self::middlewareOf($route, $definitions);

        $this->assertContains(self::AUTHENTICATE . ':api', $middleware);
        $this->assertSame('api', UserProvider::requirement($middleware)->guard);
    }

    public function test_the_names_the_route_declared_stay_so_the_rules_that_read_them_keep_their_answer(): void
    {
        // `auth.basic` and a profile's `signed` are matched by their alias; opening them must not take them away.
        $definitions = new FakeRouterMiddleware(
            ['web' => ['Illuminate\\Cookie\\Middleware\\EncryptCookies']],
            ['auth.basic' => 'Illuminate\\Auth\\Middleware\\AuthenticateWithBasicAuth', 'signed' => 'Illuminate\\Routing\\Middleware\\ValidateSignature'],
        );
        $route = new FakeRoute('files/{id}', AlbumController::class . '@show', [], ['web', 'auth.basic', 'signed']);

        $middleware = self::middlewareOf($route, $definitions);

        $this->assertSame(['web', 'auth.basic', 'signed'], array_slice($middleware, 0, 3), 'declared names first, in the declared order');
        $this->assertContains('Illuminate\\Cookie\\Middleware\\EncryptCookies', $middleware);
        $this->assertTrue(UserProvider::requirement($middleware)->required);
    }

    public function test_a_name_appears_once(): void
    {
        $definitions = new FakeRouterMiddleware(['g' => [self::AUTHENTICATE, 'throttle']]);
        $route = new FakeRoute('x', AlbumController::class . '@show', [], ['g', self::AUTHENTICATE, 'throttle']);

        $middleware = self::middlewareOf($route, $definitions);

        $this->assertSame(array_values(array_unique($middleware)), $middleware);
    }

    public function test_a_route_that_leaves_out_a_middleware_does_not_get_it_back_from_its_group(): void
    {
        $definitions = new FakeRouterMiddleware(['user-full-auth' => [self::AUTHENTICATE]]);
        $route = new FakeRoute('login', AlbumController::class . '@show', [], ['web', 'user-full-auth'], [self::AUTHENTICATE]);

        $middleware = self::middlewareOf($route, $definitions);

        $this->assertNotContains(self::AUTHENTICATE, $middleware);
        $this->assertFalse(UserProvider::requirement($middleware)->required);
    }

    public function test_leaving_out_an_alias_removes_it_from_the_declared_names_too(): void
    {
        $definitions = new FakeRouterMiddleware(['g' => ['auth']], ['auth' => self::AUTHENTICATE]);
        $route = new FakeRoute('login', AlbumController::class . '@show', [], ['g', 'auth'], ['auth']);

        $middleware = self::middlewareOf($route, $definitions);

        $this->assertNotContains('auth', $middleware);
        $this->assertFalse(UserProvider::requirement($middleware)->required);
    }

    public function test_a_route_with_nothing_declared_has_nothing(): void
    {
        $route = new FakeRoute('open', AlbumController::class . '@show', [], []);

        $this->assertSame([], self::middlewareOf($route, new FakeRouterMiddleware(['web' => ['x']])));
    }

    public function test_a_route_whose_names_are_not_groups_or_aliases_is_unchanged(): void
    {
        $route = new FakeRoute('ok', AlbumController::class . '@show', [], ['web', 'throttle:60,1']);

        $this->assertSame(['web', 'throttle:60,1'], self::middlewareOf($route, new FakeRouterMiddleware()));
    }
}
