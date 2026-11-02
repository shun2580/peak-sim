<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): starts the app and, for each route it registered, prints the middleware
// the route declares (Route::gatherMiddleware(), group and alias names as written), the middleware the route table
// reports, and what the auth rules make of each. One JSON line:
//   {"routes": [{"uri", "methods", "declared": [...], "table": [...], "before": {...}, "after": {...}}, ...]}
// where before/after are UserProvider::requirement() of the declared and of the table's list.
//
//   php route_middleware.php <scale>      (PEAK_MEASURE_APP_ROOT names the app)

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Measure\RouteTable;
use PeakSim\Runtime\Route\UserProvider;

require dirname(__DIR__, 4) . '/bootstrap.php';

$booted = (new AppBooter())->boot(MeasureConfig::fromEnv(getenv()), (int) ($argv[1] ?? 0));
$app = $booted->app;

$summary = static function (array $middleware): array {
    $r = UserProvider::requirement($middleware);

    return ['required' => $r->required, 'guard' => $r->guard, 'verified' => $r->verified, 'ability' => $r->ability, 'guest_only' => $r->guestOnly];
};

$declared = [];
foreach ($app['router']->getRoutes()->getRoutes() as $route) {
    $declared[$route->methods()[0] . ' ' . $route->uri()] = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
}

$routes = [];
foreach (RouteTable::rows($app) as $row) {
    $before = $declared[$row['methods'][0] . ' ' . $row['uri']];
    $routes[] = [
        'uri' => $row['uri'],
        'methods' => $row['methods'],
        'declared' => $before,
        'table' => $row['middleware'],
        'before' => $summary($before),
        'after' => $summary($row['middleware']),
    ];
}

echo json_encode(['routes' => $routes], JSON_UNESCAPED_SLASHES), "\n";
