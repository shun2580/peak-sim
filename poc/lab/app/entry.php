<?php

declare(strict_types=1);

/*
 * The one entry script of the lab's php-fpm (nginx always names this file; the app's own public/index.php is never run).
 *
 * It carries the safety conditions of poc/runtime/README.md over to php-fpm:
 *   2. only the measurement database: AppBooter fixes every connection of the app to it and proves it, on every request;
 *      a statement that names another schema, or a write on another connection, is a refusal below.
 *   4. the app is not modified: it is mounted read-only; everything it writes goes to the shared scratch directory (a tmpfs).
 *   5. side effects are fakes: Fakes::install (mail, notifications, queue, outbound HTTP, storage) as in a measurement.
 *   6. a request is rolled back: it runs inside a transaction that is always rolled back. A request that could not be
 *      rolled back (a statement that commits implicitly, a write on another connection, the transaction ended by the
 *      request) leaves a latch file: the database can no longer be trusted, and every later request is refused with 503
 *      until the lab is rebuilt. Only GET and HEAD are served (405 otherwise): the load is GET only.
 *
 * What is added over a measurement, and nothing more: the scratch directory is fixed, so the compiled views are kept
 * between requests as they are in production (ScratchDir::fixed).
 */

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Boot\AppSupport;
use PeakSim\Runtime\Boot\BootFailure;
use PeakSim\Runtime\Boot\ScratchDir;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Measure\Profile;
use PeakSim\Runtime\Run\ActingAs;
use PeakSim\Runtime\Run\QueryCounter;

require dirname(__DIR__, 2) . '/runtime/bootstrap.php';

$refuse = static function (int $status, string $why, string $detail = ''): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Lab-Refused: ' . $why);
    echo 'lab: refused: ' . $why . "\n";
    error_log(json_encode(['lab' => 'refused', 'status' => $status, 'why' => $why, 'detail' => mb_substr($detail, 0, 300)], JSON_UNESCAPED_SLASHES));
    exit;
};

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method !== 'GET' && $method !== 'HEAD') {
    $refuse(405, 'only GET and HEAD are served');
}

$scratchPath = (string) getenv('PEAK_LAB_SCRATCH');
if ($scratchPath === '') {
    $refuse(503, 'PEAK_LAB_SCRATCH is not set');
}
$latch = $scratchPath . '/contaminated.flag';
if (is_file($latch)) {
    $refuse(503, 'the database can no longer be trusted: ' . trim((string) @file_get_contents($latch)));
}

try {
    $env = [];
    foreach (getenv() as $key => $value) {
        $env[(string) $key] = (string) $value;
    }
    $config = MeasureConfig::fromEnv($env);
    $scale = (int) ($env['PEAK_LAB_SCALE'] ?? '0');
    $booted = (new AppBooter())->boot($config, $scale, ScratchDir::fixed($scratchPath));
    $app = $booted->app;
    AppSupport::apply($app);
    $profile = $env['PEAK_LAB_PROFILE'] ?? '';
    if ($profile !== '') {
        Profile::onBoot(Profile::load($profile), $app);
    }
    // A route the measurement requested as a guest is sent with `X-Lab-Login: none` (poc/lab/bin/targets says which).
    $actAs = ($_SERVER['HTTP_X_LAB_LOGIN'] ?? '') === 'none' ? '' : ($env['PEAK_LAB_ACT_AS'] ?? '');
    if ($actAs !== '') {
        ActingAs::apply($app, $actAs);
    }
    if (($env['PEAK_LAB_GATE_BYPASS'] ?? '0') === '1') {
        $app->make('Illuminate\\Contracts\\Auth\\Access\\Gate')->before(static fn (mixed $user = null, mixed $ability = null): bool => true);
    }
} catch (MeasureRefused $e) {
    $refuse(503, 'the safety check refused: ' . $e->getMessage());
} catch (BootFailure $e) {
    $refuse(503, 'the app did not start', $e->getMessage());
} catch (\Throwable $e) {
    $refuse(503, 'the app did not start (' . get_class($e) . ')', $e->getMessage());
}

$db = $app['db'];
$counter = new QueryCounter($booted->dbName, (string) $app['config']->get('database.default'));
$counter->listenTo($app['events']);

$request = \Illuminate\Http\Request::capture();
$db->beginTransaction();
$counter->start();
$response = $booted->kernel->handle($request);
$response->send(); // inside the transaction: a streamed body may still run statements
$booted->kernel->terminate($request, $response);
$counter->stop();

$level = $db->transactionLevel();
for ($i = 0; $i < 50 && $db->transactionLevel() > 0; $i++) {
    $db->rollBack();
}

$problem = null;
if ($counter->foreignSchemas() !== []) {
    $problem = 'a statement referred to a schema other than the measurement database: ' . implode(', ', $counter->foreignSchemas());
} elseif (($rollbackProblem = $counter->rollbackProblem()) !== null) {
    $problem = $rollbackProblem;
} elseif ($level < 1) {
    $problem = 'transaction_control: the transaction was ended by the request';
}
if ($problem !== null) {
    // The response has gone out already; what is left is to stop anything more from being measured on this database.
    @file_put_contents($latch, mb_substr(($_SERVER['REQUEST_URI'] ?? '') . ' ' . $problem, 0, 500) . "\n");
    error_log(json_encode(['lab' => 'contaminated', 'uri' => $_SERVER['REQUEST_URI'] ?? '', 'problem' => mb_substr($problem, 0, 300)], JSON_UNESCAPED_SLASHES));
}
