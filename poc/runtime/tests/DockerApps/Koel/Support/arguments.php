<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): starts the app on the measurement database of one scale, reads its
// route table and runs the tool's ArgumentResolver over every controller route, with no static analysis at hand (the
// routes are taken from the app itself). Prints one JSON line:
//   {"routes": [{"uri", "method", "params": [names], "unfilled": [{"param", "reason"}]}], "laravel": "..."}
//
//   php arguments.php <scale>

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Measure\EloquentRowLookup;
use PeakSim\Runtime\Measure\RouteTable;
use PeakSim\Runtime\Route\ArgumentResolver;
use PeakSim\Runtime\Route\RouteCatalog;

require dirname(__DIR__, 4) . '/bootstrap.php';

$booted = (new AppBooter())->boot(MeasureConfig::fromEnv(getenv()), (int) ($argv[1] ?? 0));
$app = $booted->app;
$rows = RouteTable::rows($app);

$analysis = ['routes' => []];
foreach ($rows as $row) {
    $analysis['routes'][] = [
        'route' => '/' . ltrim($row['uri'], '/'),
        'methods' => $row['methods'],
        'action' => $row['action'],
        'kind' => $row['action'] === 'Closure' ? 'closure' : 'controller',
    ];
}
$catalog = RouteCatalog::build($analysis, $rows);
$resolver = new ArgumentResolver(new EloquentRowLookup($app));

$routes = [];
foreach ($catalog->specs() as $spec) {
    if ($spec->parameters === []) {
        continue;
    }
    $resolution = $resolver->resolve($spec);
    $routes[] = ['uri' => $spec->uri, 'method' => $spec->method, 'params' => array_keys($spec->parameters), 'unfilled' => $resolution->unfilled];
}

echo json_encode(['routes' => $routes, 'laravel' => $app->version()], JSON_UNESCAPED_SLASHES), "\n";
