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
 *   6. (the lab's own wording, poc/lab/README.md) a request is NOT wrapped in a transaction here: the only thing it may
 *      write is the page view counter (the `views` table; for another app's lab, the tables of the list the environment names:
 *      PEAK_LAB_ALLOWED_WRITES_FILE, AllowedWrites), and only GET and HEAD are served (405 otherwise). The
 *      guard that keeps it so is PeakSim\Runtime\LabEntry\EntryGuard, called below for every request: the method
 *      and the latch before the app starts, the statement watch around the app, the verdict after it. A statement that
 *      writes anything but `views`, commits implicitly, writes on another connection or names another schema leaves a
 *      latch file: the database can no longer be trusted, and every later request is refused with 503 until the lab is
 *      rebuilt. poc/runtime (the tool's measurement) keeps its rollback; none of this is used there.
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
use PeakSim\Runtime\LabEntry\AllowedWrites;
use PeakSim\Runtime\LabEntry\EntryGuard;
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

$methodRefusal = EntryGuard::methodRefusal((string) ($_SERVER['REQUEST_METHOD'] ?? ''));
if ($methodRefusal !== null) {
    $refuse(405, $methodRefusal);
}

$scratchPath = (string) getenv('PEAK_LAB_SCRATCH');
if ($scratchPath === '') {
    $refuse(503, 'PEAK_LAB_SCRATCH is not set');
}
$latch = $scratchPath . '/contaminated.flag';
$latchReason = EntryGuard::latchRefusal($latch);
if ($latchReason !== null) {
    $refuse(503, 'the database can no longer be trusted: ' . $latchReason);
}

try {
    $env = [];
    foreach (getenv() as $key => $value) {
        $env[(string) $key] = (string) $value;
    }
    // The tables that may be written. A list that is named and cannot be read is a refusal: the entry never falls back to a wider rule.
    try {
        $allowedTables = AllowedWrites::fromEnv($env);
    } catch (\InvalidArgumentException $e) {
        $refuse(503, 'the list of tables the lab may write cannot be used', $e->getMessage());
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
$guard = new EntryGuard($latch, $booted->dbName, (string) $app['config']->get('database.default'), (string) ($_SERVER['REQUEST_URI'] ?? ''), $allowedTables);
$guard->watch($db, $app['events']);

$request = \Illuminate\Http\Request::capture();
$counter->start();
$response = $booted->kernel->handle($request);
$response->send();
$booted->kernel->terminate($request, $response);
$counter->stop();

// The response has gone out already; what is left is to stop anything more from being measured on this database.
$guard->finish($db, $counter);
