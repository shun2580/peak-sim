<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): drops the measurement database of one scale.
//   php drop.php <scale>

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Guard\MeasureDb;

require dirname(__DIR__, 3) . '/bootstrap.php';

$config = MeasureConfig::fromEnv(getenv());
MeasureDb::connect($config)->drop($config->dbNameForScale((int) ($argv[1] ?? 0)));
echo "dropped\n";
