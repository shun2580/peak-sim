<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): makes the measurement database of one scale, migrates the target
// app into it and fills it with the app's own deterministic seeder (the fixture app's seeder; B2a). The database is
// dropped by drop.php, or by bin/cleanup.
//
//   php prepare.php <scale> [customers products orders itemsPerOrder]
//
// The fixture app is the only app whose own seeder is run; for any other app the generator of the tool is used.

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Guard\MeasureDb;

require dirname(__DIR__, 3) . '/bootstrap.php';

$scale = (int) ($argv[1] ?? 0);
$sizes = array_map('intval', array_slice($argv, 2, 4)) + [20, 20, 50, 3];

$config = MeasureConfig::fromEnv(getenv());
$name = $config->dbNameForScale($scale);
$measureDb = MeasureDb::connect($config);
$measureDb->drop($name);
$measureDb->create($name);

putenv('SEED_CUSTOMERS=' . $sizes[0]);
putenv('SEED_PRODUCTS=' . $sizes[1]);
putenv('SEED_ORDERS=' . $sizes[2]);
putenv('SEED_ITEMS_PER_ORDER=' . $sizes[3]);
$_ENV['SEED_CUSTOMERS'] = (string) $sizes[0];
$_ENV['SEED_PRODUCTS'] = (string) $sizes[1];
$_ENV['SEED_ORDERS'] = (string) $sizes[2];
$_ENV['SEED_ITEMS_PER_ORDER'] = (string) $sizes[3];

$booted = (new AppBooter())->boot($config, $scale);
$console = $booted->app->make('Illuminate\\Contracts\\Console\\Kernel');
$console->call('migrate', ['--force' => true]);
$console->call('db:seed', ['--force' => true]);

echo json_encode(['database' => $name, 'sizes' => $sizes, 'report' => $booted->report], JSON_UNESCAPED_SLASHES), "\n";
