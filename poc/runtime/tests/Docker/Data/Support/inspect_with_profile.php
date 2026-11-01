<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): the inspection of bin/inner/inspect.php, with the profile read
// from this directory's `profiles/`. The database of the scale must already be built (build_with_profile.php).
//
//   php inspect_with_profile.php <scale> [<profile name>]    (PEAK_MEASURE_STATIC_JSON names the static analysis)
//
// Prints one JSON line: what Inspection::tasks returns.

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Measure\Inspection;
use PeakSim\Runtime\Measure\Options;
use PeakSim\Runtime\Measure\Profile;

require dirname(__DIR__, 4) . '/bootstrap.php';

$scale = (int) ($argv[1] ?? 0);
$name = isset($argv[2]) ? (string) $argv[2] : null;
$config = MeasureConfig::fromEnv(getenv());
$analysis = json_decode((string) file_get_contents((string) $config->staticJson), true, 512, JSON_THROW_ON_ERROR);
$booted = (new AppBooter())->boot($config, $scale);

echo json_encode(Inspection::tasks($booted->app, $analysis, Options::parse([]), Profile::load($name, __DIR__ . '/profiles')), JSON_UNESCAPED_SLASHES), "\n";
