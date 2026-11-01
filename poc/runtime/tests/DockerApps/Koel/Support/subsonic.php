<?php

declare(strict_types=1);

// Test support (runs INSIDE the sealed container): starts the app on the measurement database of one scale with the
// run's APP_KEY (PEAK_MEASURE_APP_KEY, set by the caller), reads the API key of the two logins through the app's own
// model (so its `encrypted` cast must decrypt what the profile stored), and sends /rest/ping three ways as the first
// login: by API key, by token and salt, and by a wrong API key. Prints one JSON line. It never prints a key.
//
//   php subsonic.php <scale> <user id>

use PeakSim\Runtime\Boot\AppBooter;
use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Run\Requester;

require dirname(__DIR__, 4) . '/bootstrap.php';

$scale = (int) ($argv[1] ?? 0);
$userId = (int) ($argv[2] ?? 0);

$booted = (new AppBooter())->boot(MeasureConfig::fromEnv(getenv()), $scale);
$app = $booted->app;
$kernel = $booted->kernel;

$userClass = 'App\\Models\\User';
$user = $userClass::query()->find($userId);
$apiKey = $user === null ? '' : (string) $user->subsonic_api_key;
$email = $user === null ? '' : (string) $user->email;

$ping = static function (array $query) use ($app, $kernel): array {
    $request = 'Illuminate\\Http\\Request';
    $request = $request::create(Requester::BASE_URL . '/rest/ping', 'GET', $query + ['f' => 'json', 'v' => '1.16.1', 'c' => 'measure'], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $app->instance('request', $request);
    $app['db']->beginTransaction();
    try {
        $response = $kernel->handle($request);
    } finally {
        $app['db']->rollBack();
    }
    $json = json_decode((string) $response->getContent(), true);

    return ['http' => $response->getStatusCode(), 'status' => is_array($json) ? ($json['subsonic-response']['status'] ?? null) : null];
};

$salt = 'measure-salt';
echo json_encode([
    'model_reads_key' => $apiKey !== '',
    'by_api_key' => $ping(['u' => $email, 'apiKey' => $apiKey]),
    'by_token' => $ping(['u' => $email, 's' => $salt, 't' => md5($apiKey . $salt)]),
    'by_wrong_api_key' => $ping(['u' => $email, 'apiKey' => 'not-the-key']),
    'without_credentials' => $ping([]),
], JSON_UNESCAPED_SLASHES), "\n";
