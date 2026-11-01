<?php

declare(strict_types=1);

// Runs INSIDE the PHP container. Prints one line per probe; exit 0 = sealed, 11 = it can leave or the DB is unreachable.

use PeakSim\Runtime\Config\MeasureConfig;
use PeakSim\Runtime\Config\MeasureRefused;
use PeakSim\Runtime\Guard\DbGuard;
use PeakSim\Runtime\Guard\EgressProbe;
use PeakSim\Runtime\Guard\MeasureDb;

require dirname(__DIR__, 2) . '/bootstrap.php';

try {
    $config = MeasureConfig::fromEnv(getenv());
    DbGuard::assertResolvesPrivate($config->dbHost, static fn (string $h): string => gethostbyname($h));
} catch (MeasureRefused $e) {
    fwrite(STDERR, 'PREFLIGHT REFUSED ' . $e->getMessage() . "\n");
    exit(MeasureRefused::EXIT_CODE);
}

$results = EgressProbe::native()->run($config->dbHost, $config->dbPort);

// Positive control that needs credentials: the measure user can log in.
try {
    MeasureDb::connect($config)->listMeasureDatabases();
    $results[] = ['name' => 'login:measure', 'kind' => 'positive', 'ok' => true, 'detail' => 'logged in as the measurement user'];
} catch (Throwable $e) {
    $results[] = ['name' => 'login:measure', 'kind' => 'positive', 'ok' => false, 'detail' => get_class($e)];
}

foreach ($results as $r) {
    printf("%s %-8s %-28s %s\n", $r['ok'] ? 'PASS' : 'FAIL', $r['kind'], $r['name'], $r['detail']);
}

if (!EgressProbe::allOk($results)) {
    fwrite(STDERR, "PREFLIGHT FAIL: the container can leave the network or cannot reach the DB service\n");
    exit(EgressProbe::EXIT_CODE);
}
echo "PREFLIGHT OK\n";
