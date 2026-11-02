<?php

declare(strict_types=1);

namespace PeakSim\Runtime\Tests\Docker\Data;

use PeakSim\Runtime\Tests\Docker\Data\Support\GenerationCase;
use PeakSim\Runtime\Tests\Docker\Support\ProbeApp;
use PHPUnit\Framework\Attributes\Group;

/**
 * The route table's middleware, read from the app's real router. An app that defines its own middleware groups in
 * `bootstrap/app.php` (as Firefly III does) names them in the routes; the table must open them, or the login that a
 * route needs is never planned. And the verification app, which defines none, must read exactly as it did.
 */
#[Group('in-stack')]
final class RouteMiddlewareDockerTest extends GenerationCase
{
    private const SCALE = 100;

    /** The verification app's own routes (the four route files); the framework adds a few of its own. */
    private const FIXTURE_PREFIXES = ['ok/', 'ng/', 'hidden/', 'unknown/'];

    private static ?ProbeApp $probe = null;

    public static function setUpBeforeClass(): void
    {
        self::requireInsideStack();
        self::fresh(self::SCALE);
        self::$probe = ProbeApp::create('/app', 'mw');
        $file = self::$probe->root . '/bootstrap/app.php';
        $text = (string) file_get_contents($file);
        $text = str_replace(
            "->withMiddleware(function (Middleware \$middleware): void {\n        //\n",
            "->withMiddleware(function (Middleware \$middleware): void {\n"
            . "        \$middleware->group('probe-user-auth', [\\Illuminate\\Auth\\Middleware\\Authenticate::class, 'throttle:60,1']);\n"
            . "        \$middleware->alias(['probe-alias' => \\Illuminate\\Auth\\Middleware\\Authenticate::class]);\n",
            $text,
            $count,
        );
        self::assertSame(1, $count, 'the verification app\'s bootstrap/app.php changed; update this test');
        file_put_contents($file, $text);
        file_put_contents(self::$probe->root . '/routes/probe.php', <<<'PHP'

            Route::get('mw/grouped', fn () => 'ok')->middleware('probe-user-auth');
            Route::get('mw/aliased', fn () => 'ok')->middleware('probe-alias:web');
            Route::get('mw/open', fn () => 'ok');
            Route::get('mw/left-out', fn () => 'ok')->middleware('probe-user-auth')->withoutMiddleware(\Illuminate\Auth\Middleware\Authenticate::class);

            PHP, FILE_APPEND);
    }

    public static function tearDownAfterClass(): void
    {
        self::$probe?->remove();
        self::drop(self::SCALE);
    }

    /** @return list<array<string, mixed>> */
    private static function routes(?string $appRoot): array
    {
        [$exit, $out, $err] = self::php(__DIR__ . '/Support/route_middleware.php', [(string) self::SCALE], $appRoot === null ? [] : ['PEAK_MEASURE_APP_ROOT' => $appRoot]);
        self::assertSame(0, $exit, $err . $out);

        return json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR)['routes'];
    }

    /** @return array<string, array<string, mixed>> by uri */
    private static function byUri(array $routes): array
    {
        $by = [];
        foreach ($routes as $route) {
            $by[$route['uri']] = $route;
        }

        return $by;
    }

    public function testTheVerificationAppsRoutesAreReadAsBefore(): void
    {
        $fixture = array_values(array_filter(
            self::routes(null),
            static fn (array $r): bool => array_filter(self::FIXTURE_PREFIXES, static fn (string $p): bool => str_starts_with($r['uri'], $p)) !== [],
        ));

        self::assertCount(24, $fixture, 'the verification app has 24 routes');
        foreach ($fixture as $route) {
            self::assertSame($route['before'], $route['after'], $route['uri'] . ': what the auth rules make of the route changed');
            self::assertFalse($route['after']['required'], $route['uri'] . ': the verification app has no route that asks for a login');
            self::assertSame($route['declared'], array_slice($route['table'], 0, count($route['declared'])), $route['uri'] . ': the declared names come first, unchanged');
        }
    }

    public function testAGroupTheAppDefinedIsOpenedSoTheLoginIsPlanned(): void
    {
        $routes = self::byUri(self::routes(self::$probe->root));

        $grouped = $routes['probe/mw/grouped'];
        self::assertSame(['web', 'probe-user-auth'], array_slice($grouped['declared'], -2), 'the app\'s router keeps the group name in the route');
        self::assertFalse($grouped['before']['required'], 'read by its declared names, the route looks open (the old blind spot)');
        self::assertTrue($grouped['after']['required']);
        self::assertContains('Illuminate\\Auth\\Middleware\\Authenticate', $grouped['table']);
    }

    public function testAnAliasWithAParameterIsOpenedAndKeepsItsGuard(): void
    {
        $aliased = self::byUri(self::routes(self::$probe->root))['probe/mw/aliased'];

        self::assertTrue($aliased['after']['required']);
        self::assertSame('web', $aliased['after']['guard']);
        self::assertContains('probe-alias:web', $aliased['table'], 'the name the route declared is still there');
    }

    public function testARouteThatLeavesTheMiddlewareOutIsNotMadeToNeedALogin(): void
    {
        $routes = self::byUri(self::routes(self::$probe->root));

        self::assertFalse($routes['probe/mw/left-out']['after']['required']);
        self::assertFalse($routes['probe/mw/open']['after']['required']);
    }
}
