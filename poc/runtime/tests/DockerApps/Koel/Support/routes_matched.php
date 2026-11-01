<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): starts the app and, for each entry of the profile's `unfillable` that
// names routes (`routes` => uri_prefix / middleware / action_prefix: every key given must match, one key's own list
// is any-of), prints how many of the app's real routes it matches. The matching here is the agreed shape, written out
// plainly so that this test does not depend on the code that applies it. Prints one JSON line:
//   {"entries": {"<name>": <number of routes>}, "routes": <number of routes in the table>}
//
//   php routes_matched.php <scale>

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Measure\RouteTable;

require dirname(__DIR__, 4) . '/bootstrap.php';

$booted = (new AppBooter())->boot(MeasureConfig::fromEnv(getenv()), (int) ($argv[1] ?? 0));
$rows = RouteTable::rows($booted->app);
$profile = (static fn (): array => require dirname(__DIR__, 4) . '/profiles/koel.php')();

$any = static function (array $needles, callable $test): bool {
    foreach ($needles as $needle) {
        if ($test($needle)) {
            return true;
        }
    }

    return false;
};

$entries = [];
foreach ($profile['unfillable'] as $name => $entry) {
    if (!isset($entry['routes'])) {
        continue;
    }
    $count = 0;
    foreach ($rows as $row) {
        $uri = ltrim($row['uri'], '/');
        $names = array_map(static fn (string $m): string => explode(':', $m, 2)[0], $row['middleware']);
        $r = $entry['routes'];
        $ok = (!isset($r['uri_prefix']) || $any($r['uri_prefix'], static fn (string $p): bool => str_starts_with($uri, $p)))
            && (!isset($r['middleware']) || $any($r['middleware'], static fn (string $m): bool => in_array($m, $names, true)))
            && (!isset($r['action_prefix']) || $any($r['action_prefix'], static fn (string $p): bool => str_starts_with((string) $row['action'], $p)));
        $count += $ok ? 1 : 0;
    }
    $entries[$name] = $count;
}

echo json_encode(['entries' => $entries, 'routes' => count($rows)], JSON_UNESCAPED_SLASHES), "\n";
