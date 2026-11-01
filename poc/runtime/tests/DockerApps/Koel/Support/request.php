<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): starts the app on the measurement database of one scale, logs in as
// one user, sends one GET through the HTTP kernel inside a transaction that is rolled back, and prints one JSON line:
// status, number of items under "data" (null when the body has none), bytes.
//
//   php request.php <scale> <user key | -> <uri>

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Run\ActingAs;
use PeakSim\Runtime\Run\Requester;

require dirname(__DIR__, 4) . '/bootstrap.php';

$scale = (int) ($argv[1] ?? 0);
$userKey = (string) ($argv[2] ?? '-');
$uri = (string) ($argv[3] ?? '/');

$booted = (new AppBooter())->boot(MeasureConfig::fromEnv(getenv()), $scale);
$app = $booted->app;
if ($userKey !== '-') {
    ActingAs::apply($app, 'App\\Models\\User:' . $userKey);
}
$request = 'Illuminate\\Http\\Request';
$request = $request::create(Requester::BASE_URL . '/' . ltrim($uri, '/'), 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
$app->instance('request', $request);

$app['db']->beginTransaction();
try {
    $response = $booted->kernel->handle($request);
} finally {
    $app['db']->rollBack();
}
$body = (string) $response->getContent();
$json = json_decode($body, true);

echo json_encode([
    'status' => $response->getStatusCode(),
    'items' => is_array($json) && isset($json['data']) && is_array($json['data']) ? count($json['data']) : null,
    'bytes' => strlen($body),
], JSON_UNESCAPED_SLASHES), "\n";
