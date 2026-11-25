<?php

declare(strict_types=1);

// Runs INSIDE the tools container (poc/lab/bin/targets). Starts BookStack on the lab database of one scale, lets the
// measurement's own inspection (`Measure\Inspection`, with the profile of the app: `bookstack` unless PEAK_LAB_PROFILE says another) fill the arguments of every route
// of the static analysis, and writes the concrete paths of the routes of the route list to /out/targets-<scale>.json.
// Sends no request. Exit codes: 0 / 2 usage / 10 refused / 12 the app did not start / 13 the profile or route list cannot be used.

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Boot\BootFailure;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\LabEntry\LoginChoice;
use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\Inspection;
use PeakSim\Runtime\Measure\MeasureExit;
use PeakSim\Runtime\Measure\Options;
use PeakSim\Runtime\Measure\Profile;

require dirname(__DIR__, 3) . '/runtime/bootstrap.php';

$scale = null;
$routesFile = '/peak-sim/poc/lab/routes/route-list.json';
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--scale=([0-9]{1,7})$/D', $arg, $m) === 1) {
        $scale = (int) $m[1];
    } elseif (preg_match('/^--routes=(\/[^\0]+)$/D', $arg, $m) === 1 && !str_contains($m[1], '..')) {
        $routesFile = $m[1];
    } else {
        fwrite(STDERR, "usage: targets.php --scale=N [--routes=/path/route-list.json]\n");
        exit(MeasureExit::USAGE);
    }
}
if ($scale === null) {
    fwrite(STDERR, "usage: targets.php --scale=N [--routes=/path/route-list.json]\n");
    exit(MeasureExit::USAGE);
}

$list = json_decode((string) @file_get_contents($routesFile), true);
if (!is_array($list) || !isset($list['selected']) || !is_array($list['selected'])) {
    fwrite(STDERR, "the route list cannot be read: $routesFile\n");
    exit(MeasureExit::DATA);
}
$wanted = [];
foreach ($list['selected'] as $row) {
    $wanted[] = (string) $row['route'];
}

// The logins the entry offers (PEAK_LAB_LOGINS). With none, the routes are sent as the entry has always sent them: the default login,
// or `X-Lab-Login: none` for a guest. With a list, each route is sent as the login the measurement's own inspection gave it (the login
// the capture used), by that login's name on the list; a login the list does not hold is a route left unfilled, never the default.
try {
    $offered = LoginChoice::parseOffered((string) getenv('PEAK_LAB_LOGINS'));
} catch (\InvalidArgumentException $e) {
    fwrite(STDERR, 'PEAK_LAB_LOGINS cannot be used: ' . $e->getMessage() . "\n");
    exit(MeasureExit::DATA);
}

// The Host every request is sent with (PEAK_LAB_REQUEST_HOST). Empty (the BookStack lab): no Host is named, the requests carry the web server's
// own name as they always have. The Bagisto lab names the host of APP_URL, which is also the host of every request in the capture
// (Run\Requester::BASE_URL): the app's own check that APP_URL matches the address a request arrived on (a health check run after each
// administrator's response) then reads the same thing in the lab as it did in the capture, instead of raising a notification about the lab's network name.
$requestHost = (string) getenv('PEAK_LAB_REQUEST_HOST');
if ($requestHost !== '' && preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/D', $requestHost) !== 1) {
    fwrite(STDERR, "PEAK_LAB_REQUEST_HOST cannot be used (a host name, lower case, no port)\n");
    exit(MeasureExit::DATA);
}

try {
    $profileName = (string) (getenv('PEAK_LAB_PROFILE') ?: 'bookstack');
    $profile = Profile::load($profileName);
    $config = MeasureConfig::fromEnv(getenv());
    if ($config->staticJson === null || !is_file($config->staticJson)) {
        fwrite(STDERR, "PEAK_MEASURE_STATIC_JSON must name the static analysis output\n");
        exit(MeasureExit::USAGE);
    }
    $analysis = json_decode((string) file_get_contents($config->staticJson), true);
    if (!is_array($analysis) || !isset($analysis['routes'])) {
        fwrite(STDERR, "the static analysis file has no routes\n");
        exit(MeasureExit::USAGE);
    }
    $booted = (new AppBooter())->boot($config, $scale);
} catch (BuildFailure $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(MeasureExit::DATA);
} catch (MeasureRefused $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(MeasureRefused::EXIT_CODE);
} catch (BootFailure $e) {
    fwrite(STDERR, 'cannot start the app: ' . $e->getMessage() . "\n");
    exit(BootFailure::EXIT_CODE);
}

$inspected = Inspection::tasks($booted->app, $analysis, Options::parse(['--profile=' . $profileName]), $profile);

$routes = [];
$unfilled = [];
foreach ($inspected['tasks'] as $task) {
    $spec = $task['spec'] ?? [];
    $key = ($spec['method'] ?? '') . ' ' . ($spec['uri'] ?? '');
    if (!in_array($key, $wanted, true) || isset($routes[$key])) {
        continue;
    }
    if (($task['fail_class'] ?? null) !== null || !is_string($task['uri'] ?? null)) {
        $unfilled[$key] = ['fail_class' => $task['fail_class'] ?? null, 'detail' => $task['fail_detail'] ?? null];
        continue;
    }
    $entry = ['path' => $task['uri']];
    if (($task['query'] ?? []) !== []) {
        $entry['query'] = $task['query'];
    }
    if (($task['credentials'] ?? null) !== null) {
        $entry['credentials'] = 'a token in the query (not written here)';
    }
    if (($task['act_as'] ?? null) === null) {
        // The measurement requested this route as a guest. The lab's entry logs the default user in unless told not to.
        $entry['headers'] = ['X-Lab-Login' => LoginChoice::GUEST];
        if ($offered !== []) {
            $entry['login'] = 'guest';
        }
    } elseif ($offered !== []) {
        $name = LoginChoice::nameOf((string) $task['act_as'], $offered);
        if ($name === null) {
            $unfilled[$key] = ['fail_class' => 'login_not_offered', 'detail' => 'the measurement logs this route in as ' . $task['act_as'] . ', which the entry does not offer'];
            continue;
        }
        $entry['headers'] = ['X-Lab-Login' => $name];
        $entry['login'] = $name;
    }
    $routes[$key] = $entry;
}
$missing = array_values(array_diff($wanted, array_keys($routes), array_keys($unfilled)));
$document = [
    'base_url' => 'http://lab-web:8080',
    'headers' => $requestHost === '' ? new stdClass() : ['Host' => $requestHost],
    'scale' => $scale,
    'database' => $booted->dbName,
    'routes' => $routes,
    'unfilled' => $unfilled,
    'not_in_static_analysis' => $missing,
];
$out = '/out/targets-' . $scale . '.json';
file_put_contents($out, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
printf("%d of %d routes filled; unfilled %d; not in the static analysis %d; written to %s\n", count($routes), count($wanted), count($unfilled), count($missing), $out);
exit($unfilled === [] && $missing === [] ? 0 : 1);
