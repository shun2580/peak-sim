<?php
// Scratch tool (not part of the repository): the lab entry's own steps, one request, with every statement logged as it is sent,
// including those of the boot, which the entry's own watch comes too late to see. Usage: replica.php <uri> <login|none> <out file>
declare(strict_types=1);
use PeakSim\Runtime\Boot\AppBooter; use PeakSim\Runtime\Boot\AppSupport; use PeakSim\Runtime\Boot\ExternalCallLedger; use PeakSim\Runtime\Boot\Fakes;
use PeakSim\Runtime\Boot\ScratchDir; use PeakSim\Runtime\Config\MeasureConfig; use PeakSim\Runtime\LabEntry\EntryGuard; use PeakSim\Runtime\LabEntry\LoginChoice;
use PeakSim\Runtime\Measure\Profile; use PeakSim\Runtime\Run\ActingAs; use PeakSim\Runtime\Run\QueryCounter;
require '/peak-sim/poc/runtime/bootstrap.php';
[$self, $uri, $loginName, $outFile] = $argv;
$env = []; foreach (getenv() as $k => $v) { $env[(string) $k] = (string) $v; }
$scale = (int) $env['PEAK_LAB_SCALE'];
$config = MeasureConfig::fromEnv($env); $dbName = $config->dbNameForScale($scale);
$scratch = ScratchDir::fixed('/tmp/peak-measure-replica');
$booter = new AppBooter();
$call = static function (string $m, ...$a) { $r = new ReflectionMethod(AppBooter::class, $m); $r->setAccessible(true); return $r->invoke(null, ...$a); };
$call('export', AppBooter::environment($config, $dbName, $scratch, AppBooter::appKeyFrom(getenv())));
ini_set('display_errors', 'stderr'); chdir($config->appRoot);
require $config->appRoot . '/vendor/autoload.php';
$app = require $config->appRoot . '/bootstrap/app.php';
$app->useStoragePath($scratch->storagePath());
$kernel = $app->make('Illuminate\\Contracts\\Http\\Kernel');
$phase = 'boot'; $log = fopen($outFile, 'w');
$app['events']->listen('Illuminate\\Database\\Events\\QueryExecuted', static function ($e) use (&$phase, $log) {
    fwrite($log, json_encode(['phase' => $phase, 'conn' => spl_object_id($e->connection), 'sql' => $e->sql, 'bindings' => count($e->bindings), 'bv' => $e->bindings, 'ms' => round($e->time, 2)]) . "\n");
});
$app->instance('request', Illuminate\Http\Request::create('http://measure.invalid/'));
Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
$kernel->bootstrap();
$phase = 'lockdown';
$call('lockDownConnections', $app, $config, $dbName);
Fakes::install($app, new ExternalCallLedger(), $scratch);
AppSupport::apply($app);
Profile::onBoot(Profile::load('bagisto'), $app);
$guard = new EntryGuard('/tmp/peak-measure-replica/flag', $dbName, (string) $app['config']->get('database.default'), $uri, []);
$guard->watch($app['db'], $app['events']);
$phase = 'login';
if ($loginName !== 'none') { ActingAs::apply($app, LoginChoice::resolve($loginName, (string) ($env['PEAK_LAB_ACT_AS'] ?? ''), LoginChoice::parseOffered((string) $env['PEAK_LAB_LOGINS']))); }
$phase = 'handle';
$request = Illuminate\Http\Request::create('http://measure.invalid' . $uri, 'GET');
$response = $kernel->handle($request);
$phase = 'terminate';
$kernel->terminate($request, $response);
fwrite($log, json_encode(['phase' => 'end', 'status' => $response->getStatusCode()]) . "\n");
@unlink('/tmp/peak-measure-replica/flag');
echo "status ", $response->getStatusCode(), "\n";
