<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): starts the target app the way measure-one does, then runs one
// `on_boot` function of a profile through the same path the run takes (`Profile::onBoot`, which puts it under
// `Boot\OnBootWatch`) on a real Laravel with the real fakes in place. Prints one JSON object: whether the function was
// let through or stopped, and the database the app is on afterwards.
//   php on_boot.php <scale> <case>

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Boot\OnBootViolation;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Measure\Profile;

require dirname(__DIR__, 3) . '/bootstrap.php';

$hooks = [
    // What an on_boot is for: put a setting in place.
    'config_only' => static function (object $app): void {
        $app['config']->set('probe.value', 'set by on_boot');
    },
    // It touches the database.
    'query' => static function (object $app): void {
        DB::select('select 1');
    },
    'transaction' => static function (object $app): void {
        DB::beginTransaction();
    },
    // It takes a fake off: the real mailer, the real queue, an HTTP client that lets a stray request through.
    'mail_swap' => static function (object $app): void {
        Mail::swap(new MailManager($app));
    },
    'queue_swap' => static function (object $app): void {
        Queue::swap(new QueueManager($app));
    },
    'http_swap' => static function (object $app): void {
        Http::swap(new HttpFactory());
    },
];

$case = (string) ($argv[2] ?? '');
if (!isset($hooks[$case])) {
    fwrite(STDERR, 'unknown case ' . $case . "\n");
    exit(2);
}

$config = MeasureConfig::fromEnv(getenv());
$booted = (new AppBooter())->boot($config, (int) ($argv[1] ?? 0));
$before = \PeakSim\Runtime\Boot\Fakes::fingerprint($booted->app);

$out = ['case' => $case];
try {
    Profile::onBoot(['on_boot' => $hooks[$case]], $booted->app);
    $out['outcome'] = 'passed';
} catch (OnBootViolation $e) {
    $out['outcome'] = 'stopped';
    $out['message'] = $e->getMessage();
}
$out['fingerprint_keys'] = array_keys($before);
$out['config_value'] = $booted->app['config']->get('probe.value');
$out['database'] = $booted->app['config']->get('database.connections.' . $booted->app['config']->get('database.default') . '.database');

echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
