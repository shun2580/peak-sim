<?php

declare(strict_types=1);

// Runs INSIDE the sealed PHP container, as a child of the driver. Starts the app on the inspection database (already
// built), reads its route table, and turns every route of the static analysis into a task: filled URI, login, body.
//
//   php inspect.php --scale=N [--only=a,b]
//
// Prints one JSON object {"tasks": [...], "skipped": n}. Exit codes: 0 / 2 usage / 10 refused / 12 the app did not start.

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Boot\BootFailure;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Measure\EloquentRowLookup;
use PeakSim\Runtime\Measure\LaravelAppFacts;
use PeakSim\Runtime\Measure\MeasureExit;
use PeakSim\Runtime\Measure\Options;
use PeakSim\Runtime\Measure\RouteTable;
use PeakSim\Runtime\Measure\TaskBuilder;
use PeakSim\Runtime\Route\ArgumentResolver;
use PeakSim\Runtime\Route\BodyBuilder;
use PeakSim\Runtime\Route\RouteCatalog;

require dirname(__DIR__, 2) . '/bootstrap.php';

$scale = null;
$rest = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--scale=([0-9]{1,7})$/D', $arg, $m) === 1) {
        $scale = (int) $m[1];
    } else {
        $rest[] = $arg;
    }
}
try {
    $options = Options::parse($rest);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(MeasureExit::USAGE);
}
if ($scale === null) {
    fwrite(STDERR, "usage: inspect.php --scale=N [--only=a,b]\n");
    exit(MeasureExit::USAGE);
}

try {
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
} catch (MeasureRefused $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(MeasureRefused::EXIT_CODE);
} catch (BootFailure $e) {
    fwrite(STDERR, 'cannot start the app: ' . $e->getMessage() . "\n");
    exit(BootFailure::EXIT_CODE);
}

$app = $booted->app;
$catalog = RouteCatalog::build($analysis, RouteTable::rows($app));
$lookup = new EloquentRowLookup($app);
$builder = new TaskBuilder(new ArgumentResolver($lookup), new BodyBuilder($lookup), new LaravelAppFacts($app));

$tasks = [];
foreach ($catalog->specs() as $spec) {
    if ($options->wants($spec->uri)) {
        $tasks[] = $builder->build($spec)->toArray();
    }
}

echo json_encode(['tasks' => $tasks, 'skipped' => $catalog->skippedCount(), 'laravel' => $app->version(), 'php' => PHP_VERSION], JSON_UNESCAPED_SLASHES), "\n";
exit(0);
