<?php

declare(strict_types=1);

// Runs INSIDE the sealed PHP container, as a child of the driver. Starts the app on the inspection database (already
// built), reads its route table, and turns every route of the static analysis into a task: filled URI, login, body.
//
//   php inspect.php --scale=N [--only=a,b] [--profile=<name>]
//
// With a profile, its `arguments`, `unfillable` and logins are used to prepare the routes (Measure\Inspection).
// Prints one JSON object {"tasks": [...], "skipped": n, ...}. Exit codes: 0 / 2 usage / 10 refused / 12 the app did not
// start / 13 the profile cannot be used.

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Boot\BootFailure;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\Inspection;
use PeakSim\Runtime\Measure\MeasureExit;
use PeakSim\Runtime\Measure\Options;
use PeakSim\Runtime\Measure\Profile;

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
    fwrite(STDERR, "usage: inspect.php --scale=N [--only=a,b] [--profile=<name>]\n");
    exit(MeasureExit::USAGE);
}

try {
    $profile = Profile::load($options->profile);
} catch (BuildFailure $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(MeasureExit::DATA);
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

$inspected = Inspection::tasks($booted->app, $analysis, $options, $profile);

echo json_encode($inspected + ['laravel' => $booted->app->version(), 'php' => PHP_VERSION, 'profile' => $options->profile], JSON_UNESCAPED_SLASHES), "\n";
exit(0);
