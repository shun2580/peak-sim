<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): which row LaravelAppFacts::loginUser picks. The verification app has
// no user model, so the guard's provider is pointed at its customers for this check.
//
//   php login_user.php <scale> [<id>]
//
// Prints one JSON line: {"user": "Model:key" or null}.

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Measure\LaravelAppFacts;

require dirname(__DIR__, 4) . '/bootstrap.php';

$scale = (int) ($argv[1] ?? 0);
$id = isset($argv[2]) ? (int) $argv[2] : null;
$booted = (new AppBooter())->boot(MeasureConfig::fromEnv(getenv()), $scale);
$booted->app['config']->set('auth.providers.users.model', 'App\\Models\\Customer');

echo json_encode(['user' => (new LaravelAppFacts($booted->app))->loginUser(null, $id)], JSON_UNESCAPED_SLASHES), "\n";
