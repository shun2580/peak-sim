<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Unit\Measure;

use PeakSim\Runtime\Measure\RouteTable;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\Album;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\AlbumController;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\EmptyController;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\FakeApp;
use PeakSim\Runtime\Tests\Unit\Measure\Stubs\FakeRoute;
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
}
