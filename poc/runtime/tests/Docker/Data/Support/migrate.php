<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): makes the measurement database of one scale and runs the target
// app's own migrations in it (structure only; no seeder, no data).
//
//   php migrate.php <scale>
//
// It starts the app through the CONSOLE kernel, as `artisan migrate` does, with the safe environment of
// AppBooter::environment() (database settings are real environment variables, so the app's `.env` is not used; the
// `measure` MySQL user can touch nothing but peak_sim_measure_* databases anyway). The console kernel binds a request
// before the providers boot; the HTTP kernel used by AppBooter::boot() does not, and some apps read the request
// while loading their routes.
//
// Prints one JSON line: database name, number of tables, seconds spent migrating.

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Boot\ScratchDir;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Guard\MeasureDb;

require dirname(__DIR__, 4) . '/bootstrap.php';

$scale = (int) ($argv[1] ?? 0);
$config = MeasureConfig::fromEnv(getenv());
$name = $config->dbNameForScale($scale);
$measureDb = MeasureDb::connect($config);
$measureDb->drop($name);
$measureDb->create($name);

$scratch = ScratchDir::create($config->runId);
register_shutdown_function(static fn () => $scratch->remove());
$env = AppBooter::environment($config, $name, $scratch, AppBooter::newAppKey());
unset($env['APP_RUNNING_IN_CONSOLE']);
foreach ($env as $key => $value) {
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
ini_set('display_errors', 'stderr');
chdir($config->appRoot);

$started = microtime(true);
require $config->appRoot . '/vendor/autoload.php';
$app = require $config->appRoot . '/bootstrap/app.php';
$app->useStoragePath($scratch->storagePath());
$kernel = $app->make('Illuminate\\Contracts\\Console\\Kernel');
$exit = $kernel->call('migrate', ['--force' => true]);
$seconds = round(microtime(true) - $started, 2);
if ($exit !== 0) {
    fwrite(STDERR, mb_substr((string) $kernel->output(), -1500) . "\n");
}

$tables = (int) $app->make('db')->connection()->selectOne(
    'SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ?',
    ['BASE TABLE'],
)->n;

echo json_encode(['database' => $name, 'migrate_exit' => $exit, 'tables' => $tables, 'seconds' => $seconds, 'laravel' => $app->version()], JSON_UNESCAPED_SLASHES), "\n";
exit($exit === 0 ? 0 : 1);
