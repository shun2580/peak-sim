<?php

declare(strict_types=1);

// Runs INSIDE the PHP container. Drops every measurement database (names are re-checked by MeasureDb).

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Guard\MeasureDb;

require dirname(__DIR__, 2) . '/bootstrap.php';

try {
    $db = MeasureDb::connect(MeasureConfig::fromEnv(getenv()));
    $dropped = $db->dropAll();
    $left = $db->listMeasureDatabases();
} catch (MeasureRefused $e) {
    fwrite(STDERR, 'CLEANUP REFUSED ' . $e->getMessage() . "\n");
    exit(MeasureRefused::EXIT_CODE);
}

foreach ($dropped as $name) {
    echo "dropped $name\n";
}
printf("dropped=%d remaining=%d\n", count($dropped), count($left));
exit($left === [] ? 0 : 1);
