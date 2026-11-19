<?php
declare(strict_types=1);
// The app's own maintenance action, committed (no transaction around it): what the route and `artisan bookstack:regenerate-references` call.
require '/peak-sim/poc/runtime/bootstrap.php';
use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;
$config = MeasureConfig::fromEnv(getenv());
$booted = (new AppBooter())->boot($config, 300000);
$app = $booted->app;
$t0 = microtime(true);
$app->make('BookStack\\References\\ReferenceStore')->updateForAll();
fwrite(STDOUT, json_encode(['done' => true, 'seconds' => round(microtime(true) - $t0, 1), 'db' => $booted->dbName]) . "\n");
