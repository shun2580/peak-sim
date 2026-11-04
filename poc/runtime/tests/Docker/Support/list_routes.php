<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): prints, as JSON, every route of the target app that can be sent
// without filling a parameter: [{"method": "GET", "uri": "/ok/eager"}, ...]. The database of the scale must exist.
//   php list_routes.php <scale>

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;

require dirname(__DIR__, 3) . '/bootstrap.php';

$config = MeasureConfig::fromEnv(getenv());
$booted = (new AppBooter())->boot($config, (int) ($argv[1] ?? 0));

$routes = [];
foreach ($booted->app['router']->getRoutes()->getRoutes() as $route) {
    $uri = '/' . ltrim((string) $route->uri(), '/');
    if (str_contains($uri, '{') || str_starts_with($uri, '/up') || str_starts_with($uri, '/storage')) {
        continue;
    }
    $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
    if ($methods === []) {
        continue;
    }
    $routes[] = ['method' => $methods[0], 'uri' => $uri];
}
usort($routes, static fn (array $a, array $b): int => [$a['uri'], $a['method']] <=> [$b['uri'], $b['method']]);

echo json_encode($routes, JSON_UNESCAPED_SLASHES), "\n";
