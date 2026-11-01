<?php

declare(strict_types=1);

// Runs INSIDE the sealed PHP container, as a child of the driver. Makes, migrates and fills ONE measurement database.
//
//   php build-db.php --scale=N --mode=seeder|generator --recipe-json='{...}'
//
// The database is named from PEAK_MEASURE_DB_TAG and the scale (MeasureConfig::dbNameForScale); no name is passed in.
// Prints one JSON line (what was built). Exit codes: 0 done / 2 usage / 10 refused / 13 the database could not be built.

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Measure\BuildFailure;
use PeakSim\Runtime\Measure\DatabaseBuild;
use PeakSim\Runtime\Measure\MeasureExit;

require dirname(__DIR__, 2) . '/bootstrap.php';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--(scale|mode|recipe-json)=(.*)$/Ds', $arg, $m) !== 1) {
        fwrite(STDERR, "usage: build-db.php --scale=N --mode=seeder|generator --recipe-json='{...}'\n");
        exit(MeasureExit::USAGE);
    }
    $opts[$m[1]] = $m[2];
}
$recipe = isset($opts['recipe-json']) ? json_decode($opts['recipe-json'], true) : null;
if (!isset($opts['scale']) || preg_match('/^[0-9]{1,7}$/D', $opts['scale']) !== 1 || !in_array($opts['mode'] ?? '', ['seeder', 'generator'], true) || !is_array($recipe)) {
    fwrite(STDERR, "usage: build-db.php --scale=N --mode=seeder|generator --recipe-json='{...}'\n");
    exit(MeasureExit::USAGE);
}

try {
    $info = (new DatabaseBuild())->run(MeasureConfig::fromEnv(getenv()), (int) $opts['scale'], $opts['mode'], $recipe);
} catch (MeasureRefused $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(MeasureRefused::EXIT_CODE);
} catch (BuildFailure $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(MeasureExit::DATA);
}

echo json_encode($info, JSON_UNESCAPED_SLASHES), "\n";
exit(0);
