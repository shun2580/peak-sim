<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): what bin/inner/build-db.php does for one database, with the profile
// read from this directory's `profiles/` (the real ones belong to the apps they describe).
//
//   php build_with_profile.php <scale> [<profile name>]
//
// Prints one JSON line (what was built). Exit codes as build-db.php: 10 refused / 13 the database could not be built.

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\DatabaseBuild;
use PeakSim\Runtime\Measure\MeasureExit;
use PeakSim\Runtime\Measure\Profile;

require dirname(__DIR__, 4) . '/bootstrap.php';

$scale = (int) ($argv[1] ?? 0);
$name = isset($argv[2]) ? (string) $argv[2] : null;
$recipe = ['mode' => 'global', 'n' => 5, 'base' => 5];

try {
    $info = (new DatabaseBuild())->run(MeasureConfig::fromEnv(getenv()), $scale, 'generator', $recipe, Profile::load($name, __DIR__ . '/profiles'));
} catch (MeasureRefused $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(MeasureRefused::EXIT_CODE);
} catch (BuildFailure $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(MeasureExit::DATA);
}

echo json_encode($info, JSON_UNESCAPED_SLASHES), "\n";
exit(0);
